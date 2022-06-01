#include "dogstatsd_client.h"

#include <dogstatsd_client/client.h>

#include "configuration.h"
#include "ddtrace.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define METRICS_CONST_TAGS "lang:php,lang_version:" PHP_VERSION ",tracer_version:" PHP_DDTRACE_VERSION
#define DEFAULT_UDS_PATH "/var/run/datadog/dsd.socket"

void ddtrace_dogstatsd_client_minit(void) { DDTRACE_G(dogstatsd_client) = dogstatsd_client_default_ctor(); }

static void _set_dogstatsd_client_globals(dogstatsd_client client) { DDTRACE_G(dogstatsd_client) = client; }

void ddtrace_dogstatsd_client_rinit(void) {
    bool health_metrics_enabled = get_DD_TRACE_HEALTH_METRICS_ENABLED();
    dogstatsd_client client = dogstatsd_client_default_ctor();

    while (health_metrics_enabled) {
        struct addrinfo *addrs;
        char *host = ZSTR_VAL(get_DD_AGENT_HOST());
        if (!*host) {
            if (access(DEFAULT_UDS_PATH, F_OK) == SUCCESS) {
                addrs = malloc(sizeof(*addrs));
                addrs->ai_next = NULL;
                addrs->ai_family = PF_UNIX;
                addrs->ai_protocol = 0;
                addrs->ai_socktype = SOCK_STREAM;
                struct sockaddr_un *unixaddr = calloc(1, sizeof(struct sockaddr_un));
                addrs->ai_addr = (struct sockaddr *)unixaddr;
                memcpy(unixaddr->sun_path, DEFAULT_UDS_PATH, sizeof(DEFAULT_UDS_PATH));
                unixaddr->sun_family = AF_UNIX;
                host = NULL;
            } else {
                host = "localhost";
            }
        }

        char *port = ZSTR_VAL(get_DD_DOGSTATSD_PORT());
        if (host) {
            int err;
            if ((err = dogstatsd_client_getaddrinfo(&addrs, host, port))) {
                ddtrace_log_debugf("Dogstatsd client failed looking up %s:%s: %s", host, port,
                                   (err == EAI_SYSTEM) ? strerror(errno) : gai_strerror(err));
                break;
            }
        }

        client = dogstatsd_client_ctor(addrs, DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE, METRICS_CONST_TAGS);
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

        break;
    }
    _set_dogstatsd_client_globals(client);
}

void ddtrace_dogstatsd_client_rshutdown(void) {
    dogstatsd_client_dtor(&DDTRACE_G(dogstatsd_client));

    _set_dogstatsd_client_globals(dogstatsd_client_default_ctor());
}
