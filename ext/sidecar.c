#include <php.h>
#include <main/SAPI.h>
#include "datadog.h"
#include "configuration.h"
#include "datadog_export.h"
#include "endpoints.h"
#include "logging.h"
#include <components-rs/common.h>
#include <components-rs/datadog.h>
#include <components-rs/sidecar.h>
#include <zend_string.h>
#include "sidecar.h"
#include "telemetry.h"
#include "process_tags.h"
#include "remote_config.h"
#include "string_utils.h"
#include "target_metadata.h"
#include "ffi_utils.h"
#include <tracer/tracer_api.h>
#ifndef _WIN32
#include <tracer/coms.h>
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

ddog_Endpoint *datadog_endpoint;
ddog_Endpoint *dogstatsd_endpoint; // always set when datadog_endpoint is set
struct ddog_InstanceId *datadog_sidecar_instance_id;

// Best-effort pointer for the signal handler (SIGTERM/SIGINT). Set to the first
// per-thread connection; never cleared until MSHUTDOWN. Not atomic: concurrent
// shutdown is already a best-effort race for signal handlers, so atomicity of
// the pointer load alone would not prevent the underlying use-after-free.
ddog_SidecarTransport *datadog_sidecar_for_signal = NULL;

// Connection mode tracking
dd_sidecar_active_mode_t datadog_sidecar_active_mode = DD_SIDECAR_CONNECTION_NONE;
int32_t datadog_sidecar_master_pid = 0;

static inline void dd_set_endpoint_test_token(ddog_Endpoint *endpoint) {
    if (zai_config_is_initialized()) {
        if (ZSTR_LEN(get_DD_TRACE_AGENT_TEST_SESSION_TOKEN())) {
            ddog_endpoint_set_test_token(endpoint, dd_zend_string_to_CharSlice(get_DD_TRACE_AGENT_TEST_SESSION_TOKEN()));
        }
    } else if (ZSTR_LEN(get_global_DD_TRACE_AGENT_TEST_SESSION_TOKEN())) {
        ddog_endpoint_set_test_token(endpoint, dd_zend_string_to_CharSlice(get_global_DD_TRACE_AGENT_TEST_SESSION_TOKEN()));
    }
}

// Set the globals that stay unchanged in case of fork
static void dd_set_non_resettable_sidecar_globals(void) {
    datadog_endpoint = datadog_sidecar_agent_endpoint();

    if (get_global_DD_TRACE_AGENTLESS() && ZSTR_LEN(get_global_DD_API_KEY())) {
        dogstatsd_endpoint = ddog_endpoint_from_api_key(dd_zend_string_to_CharSlice(get_global_DD_API_KEY()));
    } else {
        char *dogstatsd_url = datadog_dogstatsd_url();
        dogstatsd_endpoint = ddog_endpoint_from_url((ddog_CharSlice) {.ptr = dogstatsd_url, .len = strlen(dogstatsd_url)});
        free(dogstatsd_url);
    }
}

// Build the process-level instance ID (one per PHP process, reset after fork).
static void dd_set_resettable_sidecar_globals(void) {
    uint8_t formatted_run_time_id[36];
    datadog_format_runtime_id(&formatted_run_time_id);
    ddog_CharSlice runtime_id = (ddog_CharSlice) {.ptr = (char *) formatted_run_time_id, .len = sizeof(formatted_run_time_id)};
    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) datadog_formatted_session_id, .len = sizeof(datadog_formatted_session_id)};
    datadog_sidecar_instance_id = ddog_sidecar_instanceId_build(session_id, runtime_id);
}

static void dd_free_endpoints(void) {
    ddog_endpoint_drop(datadog_endpoint);
    ddog_endpoint_drop(dogstatsd_endpoint);
    datadog_endpoint = NULL;
    dogstatsd_endpoint = NULL;
}

DATADOG_PUBLIC const uint8_t *datadog_get_formatted_session_id(void) {
    if (datadog_is_empty_session_id(datadog_formatted_session_id)) {
        return NULL;
    }
    return datadog_formatted_session_id;
}

DATADOG_PUBLIC struct telemetry_rc_info datadog_get_telemetry_rc_info(void) {
    struct telemetry_rc_info info = {
        .service_name = DATADOG_G(last_service_name),
        .env_name = DATADOG_G(last_env_name),
    };
    if (DATADOG_G(remote_config_state)) {
        info.rc_path = ddog_remote_config_get_path(DATADOG_G(remote_config_state));
    }

    return info;
}

DATADOG_PUBLIC uint64_t datadog_get_sidecar_queue_id(void) {
    return DATADOG_G(sidecar_queue_id);
}

