#include "macros.h"

#include <curl/curl.h>
#include <pthread.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>
#include <sys/types.h>

#include "compatibility.h"
#include "coms.h"
#include "coms_curl.h"
#include "configuration.h"
#include "env_config.h"
#include "vendor_stdatomic.h"

#define HOST_FORMAT_STR "http://%s:%u/v0.4/traces"

struct _writer_loop_data_t {
    CURL *curl;
    pthread_t thread;
    ddtrace_coms_stack_t *tmp_stack;
    pthread_mutex_t interval_flush_mutex, finished_flush_mutex, stack_rotation_mutex;
    pthread_cond_t interval_flush_condition, finished_flush_condition;

    _Atomic(BOOL_T) running;
    _Atomic(pid_t) current_pid;
    _Atomic(BOOL_T) shutdown_when_idle, suspended, sending, allocate_new_stacks;
    _Atomic(uint32_t) flush_interval, request_counter, flush_processed_stacks_total, writer_cycle,
        requests_since_last_flush;
};

static struct _writer_loop_data_t global_writer = {.interval_flush_mutex = PTHREAD_MUTEX_INITIALIZER,
                                                   .finished_flush_mutex = PTHREAD_MUTEX_INITIALIZER,
                                                   .stack_rotation_mutex = PTHREAD_MUTEX_INITIALIZER,
                                                   .finished_flush_condition = PTHREAD_COND_INITIALIZER,
                                                   .interval_flush_condition = PTHREAD_COND_INITIALIZER,
                                                   .running = ATOMIC_VAR_INIT(0),
                                                   .current_pid = ATOMIC_VAR_INIT(0),
                                                   .shutdown_when_idle = ATOMIC_VAR_INIT(0),
                                                   .suspended = ATOMIC_VAR_INIT(0),
                                                   .allocate_new_stacks = ATOMIC_VAR_INIT(0),
                                                   .sending = ATOMIC_VAR_INIT(0)};

inline static struct _writer_loop_data_t *get_writer() { return &global_writer; }


inline static void curl_set_timeout(CURL *curl) {
    curl_easy_setopt(curl, CURLOPT_TIMEOUT_MS, get_dd_trace_agent_timeout());
}

inline static void curl_set_connect_timeout(CURL *curl) {
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT_MS, get_dd_trace_agent_connect_timeout());
}

inline static void curl_set_hostname(CURL *curl) {
    char *hostname = get_dd_agent_host();
    int64_t port = get_dd_trace_agent_port();
    if (port <= 0 || port > 65535) {
        port = 8126;
    }

    if (hostname) {
        size_t agent_url_len =
            strlen(hostname) + sizeof(HOST_FORMAT_STR) + 10;  // port digit allocation + some headroom
        char *agent_url = malloc(agent_url_len);
        snprintf(agent_url, agent_url_len, HOST_FORMAT_STR, hostname, (uint32_t)port);

        curl_easy_setopt(curl, CURLOPT_URL, agent_url);
        free(hostname);
        free(agent_url);
    } else {
        curl_easy_setopt(curl, CURLOPT_URL, "http://localhost:8126/v0.4/traces");
    }
}

inline static struct timespec deadline_in_ms(uint32_t ms) {
    struct timespec deadline;
    struct timeval now;

    gettimeofday(&now, NULL);
    uint32_t sec = ms / 1000UL;
    uint32_t msec = ms % 1000UL;
    deadline.tv_sec = now.tv_sec + sec;
    deadline.tv_nsec = ((now.tv_usec + 1000UL * msec) * 1000UL);

    // carry over full seconds from nsec
    deadline.tv_sec += deadline.tv_nsec / (1000 * 1000 * 1000);
    deadline.tv_nsec %= (1000 * 1000 * 1000);

    return deadline;
}

static size_t dummy_write_callback(char *ptr, size_t size, size_t nmemb, void *userdata) {
    UNUSED(userdata);
    size_t data_length = size * nmemb;
    if (get_dd_trace_debug_curl_output()) {
        printf("%s", ptr);
    }
    return data_length;
}

