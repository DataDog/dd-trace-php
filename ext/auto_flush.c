#include "auto_flush.h"

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
#include "ddshared.h"
#include <main/SAPI.h>

ZEND_RESULT_CODE ddtrace_flush_tracer(bool force_on_startup, bool collect_cycles) {
    bool success = true;

    zval trace, traces;
    array_init(&trace);
    if (collect_cycles) {
        ddtrace_serialize_closed_spans_with_cycle(&trace);
    } else {
        ddtrace_serialize_closed_spans(&trace);
    }

    // Prevent traces from requests not executing any PHP code:
    // PG(during_request_startup) will only be set to 0 upon execution of any PHP code.
    // e.g. php-fpm call with uri pointing to non-existing file, fpm status page, ...
    if (!force_on_startup && PG(during_request_startup)) {
        zend_array_destroy(Z_ARR(trace));
        return SUCCESS;
    }

    if (zend_hash_num_elements(Z_ARR(trace)) == 0) {
        zend_array_destroy(Z_ARR(trace));
        LOG(Info, "No finished traces to be sent to the agent");
        return SUCCESS;
    }

    // background sender only wants a singular trace
    array_init(&traces);
    zend_hash_index_add(Z_ARR(traces), 0, &trace);

    size_t limit = get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE();
    if (get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        if (ddtrace_sidecar) {
            ddog_ShmHandle *shm;
            ddog_MappedMem_ShmHandle *mapped_shm;
            if (ddtrace_ffi_try("Failed allocating shared memory", ddog_alloc_anon_shm_handle(limit, &shm))) {
                void *ptr;
                size_t size;
                if (ddtrace_ffi_try("Failed mapping shared memory", ddog_map_shm(shm, &mapped_shm, &ptr, &size))) {
                    // we just overcommit and free it later anyway
                    size_t written = ddtrace_serialize_simple_array_into_mapped_menory(&traces, ptr, size);
                    shm = ddog_unmap_shm(mapped_shm);

                    if (written) {
                        ddog_TracerHeaderTags tags = {
                                .container_id = ddtrace_get_container_id(),
                                .lang = DDOG_CHARSLICE_C("php"),
                                .lang_interpreter = (ddog_CharSlice) {.ptr = sapi_module.name, .len = strlen(sapi_module.name)},
                                .lang_vendor = DDOG_CHARSLICE_C(""),
                                .tracer_version = DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION),
                                .lang_version = dd_zend_string_to_CharSlice(ddtrace_php_version),
                                .client_computed_top_level = false,
                                .client_computed_stats = false,
                        };
                        ddog_MaybeError send_error = ddog_sidecar_send_trace_v04_shm(&ddtrace_sidecar, ddtrace_sidecar_instance_id, shm, &tags);
                        do {
                            if (send_error.tag == DDOG_OPTION_VEC_U8_SOME_VEC_U8) {
                                // retry sending it directly through the socket as last resort. May block though with large traces.
                                ddog_map_shm(shm, &mapped_shm, &ptr, &size);
                                ddog_MaybeError retry_error = ddog_sidecar_send_trace_v04_bytes(&ddtrace_sidecar, ddtrace_sidecar_instance_id, (ddog_CharSlice){ .ptr = ptr, .len = size }, &tags);
                                shm = ddog_unmap_shm(mapped_shm);
                                ddog_drop_anon_shm_handle(shm);
                                if (ddtrace_ffi_try("Failed sending traces to the sidecar", retry_error)) {
                                    LOG(Debug, "Failed sending traces via shm to sidecar: %.*s", (int) send_error.some.len, send_error.some.ptr);
                                } else {
                                    break;
                                }
                            }

                            char *url = ddtrace_agent_url();
                            LOG(Info, "Flushing trace of size %d to send-queue for %s",
                                zend_hash_num_elements(Z_ARR(trace)), url);
                            free(url);
                        } while (0);
                    } else {
                        ddog_drop_anon_shm_handle(shm);
                    }
                }
            }
        } else {
            LOG(Info, "Skipping flushing trace of size %d as connection to sidecar failed",
                               zend_hash_num_elements(Z_ARR(trace)));
        }
    } else {
#ifndef _WIN32
        char *payload;
        size_t size;
        if (ddtrace_serialize_simple_array_into_c_string(&traces, &payload, &size)) {
            if (size > limit) {
                LOG(Error, "Agent request payload of %zu bytes exceeds configured %zu byte limit; dropping request", size, limit);
                success = false;
            } else {
                success = ddtrace_send_traces_via_thread(1, payload, size);
                if (success) {
                    char *url = ddtrace_agent_url();
                    LOG(Info, "Flushing trace of size %d to send-queue for %s",
                                       zend_hash_num_elements(Z_ARR(trace)), url);
                    free(url);
                }
                dd_prepare_for_new_trace();
            }

            free(payload);
        } else
#endif
        {
            success = false;
        }
    }

    zval_ptr_dtor(&traces);

    return success ? SUCCESS : FAILURE;
}

DDTRACE_PUBLIC void ddtrace_close_all_spans_and_flush()
{
    ddtrace_close_all_open_spans(true);
    ddtrace_flush_tracer(true, true);
}

#define HOST_V6_FORMAT_STR "http://[%s]:%u"
#define HOST_V4_FORMAT_STR "http://%s:%u"
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