#ifdef ZTS
DATADOG_PUBLIC ddog_SidecarTransport **ddtrace_get_sidecar_transport(void *tsrm_ls) {
    if (tsrm_ls) {
        void *saved = TSRMLS_CACHE;
        TSRMLS_CACHE = tsrm_ls;
        ddog_SidecarTransport **result = &TSRMG_STATIC(
            datadog_globals_id, zend_datadog_globals *, sidecar);
        TSRMLS_CACHE = saved;
        return result;
    }
    return &DATADOG_G(sidecar);
}
#else
DATADOG_PUBLIC ddog_SidecarTransport **ddtrace_get_sidecar_transport(void) {
    return &DATADOG_G(sidecar);
}
#endif

static void dd_sidecar_post_connect(ddog_SidecarTransport **transport, bool is_fork, const char *logpath) {
    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) datadog_formatted_session_id, .len = sizeof(datadog_formatted_session_id)};
    ddog_CharSlice root_session_id = datadog_is_empty_session_id(datadog_formatted_root_session_id) ? DDOG_CHARSLICE_C("") : (ddog_CharSlice) {.ptr = (char *) datadog_formatted_root_session_id, .len = sizeof(datadog_formatted_root_session_id)};
    ddog_CharSlice parent_session_id = datadog_is_empty_session_id(datadog_formatted_parent_session_id) ? DDOG_CHARSLICE_C("") : (ddog_CharSlice) {.ptr = (char *) datadog_formatted_parent_session_id, .len = sizeof(datadog_formatted_parent_session_id)};
    const ddog_Vec_Tag *process_tags = datadog_process_tags_get_vec();
    ddog_Endpoint *otlp_metrics_endpoint = datadog_otel_metrics_endpoint();
    ddog_sidecar_session_set_config(transport, session_id, datadog_endpoint, dogstatsd_endpoint, otlp_metrics_endpoint,
                                    DDOG_CHARSLICE_C("php"),
                                    php_version_rt,
                                    DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION),
                                    get_global_DD_TRACE_AGENT_FLUSH_INTERVAL(),
                                    get_global_DD_TRACE_RETRY_INTERVAL(),
                                    (int)(get_global_DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS() * 1000),
                                    // for historical reasons in seconds
                                    get_global_DD_TELEMETRY_HEARTBEAT_INTERVAL() * 1000,
                                    // extended heartbeat interval, also in seconds
                                    (uint64_t)get_global_DD_TELEMETRY_EXTENDED_HEARTBEAT_INTERVAL() * 1000,
                                    get_global_DD_TRACE_BUFFER_SIZE(),
                                    get_global_DD_TRACE_AGENT_STACK_BACKLOG() * get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE(),
                                    get_global_DD_TRACE_DEBUG() ? DDOG_CHARSLICE_C("debug") : dd_zend_string_to_CharSlice(get_global_DD_TRACE_LOG_LEVEL()),
                                    (ddog_CharSlice){ .ptr = logpath, .len = strlen(logpath) },
                                    datadog_set_all_thread_vm_interrupt,
                                    DATADOG_REMOTE_CONFIG_PRODUCTS.ptr,
                                    DATADOG_REMOTE_CONFIG_PRODUCTS.len,
                                    DATADOG_REMOTE_CONFIG_CAPABILITIES.ptr,
                                    DATADOG_REMOTE_CONFIG_CAPABILITIES.len,
                                    get_global_DD_TRACE_AGENTLESS() ? false : get_global_DD_REMOTE_CONFIG_ENABLED(),
                                    is_fork,
                                    process_tags,
                                    dd_zend_string_to_CharSlice(get_global_DD_HOSTNAME()),
                                    dd_zend_string_to_CharSlice(get_global_DD_SERVICE()),
                                    root_session_id,
                                    parent_session_id
                                );
    if (otlp_metrics_endpoint) {
        ddog_endpoint_drop(otlp_metrics_endpoint);
    }

    if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        datadog_telemetry_register_services(transport);
    }
}

void datadog_sidecar_update_process_tags(void) {
    if (!DATADOG_G(sidecar)) {
        return;
    }

    const ddog_Vec_Tag *process_tags = datadog_process_tags_get_vec();
    if (!process_tags || process_tags->len == 0) {
        return;
    }

    ddog_sidecar_session_set_process_tags(&DATADOG_G(sidecar), process_tags);
}

static void datadog_sidecar_setup_thread_mode(void);

static void dd_sidecar_on_reconnect(ddog_SidecarTransport *transport) {
    if (!datadog_endpoint || !dogstatsd_endpoint) {
        return;
    }

    char logpath[MAXPATHLEN];
    int error_fd = atomic_load(&datadog_error_log_fd);
    if (error_fd == -1 || datadog_get_fd_path(error_fd, logpath) < 0) {
        *logpath = 0;
    }

    dd_sidecar_post_connect(&transport, false, logpath);

    tsrm_mutex_lock(DATADOG_G(sidecar_universal_service_tags_mutex));

    if (DATADOG_G(sidecar_queue_id) && DATADOG_G(last_service_name)) {
        ddog_CharSlice service_name = dd_zend_string_to_CharSlice(DATADOG_G(last_service_name));
        ddog_CharSlice env_name = dd_zend_string_to_CharSlice(DATADOG_G(last_env_name));
        ddog_CharSlice version = dd_zend_string_to_CharSlice(DATADOG_G(last_version));
        uint64_t remote_config_generation = DATADOG_G(remote_config_state)
            ? ddog_remote_config_current_generation(DATADOG_G(remote_config_state))
            : 0;
        datadog_ffi_try("Failed sending config data",
                        ddog_sidecar_set_universal_service_tags(&transport, datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id), service_name,
                                                                env_name, version, &DATADOG_G(active_global_tags), ddtrace_dynamic_instrumentation_state(), remote_config_generation));
    }

    tsrm_mutex_unlock(DATADOG_G(sidecar_universal_service_tags_mutex));
}

