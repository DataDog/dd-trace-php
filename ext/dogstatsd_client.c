#include "dogstatsd_client.h"

#include <dogstatsd_client/client.h>

#include "configuration.h"
#include "ddtrace.h"
#include <components/log/log.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define METRICS_CONST_TAGS "lang:php,lang_version:" PHP_VERSION ",tracer_version:" PHP_DDTRACE_VERSION
#define DEFAULT_UDS_PATH "/var/run/datadog/dsd.socket"

void ddtrace_dogstatsd_client_minit(void) { DDTRACE_G(dogstatsd_client) = dogstatsd_client_default_ctor(); }

static void _set_dogstatsd_client_globals(dogstatsd_client client) { DDTRACE_G(dogstatsd_client) = client; }

static struct addrinfo *dd_alloc_unix_addr(const char *path, size_t len) {
    struct addrinfo *addrs = malloc(sizeof(*addrs));
    addrs->ai_next = NULL;
    addrs->ai_family = PF_UNIX;
    addrs->ai_protocol = 0;
    addrs->ai_socktype = SOCK_STREAM;
    addrs->ai_addrlen = sizeof(struct sockaddr_un);
    struct sockaddr_un *unixaddr = calloc(1, sizeof(struct sockaddr_un));
    addrs->ai_addr = (struct sockaddr *)unixaddr;
    memcpy(unixaddr->sun_path, path, len);
    unixaddr->sun_family = AF_UNIX;
    return addrs;
}

void ddtrace_dogstatsd_client_rinit(void) {
    bool health_metrics_enabled = get_DD_TRACE_HEALTH_METRICS_ENABLED();
    dogstatsd_client client = dogstatsd_client_default_ctor();

    while (health_metrics_enabled) {
        struct addrinfo *addrs;
        char *url = ZSTR_VAL(get_DD_DOGSTATSD_URL());
        char *host, *port;
        if (*url) {
            if (strlen(url) > 7 && strncmp("unix://", url, 7) == 0) {
                addrs = dd_alloc_unix_addr(url + 7, strlen(url) - 7);
            } else if (strlen(url) > 6 && strncmp("udp://", url, 6) == 0) {
                char *colon = strchr(url + 6, ':');
                if (!colon) {
                    LOG(Warn,
                        "Dogstatsd client encountered an invalid udp:// DD_DOGSTATSD_URL: %s, missing a colon followed by a port",
                        url);
                    break;
                }

                host = estrndup(url + 6, colon - url - 6);

                port = colon + 1;
                int err;
                if ((err = dogstatsd_client_getaddrinfo(&addrs, host, port))) {
                    LOG(Warn, "Dogstatsd client failed looking up %s:%s: %s", host, port,
                                       (err == EAI_SYSTEM) ? strerror(errno) : gai_strerror(err));
                    efree(host);
                    break;
                }
                efree(host);
            } else {
                LOG(Warn,
                    "Dogstatsd client encountered an invalid DD_DOGSTATSD_URL: %s, expecting url starting with unix:// or udp://",
                    url);
                break;
            }

            host = url;
            port = NULL;
        } else {
            host = ZSTR_VAL(get_DD_AGENT_HOST());
            port = ZSTR_VAL(get_DD_DOGSTATSD_PORT());

            if (!*host) {
                if (access(DEFAULT_UDS_PATH, F_OK) == SUCCESS) {
                    addrs = dd_alloc_unix_addr(DEFAULT_UDS_PATH, sizeof(DEFAULT_UDS_PATH));
                    host = "unix://" DEFAULT_UDS_PATH;
                    port = NULL;
                } else {
                    host = "localhost";
                }
            }

            if (strlen(host) > 7 && strncmp("unix://", host, 7) == 0) {
                addrs = dd_alloc_unix_addr(host + 7, strlen(host) - 7);
                port = NULL;
            } else if (port) {
                int err;
                if ((err = dogstatsd_client_getaddrinfo(&addrs, host, port))) {
                    LOG(Warn, "Dogstatsd client failed looking up %s:%s: %s", host, port,
                                       (err == EAI_SYSTEM) ? strerror(errno) : gai_strerror(err));
                    break;
                }
            }
        }

        client = dogstatsd_client_ctor(addrs, DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE, METRICS_CONST_TAGS);
        if (dogstatsd_client_is_default_client(client)) {
            LOG(Warn, "Dogstatsd client failed opening socket to %s%s%s", host, port ? ":" : "",
                               port ? port : "");
            break;
        }

        double sample_rate = get_DD_TRACE_HEALTH_METRICS_HEARTBEAT_SAMPLE_RATE();
        const char *metric = "datadog.tracer.heartbeat";
        dogstatsd_metric_t type = DOGSTATSD_METRIC_GAUGE;
        dogstatsd_client_status status = dogstatsd_client_metric_send(&client, metric, "1", type, sample_rate, NULL);
        if (status != DOGSTATSD_CLIENT_OK) {
            LOGEV(Warn, {
                const char *status_str = dogstatsd_client_status_to_str(status) ?: "(unknown dogstatsd_client_status)";
                log("Health metric '%s' failed to send: %s", metric, status_str);
            })
        }

        break;
    }
    _set_dogstatsd_client_globals(client);
}

void ddtrace_dogstatsd_client_rshutdown(void) {
    dogstatsd_client_dtor(&DDTRACE_G(dogstatsd_client));

    _set_dogstatsd_client_globals(dogstatsd_client_default_ctor());
}
