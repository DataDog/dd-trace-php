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

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

ZEND_RESULT_CODE ddtrace_flush_tracer(bool force_on_startup, bool collect_cycles) {
    bool success = true;

    zval traces;
    array_init(&traces);
    if (collect_cycles) {
        ddtrace_serialize_closed_spans_with_cycle(&traces);
    } else {
        ddtrace_serialize_closed_spans(&traces);
    }

    // Prevent traces from requests not executing any PHP code:
    // PG(during_request_startup) will only be set to 0 upon execution of any PHP code.
    // e.g. php-fpm call with uri pointing to non-existing file, fpm status page, ...
    if (!force_on_startup && PG(during_request_startup)) {
        zend_array_destroy(Z_ARR(traces));
        return SUCCESS;
    }

    if (zend_hash_num_elements(Z_ARR(traces)) == 0) {
        zend_array_destroy(Z_ARR(traces));
        LOG(INFO, "No finished traces to be sent to the agent");
        return SUCCESS;
    }

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
                                .lang = DDOG_CHARSLICE_C_BARE("php"),
                                .lang_interpreter = (ddog_CharSlice) {.ptr = sapi_module.name, .len = strlen(sapi_module.name)},
                                .lang_vendor = DDOG_CHARSLICE_C_BARE(""),
                                .tracer_version = DDOG_CHARSLICE_C_BARE(PHP_DDTRACE_VERSION),
                                .lang_version = dd_zend_string_to_CharSlice(ddtrace_php_version),
                                .client_computed_top_level = false,
                                .client_computed_stats = !get_global_DD_APM_TRACING_ENABLED(),
                        };
                        size_t size_hint = written;
                        zend_long n_requests = get_global_DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS();
                        if (n_requests) {
                            size_hint = MAX(get_global_DD_TRACE_BUFFER_SIZE() / n_requests + 1, size_hint);
                        }
                        ddog_MaybeError send_error = ddog_sidecar_send_trace_v04_shm(&ddtrace_sidecar, ddtrace_sidecar_instance_id, shm, size_hint, &tags);
                        do {
                            if (send_error.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
                                // retry sending it directly through the socket as last resort. May block though with large traces.
                                ptr = emalloc(written);
                                // write the same thing again
                                ddtrace_serialize_simple_array_into_mapped_menory(&traces, ptr, written);

                                ddog_MaybeError retry_error = ddog_sidecar_send_trace_v04_bytes(&ddtrace_sidecar, ddtrace_sidecar_instance_id, (ddog_CharSlice){ .ptr = ptr, .len = written }, &tags);

                                efree(ptr);

                                if (ddtrace_ffi_try("Failed sending traces to the sidecar", retry_error)) {
                                    ddog_CharSlice error_msg = ddog_Error_message(&send_error.some);
                                    LOG(DEBUG, "Failed sending traces via shm to sidecar: %.*s", (int) error_msg.len, error_msg.ptr);
                                    ddog_MaybeError_drop(send_error);
                                } else {
                                    ddog_MaybeError_drop(send_error);
                                    break;
                                }
                            }

                            LOGEV(INFO, {
                                char *url = ddtrace_agent_url();
                                zval *trace;
                                int num_spans = 0;
                                ZEND_HASH_FOREACH_VAL(Z_ARR(traces), trace) {
                                    num_spans += zend_hash_num_elements(Z_ARR_P(trace));
                                } ZEND_HASH_FOREACH_END();
                                log("Flushing trace of size %d to send-queue for %s", num_spans, url);
                                free(url);
                            });
                        } while (0);
                    } else {
                        ddog_drop_anon_shm_handle(shm);
                    }
                }
            }
        } else {
            LOGEV(INFO, {
                zval *trace;
                int num_spans = 0;
                ZEND_HASH_FOREACH_VAL(Z_ARR(traces), trace) {
                    num_spans += zend_hash_num_elements(Z_ARR_P(trace));
                } ZEND_HASH_FOREACH_END();
                log("Skipping flushing trace of size %d as connection to sidecar failed", num_spans);
            });
        }
    } else {
#ifndef _WIN32
        success = true;
        zval sender_trace_container, *trace;
        // background sender only wants a singular trace
        array_init(&sender_trace_container);
        zval *spans = zend_hash_index_add(Z_ARR(sender_trace_container), 0, &traces);

        // send in 1-trace chunks to make background sender happy
        ZEND_HASH_FOREACH_VAL(Z_ARR(traces), trace) {
            ZVAL_COPY_VALUE(spans, trace);

            char *payload;
            size_t size;
            if (ddtrace_serialize_simple_array_into_c_string(&sender_trace_container, &payload, &size)) {
                if (size > limit) {
                    LOG(ERROR, "Agent request payload of %zu bytes exceeds configured %zu byte limit; dropping request", size, limit);
                    success = false;
                } else {
                    success = ddtrace_send_traces_via_thread(1, payload, size);
                    if (success) {
                        LOGEV(INFO, {
                            char *url = ddtrace_agent_url();
                            log("Flushing trace of size %d to send-queue for %s",
                                zend_hash_num_elements(Z_ARR_P(trace)), url);
                            free(url);
                        });
                    }
                    dd_prepare_for_new_trace();
                }

                free(payload);
            } else {
                success = false;
            }
        } ZEND_HASH_FOREACH_END();

        ZVAL_UNDEF(spans);
        zval_ptr_dtor(&sender_trace_container);
#else
        success = false;
#endif
    }

    zval_ptr_dtor(&traces);

    return success ? SUCCESS : FAILURE;
}

DDTRACE_PUBLIC void ddtrace_close_all_spans_and_flush()
{
    ddtrace_close_all_open_spans(true);
    ddtrace_flush_tracer(true, true);
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