static ddog_SidecarTransport *dd_sidecar_connect(bool as_worker, bool is_fork) {
    if (!datadog_endpoint) {
        return NULL;
    }
    ZEND_ASSERT(dogstatsd_endpoint != NULL);

    dd_set_endpoint_test_token(dogstatsd_endpoint);

#ifdef _WIN32
    char logpath[MAX_PATH];
    if (!as_worker) {
        DDOG_PHP_FUNCTION = (const uint8_t *)zend_hash_func;
    }
#else
    char logpath[MAXPATHLEN];
#endif
    int error_fd = atomic_load(&datadog_error_log_fd);
    if (error_fd == -1 || datadog_get_fd_path(error_fd, logpath) < 0) {
        *logpath = 0;
    }

    ddog_SidecarTransport *sidecar_transport;
    if (as_worker) {
        if (!datadog_ffi_try("Failed connecting to the sidecar as worker",
                             ddog_sidecar_connect_worker((int32_t)datadog_sidecar_master_pid, &sidecar_transport))) {
#ifdef _WIN32
            int32_t current_pid = (int32_t)GetCurrentProcessId();
#else
            int32_t current_pid = (int32_t)getpid();
#endif
            // If we're an orphaned child, promote this process to master so traces can still be submitted.
            if (current_pid != datadog_sidecar_master_pid) {
                LOG(INFO, "Parent's sidecar listener gone (child PID=%d, master=%d), promoting to master",
                    current_pid, datadog_sidecar_master_pid);
                datadog_sidecar_master_pid = current_pid;
                if (!datadog_ffi_try("Failed starting sidecar master listener as orphaned child",
                        ddog_sidecar_connect_master((int32_t)datadog_sidecar_master_pid)) ||
                    !datadog_ffi_try("Failed connecting to new sidecar master as orphaned child",
                        ddog_sidecar_connect_worker((int32_t)datadog_sidecar_master_pid, &sidecar_transport))) {
                    dd_free_endpoints();
                    return NULL;
                }
            } else {
                LOG(ERROR, "Failed connecting to own sidecar master listener (PID=%d)", current_pid);
                dd_free_endpoints();
                return NULL;
            }
        }
        datadog_sidecar_active_mode = DD_SIDECAR_CONNECTION_THREAD;
    } else {
        if (!datadog_ffi_try("Failed connecting to the sidecar (subprocess mode)",
                ddog_sidecar_connect_php(&sidecar_transport, logpath,
                    dd_zend_string_to_CharSlice(get_global_DD_TRACE_LOG_LEVEL()),
                    get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED(),
                    dd_sidecar_on_reconnect,
                    datadog_endpoint, (uint64_t)get_global_DD_TRACE_SIDECAR_BACKPRESSURE_BYTES(), (uint64_t)get_global_DD_TRACE_SIDECAR_BACKPRESSURE_QUEUE()))) {
            return NULL;
        }
        datadog_sidecar_active_mode = DD_SIDECAR_CONNECTION_SUBPROCESS;
    }

    dd_sidecar_post_connect(&sidecar_transport, is_fork, logpath);

    return sidecar_transport;
}

static void datadog_sidecar_setup_thread_mode() {
#ifndef _WIN32
    int32_t current_pid = (int32_t)getpid();
#else
    int32_t current_pid = (int32_t)GetCurrentProcessId();
#endif
    bool is_child_process = (datadog_sidecar_master_pid != 0 && current_pid != datadog_sidecar_master_pid);

    bool listener_available = ddog_sidecar_is_master_listener_active(datadog_sidecar_master_pid);

    if (is_child_process || listener_available) {
        DATADOG_G(sidecar) = dd_sidecar_connect(true, false);
        if (DATADOG_G(sidecar)) {
            if (is_child_process) {
                LOG(INFO, "Worker connected to sidecar master listener (worker PID=%d, master PID=%d)",
                    (int32_t)current_pid, datadog_sidecar_master_pid);
            }
            return;
        }

        if (!is_child_process) {
            LOG(WARN, "Failed to connect to own master listener (PID=%d)", (int32_t)current_pid);
            return;
        }

        LOG(WARN, "Cannot connect to master sidecar listener from worker (child PID=%d, master PID=%d)",
            (int32_t)current_pid, datadog_sidecar_master_pid);
        return;
    }

    if (!datadog_ffi_try("Failed starting sidecar master listener", ddog_sidecar_connect_master((int32_t)datadog_sidecar_master_pid))) {
        LOG(WARN, "Failed to start sidecar master listener");
        if (datadog_endpoint) {
            dd_free_endpoints();
        }
        return;
    }

    LOG(INFO, "Started sidecar master listener thread (PID=%d)", datadog_sidecar_master_pid);

    DATADOG_G(sidecar) = dd_sidecar_connect(true, false);
    if (!DATADOG_G(sidecar)) {
        LOG(WARN, "Failed to connect master process to sidecar");
    }
}

