#include "dogstatsd_client.h"

#include <dogstatsd_client/client.h>

#include "configuration.h"
#include "ddtrace.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define METRICS_CONST_TAGS "lang:php,lang_version:" PHP_VERSION ",tracer_version:" PHP_DDTRACE_VERSION

void ddtrace_dogstatsd_client_minit(void) { DDTRACE_G(dogstatsd_client) = dogstatsd_client_default_ctor(); }

static void _set_dogstatsd_client_globals(dogstatsd_client client, char *host, char *port, char *buffer) {
    DDTRACE_G(dogstatsd_client) = client;
    DDTRACE_G(dogstatsd_host) = host;
    DDTRACE_G(dogstatsd_port) = port;
    DDTRACE_G(dogstatsd_buffer) = buffer;
}

void ddtrace_dogstatsd_client_rinit(void) {
    bool health_metrics_enabled = get_DD_TRACE_HEALTH_METRICS_ENABLED();
    dogstatsd_client client = dogstatsd_client_default_ctor();
    char *host = NULL;
    char *port = NULL;
    char *buffer = NULL;

    while (health_metrics_enabled) {
        host = ZSTR_VAL(get_DD_AGENT_HOST());
        port = ZSTR_VAL(get_DD_DOGSTATSD_PORT());
        buffer = malloc(DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE);
        size_t len = DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE;

        struct addrinfo *addrs;
        int err;
        if ((err = dogstatsd_client_getaddrinfo(&addrs, host, port))) {
            ddtrace_log_debugf("Dogstatsd client failed looking up %s:%s: %s", host, port,
                               (err == EAI_SYSTEM) ? strerror(errno) : gai_strerror(err));
            break;
        }

        client = dogstatsd_client_ctor(addrs, buffer, len, METRICS_CONST_TAGS);
        if (dogstatsd_client_is_default_client(client)) {
            ddtrace_log_debugf("Dogstatsd client failed opening socket to %s:%s", host, port);
            break;
        }

        double sample_rate = get_DD_TRACE_HEALTH_METRICS_HEARTBEAT_SAMPLE_RATE();
        const char *metric = "datadog.tracer.heartbeat";
        dogstatsd_metric_t type = DOGSTATSD_METRIC_GAUGE;
        dogstatsd_client_status status = dogstatsd_client_metric_send(&client, metric, "1", type, sample_rate, NULL);
        if (status != DOGSTATSD_CLIENT_OK && get_DD_TRACE_DEBUG()) {
            const char *status_str = dogstatsd_client_status_to_str(status) ?: "(unknown dogstatsd_client_status)";
            ddtrace_log_errf("Health metric '%s' failed to send: %s", metric, status_str);
        }

        host = zend_strndup(host, strlen(host));
        port = zend_strndup(port, strlen(port));
        break;
    }
    _set_dogstatsd_client_globals(client, host, port, buffer);
}

void ddtrace_dogstatsd_client_rshutdown(void) {
    dogstatsd_client_dtor(&DDTRACE_G(dogstatsd_client));
    free(DDTRACE_G(dogstatsd_host));
    free(DDTRACE_G(dogstatsd_port));
    free(DDTRACE_G(dogstatsd_buffer));

    _set_dogstatsd_client_globals(dogstatsd_client_default_ctor(), NULL, NULL, NULL);
}