inline static void curl_send_stack(struct _writer_loop_data_t *writer, ddtrace_coms_stack_t *stack) {
    if (!writer->curl) {
        writer->curl = curl_easy_init();

        struct curl_slist *headers = NULL;
        headers = curl_slist_append(headers, "Transfer-Encoding: chunked");
        headers = curl_slist_append(headers, "Content-Type: application/msgpack");
        curl_easy_setopt(writer->curl, CURLOPT_HTTPHEADER, headers);

        curl_easy_setopt(writer->curl, CURLOPT_READFUNCTION, ddtrace_coms_read_callback);
        curl_easy_setopt(writer->curl, CURLOPT_WRITEFUNCTION, dummy_write_callback);
    }

    CURL *curl = writer->curl;
    if (curl) {
        CURLcode res;

        void *read_data = ddtrace_init_read_userdata(stack);

        curl_easy_setopt(curl, CURLOPT_READDATA, read_data);
        curl_set_hostname(writer->curl);
        curl_set_timeout(writer->curl);
        curl_set_connect_timeout(writer->curl);

        curl_easy_setopt(writer->curl, CURLOPT_UPLOAD, 1);
        curl_easy_setopt(writer->curl, CURLOPT_INFILESIZE, 10);
        curl_easy_setopt(writer->curl, CURLOPT_VERBOSE, get_dd_trace_agent_debug_verbose_curl());

        res = curl_easy_perform(curl);

        if (res != CURLE_OK) {
            if (get_dd_trace_debug_curl_output()) {
                printf("curl_easy_perform() failed: %s\n", curl_easy_strerror(res));
                fflush(stdout);
            }
        } else {
            if (get_dd_trace_debug_curl_output()) {
                double uploaded;
                curl_easy_getinfo(curl, CURLINFO_SIZE_UPLOAD, &uploaded);
                printf("UPLOADED %.0f bytes\n", uploaded);
                fflush(stdout);
            }
        }

        ddtrace_deinit_read_userdata(read_data);
    }
}

static inline void signal_data_processed(struct _writer_loop_data_t *writer) {
    pthread_mutex_lock(&writer->finished_flush_mutex);
    pthread_cond_signal(&writer->finished_flush_condition);
    pthread_mutex_unlock(&writer->finished_flush_mutex);
}

static inline void reinit_mutex(pthread_mutex_t *mutex) {
    pthread_mutex_t new_mutex = PTHREAD_MUTEX_INITIALIZER;
    *mutex=new_mutex;
    pthread_mutex_init(mutex, NULL);
}

static inline void reset_thread_variable(pthread_t *thread) {
    memset(thread, 0, sizeof(pthread_t));
}

static void *writer_loop(void *_) {
    UNUSED(_);
    struct _writer_loop_data_t *writer = get_writer();

    BOOL_T running = TRUE;
    atomic_store(&writer->running, TRUE);
    do {
        atomic_fetch_add(&writer->writer_cycle, 1);
        uint32_t interval = atomic_load(&writer->flush_interval);
        if (interval > 0) {
            struct timespec wait_deadline = deadline_in_ms(interval);
            pthread_mutex_lock(&writer->interval_flush_mutex);
            pthread_cond_timedwait(&writer->interval_flush_condition, &writer->interval_flush_mutex, &wait_deadline);
            pthread_mutex_unlock(&writer->interval_flush_mutex);
        }

        if (atomic_load(&writer->suspended)) {
            continue;
        }
        atomic_store(&writer->requests_since_last_flush, 0);

        ddtrace_coms_stack_t **stack = &writer->tmp_stack;
        ddtrace_coms_threadsafe_rotate_stack(atomic_load(&writer->allocate_new_stacks));

        uint32_t processed_stacks = 0;
        if (!*stack) {
            *stack = ddtrace_coms_attempt_acquire_stack();
        }
        while (*stack) {
            processed_stacks++;
            if (atomic_load(&writer->sending)) {
                curl_send_stack(writer, *stack);
            }

            ddtrace_coms_stack_t *to_free = *stack;

            *stack = ddtrace_coms_attempt_acquire_stack();
            ddtrace_coms_free_stack(to_free);
        }

        if (processed_stacks > 0) {
            atomic_fetch_add(&writer->flush_processed_stacks_total, processed_stacks);
        } else if (atomic_load(&writer->shutdown_when_idle)) {
            running = FALSE;
        }

        signal_data_processed(writer);
    } while (running);

    atomic_store(&writer->running, FALSE);
    pthread_exit(NULL);
    return NULL;
}

BOOL_T ddtrace_coms_set_writer_send_on_flush(BOOL_T send) {
    struct _writer_loop_data_t *writer = get_writer();
    BOOL_T previous_value = atomic_load(&writer->sending);
    atomic_store(&writer->sending, send);

    return previous_value;
}

static inline void writer_set_shutdown_state(struct _writer_loop_data_t *writer) {
    // spin the writer without waiting to speedup processing time
    atomic_store(&writer->flush_interval, 0);
    // stop allocating new stacks on flush
    atomic_store(&writer->allocate_new_stacks, FALSE);
    // make the writer exit once it finishes the processing
    atomic_store(&writer->shutdown_when_idle, TRUE);
}