ddog_SidecarTransport *datadog_sidecar_connect(bool is_fork) {
    if (datadog_sidecar_active_mode == DD_SIDECAR_CONNECTION_SUBPROCESS) {
        return dd_sidecar_connect(false, is_fork);
    } else if (datadog_sidecar_active_mode == DD_SIDECAR_CONNECTION_THREAD) {
        return dd_sidecar_connect(true, is_fork);
    }

    zend_long mode = get_global_DD_TRACE_SIDECAR_CONNECTION_MODE();
    ddog_SidecarTransport *transport = NULL;

    switch (mode) {
    case DD_TRACE_SIDECAR_CONNECTION_MODE_SUBPROCESS:
        // Force subprocess only
        transport = dd_sidecar_connect(false, is_fork);
        if (!transport) {
            LOG(ERROR, "Subprocess connection failed (mode=subprocess, no fallback)");
        }
        break;

    case DD_TRACE_SIDECAR_CONNECTION_MODE_THREAD:
        // Force thread only
        transport = dd_sidecar_connect(true, is_fork);
        if (!transport) {
            LOG(ERROR, "Thread connection failed (mode=thread, no fallback)");
        }
        break;

    case DD_TRACE_SIDECAR_CONNECTION_MODE_AUTO:
    default:
        // Try subprocess first, fallback to thread if needed
        transport = dd_sidecar_connect(false, is_fork);

        if (!transport) {
            if (datadog_endpoint) {
                LOG(WARN, "Subprocess connection failed, falling back to thread mode");
                transport = dd_sidecar_connect(true, is_fork);

                if (transport) {
                    LOG(INFO, "Connected to sidecar via thread (fallback)");
                } else {
                    LOG(ERROR, "Both subprocess and thread connections failed, sidecar unavailable");
                }
            }
        }
        break;
    }

    return transport;
}

static ddog_SidecarTransport *datadog_sidecar_connect_callback(void) {
    return datadog_sidecar_connect(false);
}

static bool datadog_sidecar_configure_appsec(bool *appsec_activation, bool *appsec_config) {
    *appsec_activation = false;
    *appsec_config = false;

    // this must be done in ddtrace rather than ddappsec because
    // the sidecar is launched by ddtrace before ddappsec has a chance
    // to run its first rinit

#if defined(__linux__) || defined(__APPLE__)
    zend_module_entry *appsec_module = zend_hash_str_find_ptr(&module_registry, "ddappsec", sizeof("ddappsec") - 1);
    if (!appsec_module) {
        return false;
    }
    void *handle = dlsym(appsec_module->handle, "dd_appsec_maybe_enable_helper");
    if (!handle) {
        return false;
    }
    bool (*dd_appsec_maybe_enable_helper)(typeof(&ddog_sidecar_enable_appsec), bool *, bool *) = handle;
    return dd_appsec_maybe_enable_helper(ddog_sidecar_enable_appsec, appsec_activation, appsec_config);
#else
    return false;
#endif
}

bool datadog_sidecar_should_enable(ddog_RemoteConfigFlags *flags) {
    bool appsec_activation, appsec_config;
    bool enable_sidecar = datadog_sidecar_configure_appsec(&appsec_activation, &appsec_config);
    flags->appsec_activation = appsec_activation;
    flags->appsec_config = appsec_config;

    enable_sidecar = enable_sidecar || get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED();
#ifdef DDTRACE
    enable_sidecar = ddtrace_update_remote_config_flags(flags) || enable_sidecar;
#endif

    return enable_sidecar;
}

void datadog_sidecar_setup(ddog_RemoteConfigFlags flags) {
    dd_set_non_resettable_sidecar_globals();
    dd_set_resettable_sidecar_globals();

    ddog_init_remote_config(flags);

    zend_long mode = get_global_DD_TRACE_SIDECAR_CONNECTION_MODE();

    if (mode == DD_TRACE_SIDECAR_CONNECTION_MODE_THREAD) {
        datadog_sidecar_setup_thread_mode();
    } else {
        DATADOG_G(sidecar) = dd_sidecar_connect(false, false);

        if (!DATADOG_G(sidecar)) {
            if (mode == DD_TRACE_SIDECAR_CONNECTION_MODE_AUTO && datadog_endpoint) {
                LOG(WARN, "Subprocess connection failed, falling back to thread mode");
                datadog_sidecar_setup_thread_mode();
            } else if (datadog_endpoint) {
                dd_free_endpoints();
            }
        }
    }

    // Record the first established connection for best-effort signal-handler use.
    if (DATADOG_G(sidecar) && !datadog_sidecar_for_signal) {
        datadog_sidecar_for_signal = DATADOG_G(sidecar);
    }
}

