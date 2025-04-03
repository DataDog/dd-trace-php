#include "dogstatsd.h"

#include "configuration.h"
#include "ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define DEFAULT_UDS_PATH "/var/run/datadog/dsd.socket"

char *ddtrace_dogstatsd_url(void) {
    zend_string *url = get_DD_DOGSTATSD_URL();
    if (ZSTR_LEN(url) > 0) {
        return zend_strndup(ZSTR_VAL(url), ZSTR_LEN(url) + 1);
    }

    zend_string *hostname = get_DD_DOGSTATSD_HOST();
    if (!hostname || ZSTR_LEN(hostname) == 0) {
        hostname = get_global_DD_AGENT_HOST();
    }

    if (ZSTR_LEN(hostname) > 7 && strncmp(ZSTR_VAL(hostname), "unix://", 7) == 0) {
        return zend_strndup(ZSTR_VAL(hostname), ZSTR_LEN(hostname));
    }

    if (ZSTR_LEN(hostname) > 0) {
        bool isIPv6 = memchr(ZSTR_VAL(hostname), ':', ZSTR_LEN(hostname));

        int port = atoi(ZSTR_VAL(get_DD_DOGSTATSD_PORT()));
        if (port <= 0 || port > 65535) {
            port = 8125;
        }
        char *formatted_url;
        asprintf(&formatted_url, isIPv6 ? HOST_V6_FORMAT_STR : HOST_V4_FORMAT_STR, ZSTR_VAL(hostname), (uint32_t)port);
        return formatted_url;
    }

    if (access(DEFAULT_UDS_PATH, F_OK) == SUCCESS) {
        return zend_strndup(ZEND_STRL("unix://" DEFAULT_UDS_PATH));
    }

    int64_t port = get_global_DD_TRACE_AGENT_PORT();
    if (port <= 0 || port > 65535) {
        port = 8125;
    }
    char *formatted_url;
    asprintf(&formatted_url, HOST_V4_FORMAT_STR, "localhost", (uint32_t)port);
    return formatted_url;
}
