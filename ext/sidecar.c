#include "ddtrace.h"
#include "auto_flush.h"
#include "compat_string.h"
#include "configuration.h"
#include "dogstatsd.h"
#include "logging.h"
#include <components-rs/common.h>
#include <components-rs/ddtrace.h>
#include "sidecar.h"
#include "telemetry.h"

ddog_SidecarTransport *ddtrace_sidecar;
ddog_Endpoint *ddtrace_endpoint;
struct ddog_InstanceId *ddtrace_sidecar_instance_id;
static uint8_t dd_sidecar_formatted_session_id[36];

// Set the globals that stay unchanged in case of fork
static void ddtrace_set_non_resettable_sidecar_globals(void) {
    ddtrace_format_runtime_id(&dd_sidecar_formatted_session_id);

   if (get_global_DD_TRACE_AGENTLESS() && ZSTR_LEN(get_global_DD_API_KEY())) {
        ddtrace_endpoint = ddog_endpoint_from_api_key(dd_zend_string_to_CharSlice(get_global_DD_API_KEY()));
    } else {
        char *agent_url = ddtrace_agent_url();
        ddtrace_endpoint = ddog_endpoint_from_url((ddog_CharSlice) {.ptr = agent_url, .len = strlen(agent_url)});
        free(agent_url);
    }
}

// Set the globals that must be updated in case of fork
static void ddtrace_set_resettable_sidecar_globals(void) {
    uint8_t formatted_run_time_id[36];
    ddtrace_format_runtime_id(&formatted_run_time_id);
    ddog_CharSlice runtime_id = (ddog_CharSlice) {.ptr = (char *) formatted_run_time_id, .len = sizeof(formatted_run_time_id)};
    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) dd_sidecar_formatted_session_id, .len = sizeof(dd_sidecar_formatted_session_id)};
    ddtrace_sidecar_instance_id = ddog_sidecar_instanceId_build(session_id, runtime_id);
}

ddog_SidecarTransport *dd_sidecar_connection_factory(void) {
    // Should not happen
    if (!ddtrace_endpoint) {
        return NULL;
    }

    ddog_Endpoint *dogstatsd_endpoint;
    if (get_global_DD_TRACE_AGENTLESS() && ZSTR_LEN(get_global_DD_API_KEY())) {
        dogstatsd_endpoint = ddog_endpoint_from_api_key(dd_zend_string_to_CharSlice(get_global_DD_API_KEY()));;
    } else {
        char *dogstatsd_url = ddtrace_dogstatsd_url();
        dogstatsd_endpoint = ddog_endpoint_from_url((ddog_CharSlice) {.ptr = dogstatsd_url, .len = strlen(dogstatsd_url)});
        free(dogstatsd_url);
    }

#ifdef _WIN32
    DDOG_PHP_FUNCTION = (const uint8_t *)zend_hash_func;
#endif

    char logpath[MAXPATHLEN];
    int error_fd = atomic_load(&ddtrace_error_log_fd);
    if (error_fd == -1 || ddtrace_get_fd_path(error_fd, logpath) < 0) {
        *logpath = 0;
    }

    ddog_SidecarTransport *sidecar_transport;
    if (!ddtrace_ffi_try("Failed connecting to the sidecar", ddog_sidecar_connect_php(&sidecar_transport, logpath, dd_zend_string_to_CharSlice(get_global_DD_TRACE_LOG_LEVEL()), get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()))) {
        ddog_endpoint_drop(dogstatsd_endpoint);
        return NULL;
    }

    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) dd_sidecar_formatted_session_id, .len = sizeof(dd_sidecar_formatted_session_id)};
    ddog_sidecar_session_set_config(&sidecar_transport, session_id, ddtrace_endpoint, dogstatsd_endpoint,
                                    get_global_DD_TRACE_AGENT_FLUSH_INTERVAL(),
                                    get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE() * get_DD_TRACE_BETA_HIGH_MEMORY_PRESSURE_PERCENT() / 100,
                                    get_global_DD_TRACE_AGENT_STACK_BACKLOG() * get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE(),
                                    get_global_DD_TRACE_DEBUG() ? DDOG_CHARSLICE_C("debug") : dd_zend_string_to_CharSlice(get_global_DD_TRACE_LOG_LEVEL()),
                                    (ddog_CharSlice){ .ptr = logpath, .len = strlen(logpath) });

    ddog_endpoint_drop(dogstatsd_endpoint);

    if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddtrace_telemetry_register_services(sidecar_transport);
    }

    return sidecar_transport;
}

void ddtrace_sidecar_setup(void) {
    ddtrace_set_non_resettable_sidecar_globals();
    ddtrace_set_resettable_sidecar_globals();

    ddtrace_sidecar = dd_sidecar_connection_factory();
    if (!ddtrace_sidecar && ddtrace_endpoint) { // Something went wrong
        ddog_endpoint_drop(ddtrace_endpoint);
        ddtrace_endpoint = NULL;
    }

    if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddtrace_telemetry_first_init();
    }
}