void datadog_sidecar_minit(void) {
#ifdef _WIN32
    datadog_sidecar_master_pid = (int32_t)GetCurrentProcessId();
#else
    datadog_sidecar_master_pid = (int32_t)getpid();
#endif

    zend_long mode = get_global_DD_TRACE_SIDECAR_CONNECTION_MODE();

    if (mode == DD_TRACE_SIDECAR_CONNECTION_MODE_THREAD) {
        datadog_ffi_try("Starting sidecar master listener in MINIT",
                       ddog_sidecar_connect_master(datadog_sidecar_master_pid));
    }
}

void datadog_sidecar_handle_fork(void) {
#ifndef _WIN32
    ddog_RemoteConfigFlags flags = {0};
    bool enable_sidecar = datadog_sidecar_should_enable(&flags);

    if (!enable_sidecar) {
        return;
    }

    datadog_force_new_instance_id();

    // After fork only one thread (the one that called fork) survives, so we only
    // need to drop and reconnect the current thread's transport.
    if (DATADOG_G(sidecar)) {
        ddog_sidecar_transport_drop(DATADOG_G(sidecar));
        DATADOG_G(sidecar) = NULL;
    }
    datadog_sidecar_for_signal = NULL;

    if (datadog_sidecar_active_mode == DD_SIDECAR_CONNECTION_THREAD) {
        datadog_ffi_try("Failed clearing inherited listener state",
                        ddog_sidecar_clear_inherited_listener());

        DATADOG_G(sidecar) = dd_sidecar_connect(true, true);
        if (DATADOG_G(sidecar)) {
            LOG(INFO, "Child process reconnected to parent's sidecar listener after fork (child PID=%d, parent=%d)",
                (int32_t)getpid(), datadog_sidecar_master_pid);
        } else {
            LOG(INFO, "Parent's sidecar listener not available after fork (child PID=%d, parent=%d), starting new master",
                (int32_t)getpid(), datadog_sidecar_master_pid);

            datadog_sidecar_master_pid = (int32_t)getpid();
            if (!datadog_ffi_try("Failed starting sidecar master listener in child process",
                    ddog_sidecar_connect_master((int32_t)datadog_sidecar_master_pid))) {
                if (datadog_endpoint) {
                    dd_free_endpoints();
                }
                return;
            }

            DATADOG_G(sidecar) = dd_sidecar_connect(true, false);
            if (!DATADOG_G(sidecar)) {
                LOG(WARN, "Failed to connect to new sidecar master in child process (PID=%d)",
                    (int32_t)getpid());
            }
        }
    } else if (datadog_sidecar_active_mode == DD_SIDECAR_CONNECTION_SUBPROCESS) {
        DATADOG_G(sidecar) = datadog_sidecar_connect(true);
        if (!DATADOG_G(sidecar)) {
            if (datadog_endpoint) {
                dd_free_endpoints();
            }
        }
    }

    if (DATADOG_G(sidecar)) {
        datadog_sidecar_for_signal = DATADOG_G(sidecar);
    }
#endif
}

void datadog_sidecar_ensure_active(void) {
    if (DATADOG_G(sidecar)) {
        datadog_sidecar_reconnect(&DATADOG_G(sidecar), datadog_sidecar_connect_callback);
    } else if (datadog_endpoint) {
        // First RINIT on this thread: the process-level setup already ran (endpoint is
        // set), so establish this thread's own connection now.
        DATADOG_G(sidecar) = datadog_sidecar_connect(false);
        if (DATADOG_G(sidecar) && !datadog_sidecar_for_signal) {
            datadog_sidecar_for_signal = DATADOG_G(sidecar);
        }
    }
}

void datadog_sidecar_finalize(bool clear_id) {
    if (!DATADOG_G(sidecar) || !DATADOG_G(request_initialized)) {
        return;
    }

    if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        datadog_telemetry_finalize();
    }

    tsrm_mutex_lock(DATADOG_G(sidecar_universal_service_tags_mutex));
    ddog_QueueId queue_id = DATADOG_G(sidecar_queue_id);
    DATADOG_G(sidecar_queue_id) = 0;
    tsrm_mutex_unlock(DATADOG_G(sidecar_universal_service_tags_mutex));

    if (clear_id) {
        datadog_ffi_try("Failed removing application from sidecar",
                        ddog_sidecar_application_remove(&DATADOG_G(sidecar), datadog_sidecar_instance_id, &queue_id));
    }
}

