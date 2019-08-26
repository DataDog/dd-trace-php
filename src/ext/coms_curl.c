#include "coms_curl.h"

#include <curl/curl.h>
#include <pthread.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/time.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#include "compatibility.h"
#include "coms.h"
#include "configuration.h"
#include "macros.h"
#include "vendor_stdatomic.h"

#define HOST_FORMAT_STR "http://%s:%u/v0.4/traces"

struct _writer_thread_variables_t {
    pthread_t self;
    pthread_mutex_t interval_flush_mutex, finished_flush_mutex, stack_rotation_mutex;
    pthread_mutex_t writer_shutdown_signal_mutex;
    pthread_cond_t writer_shutdown_signal_condition;
    pthread_cond_t interval_flush_condition, finished_flush_condition;
};

struct _writer_loop_data_t {
    CURL *curl;
    struct curl_slist *headers;
    ddtrace_coms_stack_t *tmp_stack;

    struct _writer_thread_variables_t *thread;

    _Atomic(BOOL_T) running, starting_up;
    _Atomic(pid_t) current_pid;
    _Atomic(BOOL_T) shutdown_when_idle, suspended, sending, allocate_new_stacks;
    _Atomic(uint32_t) flush_interval, request_counter, flush_processed_stacks_total, writer_cycle,
        requests_since_last_flush;
};