void ddtrace_sidecar_ensure_active(void) {
    if (ddtrace_sidecar) {
        ddog_sidecar_reconnect(&ddtrace_sidecar, dd_sidecar_connection_factory);
    }
}

void ddtrace_sidecar_shutdown(void) {
    if (ddtrace_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(ddtrace_sidecar_instance_id);
    }
    if (ddtrace_endpoint) {
        ddog_endpoint_drop(ddtrace_endpoint);
    }
    if (ddtrace_sidecar) {
        ddog_sidecar_transport_drop(ddtrace_sidecar);
    }
}

void ddtrace_reset_sidecar_globals(void) {
    if (ddtrace_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(ddtrace_sidecar_instance_id);
        ddtrace_set_resettable_sidecar_globals();
    }
}

static inline void ddtrace_sidecar_dogstatsd_push_tag(ddog_Vec_Tag *vec, ddog_CharSlice key, ddog_CharSlice value) {
    ddog_Vec_Tag_PushResult tag_result = ddog_Vec_Tag_push(vec, key, value);
    if (tag_result.tag == DDOG_VEC_TAG_PUSH_RESULT_ERR) {
        zend_string *msg = dd_CharSlice_to_zend_string(ddog_Error_message(&tag_result.err));
        LOG(WARN, "Failed to push DogStatsD tag: %s", ZSTR_VAL(msg));
        ddog_Error_drop(&tag_result.err);
        zend_string_release(msg);
    }
}

static void ddtrace_sidecar_dogstatsd_push_tags(ddog_Vec_Tag *vec, zval *tags) {
    // Global tags (https://github.com/DataDog/php-datadogstatsd/blob/0efdd1c38f6d3dd407efbb899ad1fd2e5cd18085/src/DogStatsd.php#L113-L125)
    zend_string *env = get_DD_ENV();
    if (ZSTR_LEN(env) > 0) {
        ddtrace_sidecar_dogstatsd_push_tag(vec, DDOG_CHARSLICE_C("env"), dd_zend_string_to_CharSlice(env));
    }
    zend_string *service = get_DD_SERVICE();
    if (ZSTR_LEN(service) > 0) {
        ddtrace_sidecar_dogstatsd_push_tag(vec, DDOG_CHARSLICE_C("service"), dd_zend_string_to_CharSlice(service));
    }
    zend_string *version = get_DD_VERSION();
    if (ZSTR_LEN(version) > 0) {
        ddtrace_sidecar_dogstatsd_push_tag(vec, DDOG_CHARSLICE_C("version"), dd_zend_string_to_CharSlice(version));
    }

    // Specific tags
    if (!tags || Z_TYPE_P(tags) != IS_ARRAY) {
        return;
    }

    zend_string *key;
    zval *tag_val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR_P(tags), key, tag_val) {
        if (!key) {
            continue;
        }
        zval value_str;
        ddtrace_convert_to_string(&value_str, tag_val);
        ddtrace_sidecar_dogstatsd_push_tag(vec, dd_zend_string_to_CharSlice(key), dd_zend_string_to_CharSlice(Z_STR(value_str)));
        zend_string_release(Z_STR(value_str));
    }
    ZEND_HASH_FOREACH_END();
}

void ddtrace_sidecar_dogstatsd_count(zend_string *metric, zend_long value, zval *tags) {
    if (!ddtrace_sidecar) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_dogstatsd_push_tags(&vec, tags);
    ddog_sidecar_dogstatsd_count(&ddtrace_sidecar, ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec);
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_sidecar_dogstatsd_distribution(zend_string *metric, double value, zval *tags) {
    if (!ddtrace_sidecar) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_dogstatsd_push_tags(&vec, tags);
    ddog_sidecar_dogstatsd_distribution(&ddtrace_sidecar, ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec);
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_sidecar_dogstatsd_gauge(zend_string *metric, double value, zval *tags) {
    if (!ddtrace_sidecar) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_dogstatsd_push_tags(&vec, tags);
    ddog_sidecar_dogstatsd_gauge(&ddtrace_sidecar, ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec);
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_sidecar_dogstatsd_histogram(zend_string *metric, double value, zval *tags) {
    if (!ddtrace_sidecar) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_dogstatsd_push_tags(&vec, tags);
    ddog_sidecar_dogstatsd_histogram(&ddtrace_sidecar, ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec);
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_sidecar_dogstatsd_set(zend_string *metric, zend_long value, zval *tags) {
    if (!ddtrace_sidecar) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_dogstatsd_push_tags(&vec, tags);
    ddog_sidecar_dogstatsd_set(&ddtrace_sidecar, ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec);
    ddog_Vec_Tag_drop(vec);
}