void datadog_sidecar_shutdown(void) {
    datadog_sidecar_for_signal = NULL;

    // In thread mode, drop the main thread's connection before shutting down the
    // listener to avoid deadlock.  GSHUTDOWN owns transport cleanup for all other
    // threads; the main thread's GSHUTDOWN runs after MSHUTDOWN on some SAPIs,
    // so we handle it here explicitly for the thread-mode case.
#ifdef _WIN32
    int32_t current_pid = (int32_t)GetCurrentProcessId();
#else
    int32_t current_pid = (int32_t)getpid();
#endif
    if (datadog_sidecar_active_mode == DD_SIDECAR_CONNECTION_THREAD &&
        datadog_sidecar_master_pid != 0 &&
        current_pid == datadog_sidecar_master_pid) {

        if (DATADOG_G(sidecar)) {
            ddog_sidecar_transport_drop(DATADOG_G(sidecar));
            DATADOG_G(sidecar) = NULL;
        }

        datadog_ffi_try("Failed shutting down master listener",
                        ddog_sidecar_shutdown_master_listener());
    }

    // Process-level instance ID (dropped once at MSHUTDOWN, not per-thread).
    if (datadog_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(datadog_sidecar_instance_id);
        datadog_sidecar_instance_id = NULL;
    }

    if (datadog_endpoint) {
        dd_free_endpoints();
    }

    datadog_sidecar_active_mode = DD_SIDECAR_CONNECTION_NONE;
}

void datadog_force_new_instance_id(void) {
    if (datadog_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(datadog_sidecar_instance_id);
        datadog_generate_runtime_id();
        dd_set_resettable_sidecar_globals();
    }
}

ddog_Endpoint *datadog_sidecar_agent_endpoint(void) {
    ddog_Endpoint *agent_endpoint;

    if (get_global_DD_TRACE_AGENTLESS() && ZSTR_LEN(get_global_DD_API_KEY())) {
        agent_endpoint = ddog_endpoint_from_api_key(dd_zend_string_to_CharSlice(get_global_DD_API_KEY()));
    } else {
        char *agent_url = datadog_agent_url();
        agent_endpoint = datadog_parse_agent_url((ddog_CharSlice) {.ptr = agent_url, .len = strlen(agent_url)});
        if (!agent_endpoint) {
            LOG(ERROR, "Invalid DD_TRACE_AGENT_URL: %s. A proper agent URL must be unix:///path/to/agent.sock or http://hostname:port/.", agent_url);
        }
        free(agent_url);
    }

    if (agent_endpoint) {
        dd_set_endpoint_test_token(agent_endpoint);
    }

    return agent_endpoint;
}

void datadog_sidecar_push_tag(ddog_Vec_Tag *vec, ddog_CharSlice key, ddog_CharSlice value) {
    ddog_Vec_Tag_PushResult tag_result = ddog_Vec_Tag_push(vec, key, value);
    if (tag_result.tag == DDOG_VEC_TAG_PUSH_RESULT_ERR) {
        zend_string *msg = dd_CharSlice_to_zend_string(ddog_Error_message(&tag_result.err));
        LOG(WARN, "Failed to push DogStatsD tag: %s", ZSTR_VAL(msg));
        ddog_Error_drop(&tag_result.err);
        zend_string_release(msg);
    }
}

void datadog_sidecar_push_tags(ddog_Vec_Tag *vec, zval *tags) {
    // Global tags (https://github.com/DataDog/php-datadogstatsd/blob/0efdd1c38f6d3dd407efbb899ad1fd2e5cd18085/src/DogStatsd.php#L113-L125)
    zend_string *service_string, *env_string, *version_string;
    ddtrace_span_data *span = ddtrace_active_span();
    datadog_populate_target_data(span, &service_string, &env_string, &version_string);

    if (ZSTR_LEN(env_string) > 0) {
        datadog_sidecar_push_tag(vec, DDOG_CHARSLICE_C("env"), dd_zend_string_to_CharSlice(env_string));
    }
    zend_string_release(env_string);

    if (ZSTR_LEN(service_string) > 0) {
        datadog_sidecar_push_tag(vec, DDOG_CHARSLICE_C("service"), dd_zend_string_to_CharSlice(service_string));
    }
    zend_string_release(service_string);

    if (ZSTR_LEN(version_string) > 0) {
        datadog_sidecar_push_tag(vec, DDOG_CHARSLICE_C("version"), dd_zend_string_to_CharSlice(version_string));
    }
    zend_string_release(version_string);

    if (ZSTR_LEN(get_DD_TRACE_AGENT_TEST_SESSION_TOKEN())) {
        datadog_sidecar_push_tag(vec, DDOG_CHARSLICE_C("x-datadog-test-session-token"), dd_zend_string_to_CharSlice(get_DD_TRACE_AGENT_TEST_SESSION_TOKEN()));
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
        datadog_convert_to_string(&value_str, tag_val);
        datadog_sidecar_push_tag(vec, dd_zend_string_to_CharSlice(key), dd_zend_string_to_CharSlice(Z_STR(value_str)));
        zend_string_release(Z_STR(value_str));
    }
    ZEND_HASH_FOREACH_END();
}

