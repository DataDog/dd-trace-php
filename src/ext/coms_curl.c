#include <sys/time.h>
#include <time.h>
#include <curl/curl.h>
#include <pthread.h>
#include <string.h>
#include <stdlib.h>
#include <stdint.h>

#include "coms_curl.h"
#include "coms.h"
#include "vendor_stdatomic.h"
#include "env_config.h"
#include "compatibility.h"

#define HOST_FORMAT_STR "http://%s:%u/v0.4/traces"

struct _writer_loop_data_t {
    pthread_t thread;
    pthread_mutex_t mutex;
    pthread_cond_t condition;
    _Atomic(BOOL_T) shutdown;
    _Atomic(BOOL_T) send;
};

static inline struct timespec deadline_in_ms(uint32_t ms) {
    struct timespec deadline;
    struct timeval now;

    gettimeofday(&now, NULL);
    uint32_t sec = ms / 1000UL;
    uint32_t msec = ms % 1000UL;
    deadline.tv_sec = now.tv_sec + sec;
    deadline.tv_nsec = ((now.tv_usec + 1000UL * msec) * 1000UL);

    // carry over full seconds from nsec
    deadline.tv_sec += deadline.tv_nsec / (1000*1000*1000);
    deadline.tv_nsec %= (1000*1000*1000);

    return deadline;
}

static void curl_set_hostname(CURL *curl) {
    char *hostname = ddtrace_get_c_string_config("DD_AGENT_HOST");
    int64_t port = ddtrace_get_int_config("DD_TRACE_AGENT_PORT", 8126);
    if (port <= 0 || port > 65535) {
        port = 8126;
    }

    if (hostname) {
        size_t agent_url_len = strlen(hostname) + sizeof(HOST_FORMAT_STR) + 10; // port digit allocation + some headroom
        char *agent_url = malloc(agent_url_len);
        snprintf(agent_url, agent_url_len, HOST_FORMAT_STR, hostname, (uint32_t) port);

        curl_easy_setopt(curl, CURLOPT_URL, agent_url);
        ddtrace_env_free(hostname);
        free(agent_url);
    } else {
        curl_easy_setopt(curl, CURLOPT_URL, "http://localhost:8126/v0.4/traces");
    }
}

static void curl_send_stack(ddtrace_coms_stack_t *stack) {
    CURL *curl = curl_easy_init();
    if  (curl) {
        CURLcode res;
        curl_set_hostname(curl);

        struct curl_slist *headers = NULL;
        headers = curl_slist_append(headers, "Transfer-Encoding: chunked");
        headers = curl_slist_append(headers, "Content-Type: application/msgpack");

        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
        curl_easy_setopt(curl, CURLOPT_UPLOAD, 1);
        curl_easy_setopt(curl, CURLOPT_INFILESIZE, 10);
        curl_easy_setopt(curl, CURLOPT_VERBOSE, 0L);

        void *read_data = ddtrace_init_read_userdata(stack);

        curl_easy_setopt(curl, CURLOPT_READDATA, read_data);
        curl_easy_setopt(curl, CURLOPT_READFUNCTION, ddtrace_coms_read_callback);

        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, NULL);

        res = curl_easy_perform(curl);
        curl_slist_free_all(headers);
        curl_easy_cleanup(curl);

        free(read_data);

        if(res != CURLE_OK) {
            fprintf(stderr, "curl_easy_perform() failed: %s\n",
            curl_easy_strerror(res));
        } else {
            double uploaded;
            curl_easy_getinfo(curl, CURLINFO_SIZE_UPLOAD, &uploaded);
            printf("%f\n", uploaded);
        }
    }

}

static struct _writer_loop_data_t global_writer = {
    .mutex = PTHREAD_MUTEX_INITIALIZER,
    .condition = PTHREAD_COND_INITIALIZER,
    .shutdown = {0},
    .send = {0}
};

#define DEFAULT_FLUSH_INTERVAL (5000)

uint32_t get_flush_interval() {
    int64_t interval = ddtrace_get_int_config("DD_TRACE_AGENT_FLUSH_INTERVAL", DEFAULT_FLUSH_INTERVAL);
    if (interval < 0 || interval > UINT32_MAX) {
        interval = DEFAULT_FLUSH_INTERVAL;
    }

    return interval;
}

void *writer_loop(void *_) {
    UNUSED(_);
    struct _writer_loop_data_t *writer = &global_writer;

    do {
        struct timespec deadline = deadline_in_ms(get_flush_interval());

        pthread_mutex_lock(&writer->mutex);
        fflush(stdout);

        int wait_result = pthread_cond_timedwait(&writer->condition, &writer->mutex, &deadline);

        pthread_mutex_unlock(&writer->mutex);
        ddtrace_coms_rotate_stack();

        ddtrace_coms_stack_t *stack;
        while (stack = ddtrace_coms_attempt_acquire_stack()) {
            if (atomic_load(&writer->send)) {
                curl_send_stack(stack);
            }
            ddtrace_coms_free_stack(stack);
        }
    } while (!atomic_load(&writer->shutdown));

    pthread_exit(NULL);
    return NULL;
}


BOOL_T ddtrace_coms_init_and_start_writer(){
    struct _writer_loop_data_t *writer = get_writer();

    if (pthread_create(&writer->thread, NULL, &writer_loop, NULL) == 0) {
        return TRUE;
    }

    return FALSE;
}

static inline struct _writer_loop_data_t *get_writer(){
    return &global_writer;
}

BOOL_T ddtrace_coms_signal_writer() {
    struct _writer_loop_data_t *writer = get_writer();

    pthread_mutex_lock(&writer->mutex);
    pthread_cond_signal(&writer->condition);
    pthread_mutex_unlock(&writer->mutex);
    return TRUE;
}

static inline BOOL_T ddtrace_coms_set_writer_send(BOOL_T send) {
    struct _writer_loop_data_t *writer = get_writer();
    atomic_store(&writer->send, send);
}

BOOL_T ddtrace_coms_shutdown_writer(BOOL_T immediate, BOOL_T with_send) {
    struct _writer_loop_data_t *writer = get_writer();
    atomic_store(&writer->shutdown, TRUE);
    ddtrace_coms_set_writer_send(send);

    if (immediate) {
        ddtrace_coms_signal_writer();
    }

    void *ptr;
    pthread_join(writer->thread, &ptr);

    return TRUE;
}

uint32_t curl_ze_data_out() {
    ddtrace_coms_init_and_start_writer();
    ddtrace_coms_shutdown_writer(TRUE, TRUE);

    return 1;
}