static struct _writer_loop_data_t global_writer = {.thread = NULL,
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

static void (*ptr_at_exit_callback)(void) = 0;

static void at_exit_callback() { ddtrace_coms_flush_shutdown_writer_synchronous(); }

static void at_exit_hook() {
    if (ptr_at_exit_callback) {
        ptr_at_exit_callback();
    }
}

void ddtrace_coms_setup_atexit_hook() {
    ptr_at_exit_callback = at_exit_callback;
    atexit(at_exit_hook);
}

void ddtrace_coms_disable_atexit_hook() { ptr_at_exit_callback = NULL; }

inline static void curl_send_stack(struct _writer_loop_data_t *writer, ddtrace_coms_stack_t *stack) {
    if (!writer->curl) {
        writer->curl = curl_easy_init();

        struct curl_slist *headers = NULL;
        headers = curl_slist_append(headers, "Transfer-Encoding: chunked");
        headers = curl_slist_append(headers, "Content-Type: application/msgpack");
        curl_easy_setopt(writer->curl, CURLOPT_HTTPHEADER, headers);

        curl_easy_setopt(writer->curl, CURLOPT_READFUNCTION, ddtrace_coms_read_callback);
        curl_easy_setopt(writer->curl, CURLOPT_WRITEFUNCTION, dummy_write_callback);
        writer->headers = headers;
    }

    if (writer->curl) {
        CURLcode res;

        void *read_data = ddtrace_init_read_userdata(stack);

        curl_easy_setopt(writer->curl, CURLOPT_READDATA, read_data);
        curl_set_hostname(writer->curl);
        curl_set_timeout(writer->curl);
        curl_set_connect_timeout(writer->curl);

        curl_easy_setopt(writer->curl, CURLOPT_UPLOAD, 1);
        curl_easy_setopt(writer->curl, CURLOPT_INFILESIZE, 10);
        curl_easy_setopt(writer->curl, CURLOPT_VERBOSE, get_dd_trace_agent_debug_verbose_curl());

        res = curl_easy_perform(writer->curl);

        if (res != CURLE_OK) {
            if (get_dd_trace_debug_curl_output()) {
                printf("curl_easy_perform() failed: %s\n", curl_easy_strerror(res));
                fflush(stdout);
            }
        } else {
            if (get_dd_trace_debug_curl_output()) {
                double uploaded;
                curl_easy_getinfo(writer->curl, CURLINFO_SIZE_UPLOAD, &uploaded);
                printf("UPLOADED %.0f bytes\n", uploaded);
                fflush(stdout);
            }
        }

        ddtrace_deinit_read_userdata(read_data);
    }
}
static inline void signal_writer_started(struct _writer_loop_data_t *writer) {
    if (writer->thread) {
        // at the moment no actual signal is sent but we will set a threadsafe state variable
        // ordering is important to correctly state that writer is either running or stil is starting up
        atomic_store(&writer->running, TRUE);
        atomic_store(&writer->starting_up, FALSE);
    }
}

static inline void signal_writer_finished(struct _writer_loop_data_t *writer) {
    if (writer->thread) {
        pthread_mutex_lock(&writer->thread->writer_shutdown_signal_mutex);
        atomic_store(&writer->running, FALSE);

        pthread_cond_signal(&writer->thread->writer_shutdown_signal_condition);
        pthread_mutex_unlock(&writer->thread->writer_shutdown_signal_mutex);
    }
}

static inline void signal_data_processed(struct _writer_loop_data_t *writer) {
    if (writer->thread) {
        pthread_mutex_lock(&writer->thread->finished_flush_mutex);
        pthread_cond_signal(&writer->thread->finished_flush_condition);
        pthread_mutex_unlock(&writer->thread->finished_flush_mutex);
    }
}

static void *writer_loop(void *_) {
    UNUSED(_);
    struct _writer_loop_data_t *writer = get_writer();

    BOOL_T running = TRUE;
    signal_writer_started(writer);
    do {
        atomic_fetch_add(&writer->writer_cycle, 1);
        uint32_t interval = atomic_load(&writer->flush_interval);
        // fprintf(stderr, "interval %lu\n", interval);
        if (interval > 0) {
            struct timespec wait_deadline = deadline_in_ms(interval);
            if (writer->thread) {
                pthread_mutex_lock(&writer->thread->interval_flush_mutex);
                pthread_cond_timedwait(&writer->thread->interval_flush_condition, &writer->thread->interval_flush_mutex,
                                       &wait_deadline);
                pthread_mutex_unlock(&writer->thread->interval_flush_mutex);
            }
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
            // successfully sent stack is no longer needed
            // ensure no one will refernce freed stack when thread restarts after fork
            *stack = NULL;
            ddtrace_coms_free_stack(to_free);

            *stack = ddtrace_coms_attempt_acquire_stack();
        }

        if (processed_stacks > 0) {
            atomic_fetch_add(&writer->flush_processed_stacks_total, processed_stacks);
        } else if (atomic_load(&writer->shutdown_when_idle)) {
            running = FALSE;
        }

        signal_data_processed(writer);
    } while (running);

    curl_slist_free_all(writer->headers);
    curl_easy_cleanup(writer->curl);
    ddtrace_coms_shutdown();
    signal_writer_finished(writer);
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

static inline struct _writer_thread_variables_t *create_thread_variables() {
    struct _writer_thread_variables_t *thread = calloc(1, sizeof(struct _writer_thread_variables_t));
    pthread_mutex_init(&thread->interval_flush_mutex, NULL);
    pthread_mutex_init(&thread->finished_flush_mutex, NULL);
    pthread_mutex_init(&thread->stack_rotation_mutex, NULL);

    pthread_mutex_init(&thread->writer_shutdown_signal_mutex, NULL);
    pthread_cond_init(&thread->writer_shutdown_signal_condition, NULL);

    pthread_cond_init(&thread->interval_flush_condition, NULL);
    pthread_cond_init(&thread->finished_flush_condition, NULL);

    return thread;
}

BOOL_T ddtrace_coms_init_and_start_writer() {
    struct _writer_loop_data_t *writer = get_writer();
    writer_set_operational_state(writer);
    atomic_store(&writer->current_pid, getpid());

    if (writer->thread) {
        return FALSE;
    }
    struct _writer_thread_variables_t *thread = create_thread_variables();
    writer->thread = thread;
    atomic_store(&writer->starting_up, TRUE);
    if (pthread_create(&thread->self, NULL, &writer_loop, NULL) == 0) {
        return TRUE;
    } else {
        return FALSE;
    }
}

static inline BOOL_T has_pid_changed() {
    struct _writer_loop_data_t *writer = get_writer();
    pid_t current_pid = getpid();
    pid_t previous_pid = atomic_load(&writer->current_pid);
    return current_pid != previous_pid;
}

BOOL_T ddtrace_coms_on_pid_change() {
    struct _writer_loop_data_t *writer = get_writer();

    pid_t current_pid = getpid();
    pid_t previous_pid = atomic_load(&writer->current_pid);
    if (current_pid == previous_pid) {
        return TRUE;
    }

    // ensure this reinitialization is done only once on pid change
    if (atomic_compare_exchange_strong(&writer->current_pid, &previous_pid, current_pid)) {
        if (writer->thread) {
            free(writer->thread);
            writer->thread = NULL;
        }

        ddtrace_coms_init_and_start_writer();
        return TRUE;
    }

    return FALSE;
}

BOOL_T ddtrace_coms_threadsafe_rotate_stack(BOOL_T attempt_allocate_new) {
    struct _writer_loop_data_t *writer = get_writer();
    BOOL_T rv = FALSE;
    if (writer->thread) {
        pthread_mutex_lock(&writer->thread->stack_rotation_mutex);
        rv = ddtrace_coms_rotate_stack(attempt_allocate_new);
        pthread_mutex_unlock(&writer->thread->stack_rotation_mutex);
    }
    return rv;
}

BOOL_T ddtrace_coms_trigger_writer_flush() {
    struct _writer_loop_data_t *writer = get_writer();
    if (writer->thread) {
        pthread_mutex_lock(&writer->thread->interval_flush_mutex);
        pthread_cond_signal(&writer->thread->interval_flush_condition);
        pthread_mutex_unlock(&writer->thread->interval_flush_mutex);
    }

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

// Returns TRUE if writer is shutdown completely
BOOL_T ddtrace_coms_flush_shutdown_writer_synchronous() {
    struct _writer_loop_data_t *writer = get_writer();
    if (!writer->thread) {
        return TRUE;
    }

    writer_set_shutdown_state(writer);

    // wait for writer cycle to to complete before exiting
    pthread_mutex_lock(&writer->thread->writer_shutdown_signal_mutex);
    ddtrace_coms_trigger_writer_flush();

    BOOL_T should_join = FALSE;
    // see signal_writer_started
    if (atomic_load(&writer->starting_up) || atomic_load(&writer->running)) {
        struct timespec deadline = deadline_in_ms(get_dd_trace_shutdown_timeout());

        int rv = pthread_cond_timedwait(&writer->thread->writer_shutdown_signal_condition,
                                        &writer->thread->writer_shutdown_signal_mutex, &deadline);
        if (rv == 0) {
            should_join = TRUE;
        }
    } else {
        should_join = TRUE;
    }
    pthread_mutex_unlock(&writer->thread->writer_shutdown_signal_mutex);

    if (should_join && !has_pid_changed()) {
        // when timeout was not reached and we haven't forked (without restarting thread)
        // this ensures situation when join is safe from being deadlocked
        pthread_join(writer->thread->self, NULL);
        free(writer->thread);
        writer->thread = NULL;
        return TRUE;
    }
    return FALSE;
}

BOOL_T ddtrace_coms_synchronous_flush(uint32_t timeout) {
    struct _writer_loop_data_t *writer = get_writer();
    uint32_t previous_writer_cycle = atomic_load(&writer->writer_cycle);
    uint32_t previous_processed_stacks_total = atomic_load(&writer->flush_processed_stacks_total);
    int64_t old_flush_interval = atomic_load(&writer->flush_interval);

    // ensure we immediately flush all data
    atomic_store(&writer->flush_interval, 0);

    pthread_mutex_lock(&writer->thread->finished_flush_mutex);
    ddtrace_coms_trigger_writer_flush();

    while (previous_writer_cycle == atomic_load(&writer->writer_cycle)) {
        if (!atomic_load(&writer->running) || !writer->thread) {
            // writer stopped there is no way the counter will be increaseed
            break;
        }
        struct timespec deadline = deadline_in_ms(timeout);
        pthread_cond_timedwait(&writer->thread->finished_flush_condition, &writer->thread->finished_flush_mutex,
                               &deadline);
    }
    pthread_mutex_unlock(&writer->thread->finished_flush_mutex);

    // restore the flush interval
    atomic_store(&writer->flush_interval, old_flush_interval);

    uint32_t processed_stacks_total =
        atomic_load(&writer->flush_processed_stacks_total) - previous_processed_stacks_total;

    return processed_stacks_total > 0;
}

BOOL_T ddtrace_in_writer_thread() {
    struct _writer_loop_data_t *writer = get_writer();
    if (!writer->thread) {
        return FALSE;
    }

    return (pthread_self() == writer->thread->self);
}
