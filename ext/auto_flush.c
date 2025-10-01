#include "auto_flush.h"

#include "asm_event.h"
#ifndef _WIN32
#include "comms_php.h"
#include "coms.h"
#endif
#include "ddtrace_string.h"
#include "configuration.h"
#include <components/log/log.h>
#include "serializer.h"
#include "span.h"
#include "sidecar.h"
#include "trace_source.h"
#include "ddshared.h"
#include "standalone_limiter.h"
#include <main/SAPI.h>
#include <components-rs/sidecar.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

ZEND_RESULT_CODE ddtrace_flush_tracer(bool force_on_startup, bool collect_cycles, bool fast_shutdown) {
    bool success = true;

    ddog_TracesBytes *traces = ddog_get_traces();
    if (collect_cycles) {
        ddtrace_serialize_closed_spans_with_cycle(traces, fast_shutdown);
    } else {
        ddtrace_serialize_closed_spans(traces, fast_shutdown);
    }

    // Prevent traces from requests not executing any PHP code:
    // PG(during_request_startup) will only be set to 0 upon execution of any PHP code.
    // e.g. php-fpm call with uri pointing to non-existing file, fpm status page, ...
    if (!force_on_startup && PG(during_request_startup)) {
        ddog_free_traces(traces);
        return SUCCESS;
    }

    if (!ddog_get_traces_size(traces)) {
        ddog_free_traces(traces);
        LOG(INFO, "No finished traces to be sent to the agent");
        return SUCCESS;
    }

    size_t limit = get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE();
    char *url = ddtrace_agent_url();

    if (get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        if (ddtrace_sidecar) {
            ddog_SenderParameters parameters = {
                .tracer_headers_tags = {
                    .container_id = ddtrace_get_container_id(),
                    .lang = DDOG_CHARSLICE_C_BARE("php"),
                    .lang_interpreter = (ddog_CharSlice) {.ptr = sapi_module.name, .len = strlen(sapi_module.name)},
                    .lang_vendor = DDOG_CHARSLICE_C_BARE(""),
                    .tracer_version = DDOG_CHARSLICE_C_BARE(PHP_DDTRACE_VERSION),
                    .lang_version = php_version_rt,
                    .client_computed_top_level = false,
                    .client_computed_stats = !get_global_DD_APM_TRACING_ENABLED(),
                },
                .transport = ddtrace_sidecar,
                .instance_id = ddtrace_sidecar_instance_id,
                .limit = limit,
                .n_requests = get_global_DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS(),
                .buffer_size = get_global_DD_TRACE_BUFFER_SIZE(),
                .url = (ddog_CharSlice) {.ptr = url, .len = strlen(url)},
            };
            ddog_send_traces_to_sidecar(traces, &parameters);
        } else {
            LOGEV(INFO, {
                log("Skipping flushing trace as connection to sidecar failed");
            });
        }
    } else {
#ifndef _WIN32
        success = true;
        size_t length = ddog_get_traces_size(traces);
        for (size_t i = 0; i < length; i++) {
            ddog_TraceBytes *trace = ddog_get_trace(traces, i);
            ddog_CharSlice serialized_trace = ddog_serialize_trace_into_charslice(trace);

            if (serialized_trace.len > 0) {
                if (serialized_trace.len > limit) {
                    LOG(ERROR, "Agent request payload of %zu bytes exceeds configured %zu byte limit; dropping request", serialized_trace.len, limit);
                    success = false;
                } else {
                    success = ddtrace_send_traces_via_thread(1, serialized_trace.ptr, serialized_trace.len);
                    if (success) {
                        LOGEV(INFO, {
                            log("Flushing trace of size %d to send-queue for %s", ddog_get_trace_size(trace), url);
                        });
                    }
                    dd_prepare_for_new_trace();
                }

                ddog_free_charslice(serialized_trace);
            } else {
                success = false;
            }
        }
#else
        success = false;
#endif
    }

    free(url);
    ddog_free_traces(traces);

    return success ? SUCCESS : FAILURE;
}

DDTRACE_PUBLIC void ddtrace_close_all_spans_and_flush()
{
    ddtrace_close_all_open_spans(true);
    ddtrace_flush_tracer(true, true, false);
}

#define DEFAULT_UDS_PATH "/var/run/datadog/apm.socket"

char *ddtrace_agent_url(void) {
    zend_string *url = get_global_DD_TRACE_AGENT_URL();
    if (ZSTR_LEN(url) > 0) {
        char *dup = zend_strndup(ZSTR_VAL(url), ZSTR_LEN(url) + 1);

        // mess around with backslashes to support our test cases providing something like "file://C:\dir\test.out"
        const char *fileprefix = "file://";
        if (strncmp(ZSTR_VAL(url), fileprefix, strlen(fileprefix)) == 0 && strchr(ZSTR_VAL(url), '\\')) {
            for (size_t i = strlen(fileprefix); i < ZSTR_LEN(url); ++i) {
                if (dup[i] == '\\') {
                    dup[i] = '/';
                }
            }
        }

        return dup;
    }

    zend_string *hostname = get_global_DD_AGENT_HOST();
    if (ZSTR_LEN(hostname) > 7 && strncmp(ZSTR_VAL(hostname), "unix://", 7) == 0) {
        return zend_strndup(ZSTR_VAL(hostname), ZSTR_LEN(hostname));
    }

    if (ZSTR_LEN(hostname) > 0) {
        bool isIPv6 = memchr(ZSTR_VAL(hostname), ':', ZSTR_LEN(hostname));

        int64_t port = get_global_DD_TRACE_AGENT_PORT();
        if (port <= 0 || port > 65535) {
            port = 8126;
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
        port = 8126;
    }
    char *formatted_url;
    asprintf(&formatted_url, HOST_V4_FORMAT_STR, "localhost", (uint32_t)port);
    return formatted_url;
}