static inline void writer_set_operational_state(struct _writer_loop_data_t *writer) {
    atomic_store(&writer->sending, TRUE);
    atomic_store(&writer->flush_interval, get_dd_trace_agent_flush_interval());
    atomic_store(&writer->allocate_new_stacks, TRUE);
    atomic_store(&writer->shutdown_when_idle, FALSE);
}

BOOL_T ddtrace_coms_init_and_start_writer() {
    struct _writer_loop_data_t *writer = get_writer();
    writer_set_operational_state(writer);

    if (pthread_create(&writer->thread, NULL, &writer_loop, NULL) == 0) {
        return TRUE;
    } else {
        return FALSE;
    }
}

BOOL_T ddtrace_coms_on_pid_change(){
    struct _writer_loop_data_t *writer = get_writer();

    pid_t current_pid = getpid();
    pid_t previous_pid = atomic_load(&writer->current_pid);
    if (current_pid == previous_pid){
        return TRUE;
    }

    // ensure this reinitialization is done only once on pid change
    if (atomic_compare_exchange_strong(&writer->current_pid, &previous_pid, current_pid)){
        reinit_mutex(&writer->finished_flush_mutex);
        reinit_mutex(&writer->interval_flush_mutex);
        reinit_mutex(&writer->stack_rotation_mutex);
        reset_thread_variable(&writer->thread);

        ddtrace_coms_init_and_start_writer();
        return TRUE;
    }

    return FALSE;
}

BOOL_T ddtrace_coms_threadsafe_rotate_stack(BOOL_T attempt_allocate_new) {
    struct _writer_loop_data_t *writer = get_writer();

    pthread_mutex_lock(&writer->stack_rotation_mutex);
    BOOL_T rv = ddtrace_coms_rotate_stack(attempt_allocate_new);
    pthread_mutex_unlock(&writer->stack_rotation_mutex);
    return rv;
}

BOOL_T ddtrace_coms_trigger_writer_flush() {
    struct _writer_loop_data_t *writer = get_writer();

    pthread_mutex_lock(&writer->interval_flush_mutex);
    pthread_cond_signal(&writer->interval_flush_condition);
    pthread_mutex_unlock(&writer->interval_flush_mutex);

    return TRUE;
}

BOOL_T ddtrace_coms_on_request_finished() {
    struct _writer_loop_data_t *writer = get_writer();

    atomic_fetch_add(&writer->request_counter, 1);
    uint32_t requests_since_last_flush = atomic_fetch_add(&writer->requests_since_last_flush, 1);

    // simple heuristic to flush every n request to improve memory used
    if (requests_since_last_flush > get_dd_trace_agent_flush_after_n_requests()) {
        ddtrace_coms_trigger_writer_flush();
    }

    return TRUE;
}

BOOL_T ddtrace_coms_flush_shutdown_writer_synchronous() {
    struct _writer_loop_data_t *writer = get_writer();
    writer_set_shutdown_state(writer);
    // wakeup the writer
    ddtrace_coms_trigger_writer_flush();

    if (atomic_load(&writer->running)) {
        ddtrace_coms_synchronous_flush();
    }
    return TRUE;
}

BOOL_T ddtrace_coms_synchronous_flush() {
    struct _writer_loop_data_t *writer = get_writer();
    uint32_t previous_writer_cycle = atomic_load(&writer->writer_cycle);
    uint32_t previous_processed_stacks_total = atomic_load(&writer->flush_processed_stacks_total);
    // immediately flush until
    atomic_store(&writer->flush_interval, 0);

    ddtrace_coms_trigger_writer_flush();

    while (previous_writer_cycle == atomic_load(&writer->writer_cycle)) {
        if (!atomic_load(&writer->running)) {
            // writer stopped there is  no way the counter will be increaseed
            return FALSE;
        }
        pthread_mutex_lock(&writer->finished_flush_mutex);
        struct timespec deadline = deadline_in_ms(100);
        pthread_cond_timedwait(&writer->finished_flush_condition, &writer->finished_flush_mutex, &deadline);
        pthread_mutex_unlock(&writer->finished_flush_mutex);
    }

    // reset the flush interval
    atomic_store(&writer->flush_interval, get_dd_trace_agent_flush_interval());

    uint32_t processed_stacks_total =
        atomic_load(&writer->flush_processed_stacks_total) - previous_processed_stacks_total;

    return processed_stacks_total > 0;
}

BOOL_T ddtrace_in_writer_thread() {
    struct _writer_loop_data_t *writer = get_writer();
    return (pthread_self() == writer->thread);
}