void datadog_sidecar_dogstatsd_count(zend_string *metric, zend_long value, zval *tags) {
    if (!DATADOG_G(sidecar)) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    datadog_sidecar_push_tags(&vec, tags);
    datadog_ffi_try("Failed sending dogstatsd count metric",
                    ddog_sidecar_dogstatsd_count(&DATADOG_G(sidecar), datadog_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void datadog_sidecar_dogstatsd_distribution(zend_string *metric, double value, zval *tags) {
    if (!DATADOG_G(sidecar)) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    datadog_sidecar_push_tags(&vec, tags);
    datadog_ffi_try("Failed sending dogstatsd distribution metric",
                    ddog_sidecar_dogstatsd_distribution(&DATADOG_G(sidecar), datadog_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void datadog_sidecar_dogstatsd_gauge(zend_string *metric, double value, zval *tags) {
    if (!DATADOG_G(sidecar)) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    datadog_sidecar_push_tags(&vec, tags);
    datadog_ffi_try("Failed sending dogstatsd gauge metric",
                    ddog_sidecar_dogstatsd_gauge(&DATADOG_G(sidecar), datadog_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void datadog_sidecar_dogstatsd_histogram(zend_string *metric, double value, zval *tags) {
    if (!DATADOG_G(sidecar)) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    datadog_sidecar_push_tags(&vec, tags);
    datadog_ffi_try("Failed sending dogstatsd histogram metric",
                    ddog_sidecar_dogstatsd_histogram(&DATADOG_G(sidecar), datadog_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void datadog_sidecar_dogstatsd_set(zend_string *metric, zend_long value, zval *tags) {
    if (!DATADOG_G(sidecar)) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    datadog_sidecar_push_tags(&vec, tags);
    datadog_ffi_try("Failed sending dogstatsd set metric",
                    ddog_sidecar_dogstatsd_set(&DATADOG_G(sidecar), datadog_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_sidecar_submit_span_data_direct_defaults(ddog_SidecarTransport **transport, ddtrace_span_data *root) {
    ddtrace_sidecar_submit_span_data_direct(transport, root, get_DD_SERVICE(), get_DD_ENV(), get_DD_VERSION());
}

void ddtrace_sidecar_submit_span_data_direct(ddog_SidecarTransport **transport, ddtrace_span_data *root, zend_string *cfg_service, zend_string *cfg_env, zend_string *cfg_version) {
    if (!*transport) {
        return;
    }
    zend_string *service_string, *env_string, *version_string;
    datadog_populate_target_data_with_defaults(root, &service_string, &env_string, &version_string, cfg_service, cfg_env, cfg_version);

    ddog_CharSlice service_slice = dd_zend_string_to_CharSlice(service_string);
    ddog_CharSlice env_slice = dd_zend_string_to_CharSlice(env_string);
    ddog_CharSlice version_slice = dd_zend_string_to_CharSlice(version_string);

    const ddog_Vec_Tag *process_tags = datadog_process_tags_get_vec();

    bool changed = true;
    if (DATADOG_G(remote_config_state)) {
        changed = ddog_remote_configs_service_env_change(DATADOG_G(remote_config_state), service_slice, env_slice, version_slice, &DATADOG_G(active_global_tags), process_tags);
    }

    // Force resend on reconnect
    if (changed || !root || *transport != DATADOG_G(sidecar)) {
        tsrm_mutex_lock(DATADOG_G(sidecar_universal_service_tags_mutex));
        if (DATADOG_G(last_service_name)) {
            zend_string_release(DATADOG_G(last_service_name));
        }
        DATADOG_G(last_service_name) = service_string;
        if (DATADOG_G(last_env_name)) {
            zend_string_release(DATADOG_G(last_env_name));
        }
        DATADOG_G(last_env_name) = env_string;
        if (DATADOG_G(last_version)) {
            zend_string_release(DATADOG_G(last_version));
        }
        DATADOG_G(last_version) = version_string;
        tsrm_mutex_unlock(DATADOG_G(sidecar_universal_service_tags_mutex));

        // This must not be in mutex, as a reconnect may happen here
        uint64_t remote_config_generation = DATADOG_G(remote_config_state)
            ? ddog_remote_config_current_generation(DATADOG_G(remote_config_state))
            : 0;
        datadog_ffi_try("Failed sending config data",
                        ddog_sidecar_set_universal_service_tags(transport, datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id), service_slice, env_slice, version_slice, &DATADOG_G(active_global_tags), ddtrace_dynamic_instrumentation_state(), remote_config_generation));
    } else {
        zend_string_release(service_string);
        zend_string_release(env_string);
        zend_string_release(version_string);
    }

    if ((changed || !root) && DATADOG_G(telemetry_buffer)) {
        datadog_ffi_try("Failed flushing filtered telemetry buffer",
            ddog_sidecar_telemetry_filter_flush(transport, datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id), datadog_telemetry_buffer(), datadog_telemetry_cache(), service_slice, env_slice));
    }

    if (DATADOG_G(remote_config_state)) {
        // Must happen after ddog_sidecar_set_universal_service_tags (session state fully initialized)
        ddog_process_remote_configs(DATADOG_G(remote_config_state));
    }
}

void datadog_sidecar_activate(void) {
    DATADOG_G(sidecar_queue_id) = ddog_sidecar_queueId_generate();

    DATADOG_G(active_global_tags) = ddog_Vec_Tag_new();
    zend_string *tag;
    zval *value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(get_DD_TAGS(), tag, value) {
        UNUSED(ddog_Vec_Tag_push(&DATADOG_G(active_global_tags), dd_zend_string_to_CharSlice(tag), dd_zend_string_to_CharSlice(Z_STR_P(value))));
    } ZEND_HASH_FOREACH_END();
}

void datadog_sidecar_rinit(void) {
    if (get_DD_TRACE_GIT_METADATA_ENABLED()) {
        zend_string *commit = NULL, *repo = NULL;
        if (datadog_get_git_metadata(&commit, &repo)) {
            if (commit) {
                UNUSED(ddog_Vec_Tag_push(&DATADOG_G(active_global_tags), DDOG_CHARSLICE_C("git.commit.sha"),
                                         dd_zend_string_to_CharSlice(commit)));
            }
            if (repo) {
                UNUSED(ddog_Vec_Tag_push(&DATADOG_G(active_global_tags), DDOG_CHARSLICE_C("git.repository_url"),
                                         dd_zend_string_to_CharSlice(repo)));
            }
        }
    }

    ddtrace_sidecar_submit_span_data_direct_defaults(&DATADOG_G(sidecar), NULL);
}

void datadog_sidecar_rshutdown(void) {
    ddog_Vec_Tag_drop(DATADOG_G(active_global_tags));
}

void datadog_sidecar_gshutdown(zend_datadog_globals *datadog_globals) {
    if (datadog_globals->sidecar) {
        if (datadog_globals->sidecar == datadog_sidecar_for_signal) {
            datadog_sidecar_for_signal = NULL;
        }

        ddog_sidecar_transport_drop(datadog_globals->sidecar);
        datadog_globals->sidecar = NULL;
    }
}

bool datadog_alter_test_session_token(zval *old_value, zval *new_value, zend_string *new_str) {
    UNUSED(old_value, new_str);
    if (datadog_endpoint) {
        ddog_endpoint_set_test_token(datadog_endpoint, dd_zend_string_to_CharSlice(Z_STR_P(new_value)));
    }
    if (DATADOG_G(sidecar)) {
        datadog_ffi_try("Failed updating test session token",
                        ddog_sidecar_set_test_session_token(&DATADOG_G(sidecar), dd_zend_string_to_CharSlice(Z_STR_P(new_value))));
    }
#if !defined(_WIN32) && defined(DDTRACE)
    ddtrace_coms_set_test_session_token(Z_STRVAL_P(new_value), Z_STRLEN_P(new_value));
#endif
    return true;
}

ddog_crasht_Metadata datadog_setup_crashtracking_metadata(ddog_Vec_Tag *tags) {
    datadog_sidecar_push_tags(tags, NULL);

    datadog_sidecar_push_tag(tags, DDOG_CHARSLICE_C("is_crash"), DDOG_CHARSLICE_C("true"));
    datadog_sidecar_push_tag(tags, DDOG_CHARSLICE_C("severity"), DDOG_CHARSLICE_C("crash"));
    datadog_sidecar_push_tag(tags, DDOG_CHARSLICE_C("library_version"), DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION));
    datadog_sidecar_push_tag(tags, DDOG_CHARSLICE_C("language"), DDOG_CHARSLICE_C("php"));
    datadog_sidecar_push_tag(tags, DDOG_CHARSLICE_C("runtime"), DDOG_CHARSLICE_C("php"));
    datadog_sidecar_push_tag(tags, DDOG_CHARSLICE_C("runtime-id"), (ddog_CharSlice) {.ptr = (char *) datadog_formatted_session_id, .len = sizeof(datadog_formatted_session_id)});

    const char *runtime_version = zend_get_module_version("Reflection");
    datadog_sidecar_push_tag(tags, DDOG_CHARSLICE_C("runtime_version"), (ddog_CharSlice) {.ptr = (char *) runtime_version, .len = strlen(runtime_version)});

    zend_string *process_tags = datadog_process_tags_get_serialized();
    if (ZSTR_LEN(process_tags)) {
        datadog_sidecar_push_tag(tags, DDOG_CHARSLICE_C("process_tags"), (ddog_CharSlice) {.ptr = ZSTR_VAL(process_tags), .len = ZSTR_LEN(process_tags)});
    }

    return (ddog_crasht_Metadata){
        .library_name = DDOG_CHARSLICE_C_BARE("dd-trace-php"),
        .library_version = DDOG_CHARSLICE_C_BARE(PHP_DDTRACE_VERSION),
        .family = DDOG_CHARSLICE_C("php"),
        .tags = tags
    };
}
