#include <php.h>
#include <main/SAPI.h>
#include "ddtrace.h"
#include "auto_flush.h"
#include "compat_string.h"
#include "configuration.h"
#include "ddtrace_export.h"
#include "dogstatsd.h"
#include "logging.h"
#include <components-rs/common.h>
#include <components-rs/ddtrace.h>
#include <components-rs/sidecar.h>
#include <zend_string.h>
#include "sidecar.h"
#include "live_debugger.h"
#include "telemetry.h"
#include "process_tags.h"
#include "serializer.h"
#include "remote_config.h"
#include "process_tags.h"
#include "span.h"
#ifndef _WIN32
#include "coms.h"
#endif

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

ddog_Endpoint *ddtrace_endpoint;
ddog_Endpoint *dogstatsd_endpoint; // always set when ddtrace_endpoint is set
struct ddog_InstanceId *ddtrace_sidecar_instance_id;

#define DDTRACE_FFE_EXPOSURE_BUFFER_LIMIT 1000

typedef struct {
    uint64_t timestamp_ms;
    zend_string *flag_key;
    zend_string *subject_id;
    zend_string *subject_attributes_json;
    zend_string *allocation_key;
    zend_string *variant;
} ddtrace_ffe_exposure;

static void ddtrace_ffe_release_exposure(ddtrace_ffe_exposure *exposure) {
    if (exposure->flag_key) {
        zend_string_release(exposure->flag_key);
    }
    if (exposure->subject_id) {
        zend_string_release(exposure->subject_id);
    }
    if (exposure->subject_attributes_json) {
        zend_string_release(exposure->subject_attributes_json);
    }
    if (exposure->allocation_key) {
        zend_string_release(exposure->allocation_key);
    }
    if (exposure->variant) {
        zend_string_release(exposure->variant);
    }
}

void ddtrace_ffe_clear_exposures(void) {
    ddtrace_ffe_exposure *buffer = (ddtrace_ffe_exposure *) DDTRACE_G(ffe_exposure_buffer);
    for (size_t i = 0; i < DDTRACE_G(ffe_exposure_buffer_len); i++) {
        ddtrace_ffe_release_exposure(&buffer[i]);
    }
    if (buffer) {
        efree(buffer);
    }
    DDTRACE_G(ffe_exposure_buffer) = NULL;
    DDTRACE_G(ffe_exposure_buffer_len) = 0;
    DDTRACE_G(ffe_exposure_buffer_cap) = 0;
}

// Best-effort pointer for the signal handler (SIGTERM/SIGINT). Set to the first
// per-thread connection; never cleared until MSHUTDOWN. Not atomic: concurrent
// shutdown is already a best-effort race for signal handlers, so atomicity of
// the pointer load alone would not prevent the underlying use-after-free.
ddog_SidecarTransport *ddtrace_sidecar_for_signal = NULL;

// Connection mode tracking
dd_sidecar_active_mode_t ddtrace_sidecar_active_mode = DD_SIDECAR_CONNECTION_NONE;
int32_t ddtrace_sidecar_master_pid = 0;

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
static void ddtrace_set_non_resettable_sidecar_globals(void) {
    ddtrace_endpoint = ddtrace_sidecar_agent_endpoint();

    if (get_global_DD_TRACE_AGENTLESS() && ZSTR_LEN(get_global_DD_API_KEY())) {
        dogstatsd_endpoint = ddog_endpoint_from_api_key(dd_zend_string_to_CharSlice(get_global_DD_API_KEY()));
    } else {
        char *dogstatsd_url = ddtrace_dogstatsd_url();
        dogstatsd_endpoint = ddog_endpoint_from_url((ddog_CharSlice) {.ptr = dogstatsd_url, .len = strlen(dogstatsd_url)});
        free(dogstatsd_url);
    }
}

// Build the process-level instance ID (one per PHP process, reset after fork).
static void ddtrace_set_resettable_sidecar_globals(void) {
    uint8_t formatted_run_time_id[36];
    ddtrace_format_runtime_id(&formatted_run_time_id);
    ddog_CharSlice runtime_id = (ddog_CharSlice) {.ptr = (char *) formatted_run_time_id, .len = sizeof(formatted_run_time_id)};
    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) ddtrace_formatted_session_id, .len = sizeof(ddtrace_formatted_session_id)};
    ddtrace_sidecar_instance_id = ddog_sidecar_instanceId_build(session_id, runtime_id);
}

static void dd_free_endpoints(void) {
    ddog_endpoint_drop(ddtrace_endpoint);
    ddog_endpoint_drop(dogstatsd_endpoint);
    ddtrace_endpoint = NULL;
    dogstatsd_endpoint = NULL;
}

DDTRACE_PUBLIC const uint8_t *ddtrace_get_formatted_session_id(void) {
    if (ddtrace_is_empty_session_id(ddtrace_formatted_session_id)) {
        return NULL;
    }
    return ddtrace_formatted_session_id;
}

DDTRACE_PUBLIC struct telemetry_rc_info ddtrace_get_telemetry_rc_info(void) {
    struct telemetry_rc_info info = {
        .service_name = DDTRACE_G(last_service_name),
        .env_name = DDTRACE_G(last_env_name),
    };
    if (DDTRACE_G(remote_config_state)) {
        info.rc_path = ddog_remote_config_get_path(DDTRACE_G(remote_config_state));
    }

    return info;
}

DDTRACE_PUBLIC uint64_t ddtrace_get_sidecar_queue_id(void) {
    return DDTRACE_G(sidecar_queue_id);
}

static void dd_sidecar_post_connect(ddog_SidecarTransport **transport, bool is_fork, const char *logpath) {
    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) ddtrace_formatted_session_id, .len = sizeof(ddtrace_formatted_session_id)};
    ddog_CharSlice root_session_id = ddtrace_is_empty_session_id(ddtrace_formatted_root_session_id) ? DDOG_CHARSLICE_C("") : (ddog_CharSlice) {.ptr = (char *) ddtrace_formatted_root_session_id, .len = sizeof(ddtrace_formatted_root_session_id)};
    ddog_CharSlice parent_session_id = ddtrace_is_empty_session_id(ddtrace_formatted_parent_session_id) ? DDOG_CHARSLICE_C("") : (ddog_CharSlice) {.ptr = (char *) ddtrace_formatted_parent_session_id, .len = sizeof(ddtrace_formatted_parent_session_id)};
    const ddog_Vec_Tag *process_tags = ddtrace_process_tags_get_vec();
    ddog_sidecar_session_set_config(transport, session_id, ddtrace_endpoint, dogstatsd_endpoint,
                                    DDOG_CHARSLICE_C("php"),
                                    php_version_rt,
                                    DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION),
                                    get_global_DD_TRACE_AGENT_FLUSH_INTERVAL(),
                                    (int)(get_global_DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS() * 1000),
                                    // for historical reasons in seconds
                                    get_global_DD_TELEMETRY_HEARTBEAT_INTERVAL() * 1000,
                                    // extended heartbeat interval, also in seconds
                                    (uint64_t)get_global_DD_TELEMETRY_EXTENDED_HEARTBEAT_INTERVAL() * 1000,
                                    get_global_DD_TRACE_BUFFER_SIZE(),
                                    get_global_DD_TRACE_AGENT_STACK_BACKLOG() * get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE(),
                                    get_global_DD_TRACE_DEBUG() ? DDOG_CHARSLICE_C("debug") : dd_zend_string_to_CharSlice(get_global_DD_TRACE_LOG_LEVEL()),
                                    (ddog_CharSlice){ .ptr = logpath, .len = strlen(logpath) },
                                    ddtrace_set_all_thread_vm_interrupt,
                                    DDTRACE_REMOTE_CONFIG_PRODUCTS.ptr,
                                    DDTRACE_REMOTE_CONFIG_PRODUCTS.len,
                                    DDTRACE_REMOTE_CONFIG_CAPABILITIES.ptr,
                                    DDTRACE_REMOTE_CONFIG_CAPABILITIES.len,
                                    get_global_DD_REMOTE_CONFIG_ENABLED(),
                                    is_fork,
                                    process_tags,
                                    dd_zend_string_to_CharSlice(get_global_DD_HOSTNAME()),
                                    dd_zend_string_to_CharSlice(get_global_DD_SERVICE()),
                                    root_session_id,
                                    parent_session_id
                                );

    if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddtrace_telemetry_register_services(transport);
    }
}

void ddtrace_sidecar_update_process_tags(void) {
    if (!DDTRACE_G(sidecar)) {
        return;
    }

    const ddog_Vec_Tag *process_tags = ddtrace_process_tags_get_vec();
    if (!process_tags || process_tags->len == 0) {
        return;
    }

    ddog_sidecar_session_set_process_tags(&DDTRACE_G(sidecar), process_tags);
}

static ddog_SidecarTransport *dd_sidecar_connection_factory_ex(bool is_fork);
static ddog_SidecarTransport *dd_sidecar_connection_factory_thread(void);
static void ddtrace_sidecar_setup_thread_mode(bool appsec_activation, bool appsec_config);

static void dd_sidecar_on_reconnect(ddog_SidecarTransport *transport) {
    if (!ddtrace_endpoint || !dogstatsd_endpoint) {
        return;
    }

    char logpath[MAXPATHLEN];
    int error_fd = atomic_load(&ddtrace_error_log_fd);
    if (error_fd == -1 || ddtrace_get_fd_path(error_fd, logpath) < 0) {
        *logpath = 0;
    }

    dd_sidecar_post_connect(&transport, false, logpath);

    tsrm_mutex_lock(DDTRACE_G(sidecar_universal_service_tags_mutex));

    if (DDTRACE_G(sidecar_queue_id) && DDTRACE_G(last_service_name)) {
        ddog_CharSlice service_name = dd_zend_string_to_CharSlice(DDTRACE_G(last_service_name));
        ddog_CharSlice env_name = dd_zend_string_to_CharSlice(DDTRACE_G(last_env_name));
        ddog_CharSlice version = dd_zend_string_to_CharSlice(DDTRACE_G(last_version));
        ddtrace_ffi_try("Failed sending config data",
                        ddog_sidecar_set_universal_service_tags(&transport, ddtrace_sidecar_instance_id, &DDTRACE_G(sidecar_queue_id), service_name,
                                                                env_name, version, &DDTRACE_G(active_global_tags), ddtrace_dynamic_instrumentation_state()));
    }

    tsrm_mutex_unlock(DDTRACE_G(sidecar_universal_service_tags_mutex));
}

static ddog_SidecarTransport *dd_sidecar_connect(bool as_worker, bool is_fork) {
    if (!ddtrace_endpoint) {
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
    int error_fd = atomic_load(&ddtrace_error_log_fd);
    if (error_fd == -1 || ddtrace_get_fd_path(error_fd, logpath) < 0) {
        *logpath = 0;
    }

    ddog_SidecarTransport *sidecar_transport;
    if (as_worker) {
        if (!ddtrace_ffi_try("Failed connecting to the sidecar as worker",
                             ddog_sidecar_connect_worker((int32_t)ddtrace_sidecar_master_pid, &sidecar_transport))) {
#ifdef _WIN32
            int32_t current_pid = (int32_t)GetCurrentProcessId();
#else
            int32_t current_pid = (int32_t)getpid();
#endif
            // If we're an orphaned child, promote this process to master so traces can still be submitted.
            if (current_pid != ddtrace_sidecar_master_pid) {
                LOG(INFO, "Parent's sidecar listener gone (child PID=%d, master=%d), promoting to master",
                    current_pid, ddtrace_sidecar_master_pid);
                ddtrace_sidecar_master_pid = current_pid;
                if (!ddtrace_ffi_try("Failed starting sidecar master listener as orphaned child",
                        ddog_sidecar_connect_master((int32_t)ddtrace_sidecar_master_pid)) ||
                    !ddtrace_ffi_try("Failed connecting to new sidecar master as orphaned child",
                        ddog_sidecar_connect_worker((int32_t)ddtrace_sidecar_master_pid, &sidecar_transport))) {
                    dd_free_endpoints();
                    return NULL;
                }
            } else {
                LOG(ERROR, "Failed connecting to own sidecar master listener (PID=%d)", current_pid);
                dd_free_endpoints();
                return NULL;
            }
        }
        ddtrace_sidecar_active_mode = DD_SIDECAR_CONNECTION_THREAD;
    } else {
        if (!ddtrace_ffi_try("Failed connecting to the sidecar (subprocess mode)",
                ddog_sidecar_connect_php(&sidecar_transport, logpath,
                    dd_zend_string_to_CharSlice(get_global_DD_TRACE_LOG_LEVEL()),
                    get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED(),
                    dd_sidecar_on_reconnect,
                    ddtrace_endpoint, (uint64_t)get_global_DD_TRACE_SIDECAR_BACKPRESSURE_BYTES(), (uint64_t)get_global_DD_TRACE_SIDECAR_BACKPRESSURE_QUEUE()))) {
            return NULL;
        }
        ddtrace_sidecar_active_mode = DD_SIDECAR_CONNECTION_SUBPROCESS;
    }

    dd_sidecar_post_connect(&sidecar_transport, is_fork, logpath);

    return sidecar_transport;
}

static void ddtrace_sidecar_setup_thread_mode(bool appsec_activation, bool appsec_config) {
#ifndef _WIN32
    int32_t current_pid = (int32_t)getpid();
#else
    int32_t current_pid = (int32_t)GetCurrentProcessId();
#endif
    bool is_child_process = (ddtrace_sidecar_master_pid != 0 && current_pid != ddtrace_sidecar_master_pid);

    bool listener_available = ddog_sidecar_is_master_listener_active(ddtrace_sidecar_master_pid);

    if (is_child_process || listener_available) {
        DDTRACE_G(sidecar) = dd_sidecar_connect(true, false);
        if (DDTRACE_G(sidecar)) {
            if (is_child_process) {
                LOG(INFO, "Worker connected to sidecar master listener (worker PID=%d, master PID=%d)",
                    (int32_t)current_pid, ddtrace_sidecar_master_pid);
            }
            return;
        }

        if (!is_child_process) {
            LOG(WARN, "Failed to connect to own master listener (PID=%d)", (int32_t)current_pid);
            return;
        }

        LOG(WARN, "Cannot connect to master sidecar listener from worker (child PID=%d, master PID=%d)",
            (int32_t)current_pid, ddtrace_sidecar_master_pid);
        return;
    }

    if (!ddtrace_ffi_try("Failed starting sidecar master listener", ddog_sidecar_connect_master((int32_t)ddtrace_sidecar_master_pid))) {
        LOG(WARN, "Failed to start sidecar master listener");
        if (ddtrace_endpoint) {
            dd_free_endpoints();
        }
        return;
    }

    LOG(INFO, "Started sidecar master listener thread (PID=%d)", ddtrace_sidecar_master_pid);

    DDTRACE_G(sidecar) = dd_sidecar_connect(true, false);
    if (!DDTRACE_G(sidecar)) {
        LOG(WARN, "Failed to connect master process to sidecar");
        return;
    }

    if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddtrace_telemetry_first_init();
    }
}

ddog_SidecarTransport *ddtrace_sidecar_connect(bool is_fork) {
    if (ddtrace_sidecar_active_mode == DD_SIDECAR_CONNECTION_SUBPROCESS) {
        return dd_sidecar_connect(false, is_fork);
    } else if (ddtrace_sidecar_active_mode == DD_SIDECAR_CONNECTION_THREAD) {
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
            if (ddtrace_endpoint) {
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

static ddog_SidecarTransport *ddtrace_sidecar_connect_callback(void) {
    return ddtrace_sidecar_connect(false);
}

bool ddtrace_sidecar_maybe_enable_appsec(bool *appsec_activation, bool *appsec_config) {
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

bool ddtrace_sidecar_should_enable(bool *appsec_activation, bool *appsec_config) {
    bool enable_sidecar = ddtrace_sidecar_maybe_enable_appsec(appsec_activation, appsec_config);
    if (!enable_sidecar) {
        enable_sidecar = get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED() ||
                       get_global_DD_TRACE_SIDECAR_TRACE_SENDER() ||
                       get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED();
    }
    return enable_sidecar;
}

void ddtrace_sidecar_setup(bool appsec_activation, bool appsec_config) {
    ddtrace_set_non_resettable_sidecar_globals();
    ddtrace_set_resettable_sidecar_globals();

    ddog_init_remote_config((struct ddog_DdogRemoteConfigFlags){
        .live_debugging_enabled = get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED(),
        .appsec_activation = appsec_activation,
        .appsec_config = appsec_config,
        .ffe_enabled = get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED(),
    });

    zend_long mode = get_global_DD_TRACE_SIDECAR_CONNECTION_MODE();

    if (mode == DD_TRACE_SIDECAR_CONNECTION_MODE_THREAD) {
        ddtrace_sidecar_setup_thread_mode(appsec_activation, appsec_config);
    } else {
        DDTRACE_G(sidecar) = dd_sidecar_connect(false, false);

        if (!DDTRACE_G(sidecar)) {
            if (mode == DD_TRACE_SIDECAR_CONNECTION_MODE_AUTO && ddtrace_endpoint) {
                LOG(WARN, "Subprocess connection failed, falling back to thread mode");
                ddtrace_sidecar_setup_thread_mode(appsec_activation, appsec_config);
            } else if (ddtrace_endpoint) {
                dd_free_endpoints();
            }
        } else if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
            ddtrace_telemetry_first_init();
        }
    }

    // Record the first established connection for best-effort signal-handler use.
    if (DDTRACE_G(sidecar) && !ddtrace_sidecar_for_signal) {
        ddtrace_sidecar_for_signal = DDTRACE_G(sidecar);
    }
}

void ddtrace_sidecar_minit(void) {
#ifdef _WIN32
    ddtrace_sidecar_master_pid = (int32_t)GetCurrentProcessId();
#else
    ddtrace_sidecar_master_pid = (int32_t)getpid();
#endif

    zend_long mode = get_global_DD_TRACE_SIDECAR_CONNECTION_MODE();

    if (mode == DD_TRACE_SIDECAR_CONNECTION_MODE_THREAD) {
        ddtrace_ffi_try("Starting sidecar master listener in MINIT",
                       ddog_sidecar_connect_master(ddtrace_sidecar_master_pid));
    }
}

void ddtrace_sidecar_handle_fork(void) {
#ifndef _WIN32
    bool appsec_activation = false;
    bool appsec_config = false;
    bool enable_sidecar = ddtrace_sidecar_should_enable(&appsec_activation, &appsec_config);

    if (!enable_sidecar) {
        return;
    }

    ddtrace_force_new_instance_id();

    // After fork only one thread (the one that called fork) survives, so we only
    // need to drop and reconnect the current thread's transport.
    if (DDTRACE_G(sidecar)) {
        ddog_sidecar_transport_drop(DDTRACE_G(sidecar));
        DDTRACE_G(sidecar) = NULL;
    }
    ddtrace_sidecar_for_signal = NULL;

    if (ddtrace_sidecar_active_mode == DD_SIDECAR_CONNECTION_THREAD) {
        ddtrace_ffi_try("Failed clearing inherited listener state",
                        ddog_sidecar_clear_inherited_listener());

        DDTRACE_G(sidecar) = dd_sidecar_connect(true, true);
        if (DDTRACE_G(sidecar)) {
            LOG(INFO, "Child process reconnected to parent's sidecar listener after fork (child PID=%d, parent=%d)",
                (int32_t)getpid(), ddtrace_sidecar_master_pid);
        } else {
            LOG(INFO, "Parent's sidecar listener not available after fork (child PID=%d, parent=%d), starting new master",
                (int32_t)getpid(), ddtrace_sidecar_master_pid);

            ddtrace_sidecar_master_pid = (int32_t)getpid();
            if (!ddtrace_ffi_try("Failed starting sidecar master listener in child process",
                    ddog_sidecar_connect_master((int32_t)ddtrace_sidecar_master_pid))) {
                if (ddtrace_endpoint) {
                    dd_free_endpoints();
                }
                return;
            }

            DDTRACE_G(sidecar) = dd_sidecar_connect(true, false);
            if (!DDTRACE_G(sidecar)) {
                LOG(WARN, "Failed to connect to new sidecar master in child process (PID=%d)",
                    (int32_t)getpid());
            }
        }
    } else if (ddtrace_sidecar_active_mode == DD_SIDECAR_CONNECTION_SUBPROCESS) {
        DDTRACE_G(sidecar) = ddtrace_sidecar_connect(true);
        if (!DDTRACE_G(sidecar)) {
            if (ddtrace_endpoint) {
                dd_free_endpoints();
            }
        } else {
            ddtrace_sidecar_submit_root_span_data();
        }
    }

    if (DDTRACE_G(sidecar)) {
        ddtrace_sidecar_for_signal = DDTRACE_G(sidecar);
    }
#endif
}

void ddtrace_sidecar_ensure_active(void) {
    if (DDTRACE_G(sidecar)) {
        ddtrace_sidecar_reconnect(&DDTRACE_G(sidecar), ddtrace_sidecar_connect_callback);
    } else if (ddtrace_endpoint) {
        // First RINIT on this thread: the process-level setup already ran (endpoint is
        // set), so establish this thread's own connection now.
        DDTRACE_G(sidecar) = ddtrace_sidecar_connect(false);
        if (DDTRACE_G(sidecar) && !ddtrace_sidecar_for_signal) {
            ddtrace_sidecar_for_signal = DDTRACE_G(sidecar);
        }
    }
}

void ddtrace_sidecar_finalize(bool clear_id) {
    if (!DDTRACE_G(sidecar)) {
        return;
    }

    if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddtrace_telemetry_finalize();
    }

    tsrm_mutex_lock(DDTRACE_G(sidecar_universal_service_tags_mutex));
    ddog_QueueId queue_id = DDTRACE_G(sidecar_queue_id);
    DDTRACE_G(sidecar_queue_id) = 0;
    tsrm_mutex_unlock(DDTRACE_G(sidecar_universal_service_tags_mutex));

    if (clear_id) {
        ddtrace_ffi_try("Failed removing application from sidecar",
                        ddog_sidecar_application_remove(&DDTRACE_G(sidecar), ddtrace_sidecar_instance_id, &queue_id));
    }
}

void ddtrace_sidecar_shutdown(void) {
    ddtrace_sidecar_for_signal = NULL;

    // In thread mode, drop the main thread's connection before shutting down the
    // listener to avoid deadlock.  GSHUTDOWN owns transport cleanup for all other
    // threads; the main thread's GSHUTDOWN runs after MSHUTDOWN on some SAPIs,
    // so we handle it here explicitly for the thread-mode case.
#ifdef _WIN32
    int32_t current_pid = (int32_t)GetCurrentProcessId();
#else
    int32_t current_pid = (int32_t)getpid();
#endif
    if (ddtrace_sidecar_active_mode == DD_SIDECAR_CONNECTION_THREAD &&
        ddtrace_sidecar_master_pid != 0 &&
        current_pid == ddtrace_sidecar_master_pid) {

        if (DDTRACE_G(sidecar)) {
            ddog_sidecar_transport_drop(DDTRACE_G(sidecar));
            DDTRACE_G(sidecar) = NULL;
        }

        ddtrace_ffi_try("Failed shutting down master listener",
                        ddog_sidecar_shutdown_master_listener());
    }

    // Process-level instance ID (dropped once at MSHUTDOWN, not per-thread).
    if (ddtrace_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(ddtrace_sidecar_instance_id);
        ddtrace_sidecar_instance_id = NULL;
    }

    if (ddtrace_endpoint) {
        dd_free_endpoints();
    }

    ddtrace_sidecar_active_mode = DD_SIDECAR_CONNECTION_NONE;
}

void ddtrace_force_new_instance_id(void) {
    if (ddtrace_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(ddtrace_sidecar_instance_id);
        ddtrace_set_resettable_sidecar_globals();
    }
}

ddog_Endpoint *ddtrace_sidecar_agent_endpoint(void) {
    ddog_Endpoint *agent_endpoint;

    if (get_global_DD_TRACE_AGENTLESS() && ZSTR_LEN(get_global_DD_API_KEY())) {
        agent_endpoint = ddog_endpoint_from_api_key(dd_zend_string_to_CharSlice(get_global_DD_API_KEY()));
    } else {
        char *agent_url = ddtrace_agent_url();
        agent_endpoint = ddtrace_parse_agent_url((ddog_CharSlice) {.ptr = agent_url, .len = strlen(agent_url)});
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

void ddtrace_sidecar_push_tag(ddog_Vec_Tag *vec, ddog_CharSlice key, ddog_CharSlice value) {
    ddog_Vec_Tag_PushResult tag_result = ddog_Vec_Tag_push(vec, key, value);
    if (tag_result.tag == DDOG_VEC_TAG_PUSH_RESULT_ERR) {
        zend_string *msg = dd_CharSlice_to_zend_string(ddog_Error_message(&tag_result.err));
        LOG(WARN, "Failed to push DogStatsD tag: %s", ZSTR_VAL(msg));
        ddog_Error_drop(&tag_result.err);
        zend_string_release(msg);
    }
}

void ddtrace_sidecar_push_tags(ddog_Vec_Tag *vec, zval *tags) {
    // Global tags (https://github.com/DataDog/php-datadogstatsd/blob/0efdd1c38f6d3dd407efbb899ad1fd2e5cd18085/src/DogStatsd.php#L113-L125)
    ddtrace_span_data *span = ddtrace_active_span();
    zend_string *env;
    if (span) {
        env = ddtrace_convert_to_str(&span->property_env);
    } else {
        env = zend_string_copy(get_DD_ENV());
    }
    if (ZSTR_LEN(env) > 0) {
        ddtrace_sidecar_push_tag(vec, DDOG_CHARSLICE_C("env"), dd_zend_string_to_CharSlice(env));
    }
    zend_string_release(env);
    zend_string *service = ddtrace_active_service_name();
    if (ZSTR_LEN(service) > 0) {
        ddtrace_sidecar_push_tag(vec, DDOG_CHARSLICE_C("service"), dd_zend_string_to_CharSlice(service));
    }
    zend_string_release(service);
    zend_string *version;
    if (span) {
        version = ddtrace_convert_to_str(&span->property_version);
    } else {
        version = zend_string_copy(get_DD_VERSION());
    }
    if (ZSTR_LEN(version) > 0) {
        ddtrace_sidecar_push_tag(vec, DDOG_CHARSLICE_C("version"), dd_zend_string_to_CharSlice(version));
    }
    zend_string_release(version);

    if (ZSTR_LEN(get_DD_TRACE_AGENT_TEST_SESSION_TOKEN())) {
        ddtrace_sidecar_push_tag(vec, DDOG_CHARSLICE_C("x-datadog-test-session-token"), dd_zend_string_to_CharSlice(get_DD_TRACE_AGENT_TEST_SESSION_TOKEN()));
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
        ddtrace_sidecar_push_tag(vec, dd_zend_string_to_CharSlice(key), dd_zend_string_to_CharSlice(Z_STR(value_str)));
        zend_string_release(Z_STR(value_str));
    }
    ZEND_HASH_FOREACH_END();
}

void ddtrace_sidecar_dogstatsd_count(zend_string *metric, zend_long value, zval *tags) {
    if (!DDTRACE_G(sidecar) || !get_DD_INTEGRATION_METRICS_ENABLED()) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_push_tags(&vec, tags);
    ddtrace_ffi_try("Failed sending dogstatsd count metric",
                    ddog_sidecar_dogstatsd_count(&DDTRACE_G(sidecar), ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_ffe_record_exposure(
    const char *flag_key,
    size_t flag_key_len,
    const char *targeting_key,
    size_t targeting_key_len,
    zend_string *subject_attributes_json,
    const char *allocation_key,
    const char *variant
) {
    if (!flag_key || flag_key_len == 0 || !allocation_key || !variant || variant[0] == '\0') {
        return;
    }

    if (DDTRACE_G(ffe_exposure_buffer_len) >= DDTRACE_FFE_EXPOSURE_BUFFER_LIMIT) {
        return;
    }

    if (DDTRACE_G(ffe_exposure_buffer_len) == DDTRACE_G(ffe_exposure_buffer_cap)) {
        size_t new_cap = DDTRACE_G(ffe_exposure_buffer_cap) == 0 ? 8 : DDTRACE_G(ffe_exposure_buffer_cap) * 2;
        if (new_cap > DDTRACE_FFE_EXPOSURE_BUFFER_LIMIT) {
            new_cap = DDTRACE_FFE_EXPOSURE_BUFFER_LIMIT;
        }
        DDTRACE_G(ffe_exposure_buffer) = safe_erealloc(
            DDTRACE_G(ffe_exposure_buffer),
            new_cap,
            sizeof(ddtrace_ffe_exposure),
            0
        );
        DDTRACE_G(ffe_exposure_buffer_cap) = new_cap;
    }

    ddtrace_ffe_exposure *buffer = (ddtrace_ffe_exposure *) DDTRACE_G(ffe_exposure_buffer);
    ddtrace_ffe_exposure *exposure = &buffer[DDTRACE_G(ffe_exposure_buffer_len)++];
    exposure->timestamp_ms = ddtrace_nanoseconds_realtime() / 1000000;
    exposure->flag_key = zend_string_init(flag_key, flag_key_len, 0);
    exposure->subject_id = zend_string_init(targeting_key ? targeting_key : "", targeting_key ? targeting_key_len : 0, 0);
    exposure->subject_attributes_json = subject_attributes_json ? zend_string_copy(subject_attributes_json) : zend_string_init("{}", sizeof("{}") - 1, 0);
    exposure->allocation_key = zend_string_init(allocation_key, strlen(allocation_key), 0);
    exposure->variant = zend_string_init(variant, strlen(variant), 0);
}

bool ddtrace_ffe_flush_exposures(void) {
    size_t exposure_count = DDTRACE_G(ffe_exposure_buffer_len);
    ddtrace_ffe_exposure *buffer = (ddtrace_ffe_exposure *) DDTRACE_G(ffe_exposure_buffer);

    if (exposure_count == 0 || !buffer) {
        return false;
    }

    if (!DDTRACE_G(sidecar) || !ddtrace_sidecar_instance_id || !DDTRACE_G(sidecar_queue_id)) {
        ddtrace_ffe_clear_exposures();
        return false;
    }

    ddog_FfeExposure *ffi_exposures = safe_emalloc(exposure_count, sizeof(ddog_FfeExposure), 0);
    for (size_t i = 0; i < exposure_count; i++) {
        ffi_exposures[i] = (ddog_FfeExposure) {
            .timestamp_ms = buffer[i].timestamp_ms,
            .flag_key = dd_zend_string_to_CharSlice(buffer[i].flag_key),
            .subject_id = dd_zend_string_to_CharSlice(buffer[i].subject_id),
            .subject_attributes_json = dd_zend_string_to_CharSlice(buffer[i].subject_attributes_json),
            .allocation_key = dd_zend_string_to_CharSlice(buffer[i].allocation_key),
            .variant = dd_zend_string_to_CharSlice(buffer[i].variant),
        };
    }

    ddog_FfeTelemetryContext context = {
        .service = dd_zend_string_to_CharSlice(get_DD_SERVICE()),
        .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
        .version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
    };
    ddog_Slice_FfeExposure exposure_slice = {
        .ptr = ffi_exposures,
        .len = exposure_count,
    };

    bool flushed = ddtrace_ffi_try(
        "Failed sending FFE exposure batch to sidecar",
        ddog_sidecar_send_ffe_exposure_batch(
            &DDTRACE_G(sidecar),
            ddtrace_sidecar_instance_id,
            &DDTRACE_G(sidecar_queue_id),
            &context,
            exposure_slice));

    efree(ffi_exposures);
    ddtrace_ffe_clear_exposures();
    return flushed;
}

void ddtrace_sidecar_dogstatsd_distribution(zend_string *metric, double value, zval *tags) {
    if (!DDTRACE_G(sidecar) || !get_DD_INTEGRATION_METRICS_ENABLED()) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_push_tags(&vec, tags);
    ddtrace_ffi_try("Failed sending dogstatsd distribution metric",
                    ddog_sidecar_dogstatsd_distribution(&DDTRACE_G(sidecar), ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_sidecar_dogstatsd_gauge(zend_string *metric, double value, zval *tags) {
    if (!DDTRACE_G(sidecar) || !get_DD_INTEGRATION_METRICS_ENABLED()) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_push_tags(&vec, tags);
    ddtrace_ffi_try("Failed sending dogstatsd gauge metric",
                    ddog_sidecar_dogstatsd_gauge(&DDTRACE_G(sidecar), ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_sidecar_dogstatsd_histogram(zend_string *metric, double value, zval *tags) {
    if (!DDTRACE_G(sidecar) || !get_DD_INTEGRATION_METRICS_ENABLED()) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_push_tags(&vec, tags);
    ddtrace_ffi_try("Failed sending dogstatsd histogram metric",
                    ddog_sidecar_dogstatsd_histogram(&DDTRACE_G(sidecar), ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_sidecar_dogstatsd_set(zend_string *metric, zend_long value, zval *tags) {
    if (!DDTRACE_G(sidecar) || !get_DD_INTEGRATION_METRICS_ENABLED()) {
        return;
    }

    ddog_Vec_Tag vec = ddog_Vec_Tag_new();
    ddtrace_sidecar_push_tags(&vec, tags);
    ddtrace_ffi_try("Failed sending dogstatsd set metric",
                    ddog_sidecar_dogstatsd_set(&DDTRACE_G(sidecar), ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value, &vec));
    ddog_Vec_Tag_drop(vec);
}

void ddtrace_sidecar_submit_root_span_data_direct_defaults(ddog_SidecarTransport **transport, ddtrace_root_span_data *root) {
    ddtrace_sidecar_submit_root_span_data_direct(transport, root, get_DD_SERVICE(), get_DD_ENV(), get_DD_VERSION());
}

void ddtrace_sidecar_submit_root_span_data_direct(ddog_SidecarTransport **transport, ddtrace_root_span_data *root, zend_string *cfg_service, zend_string *cfg_env, zend_string *cfg_version) {
    if (!*transport) {
        return;
    }

    zend_string *service_string;
    if (root) {
        zval *service = &root->property_service;
        if (Z_TYPE_P(service) == IS_STRING && Z_STRLEN_P(service) > 0) {
            service_string = zend_string_copy(Z_STR_P(service));
        } else {
            service_string = zend_string_init(ZEND_STRL("unnamed-php-service"), 0);
        }
    } else if (ZSTR_LEN(cfg_service)) {
        service_string = zend_string_copy(cfg_service);
    } else {
        service_string = ddtrace_default_service_name();
    }
    ddog_CharSlice service_slice = dd_zend_string_to_CharSlice(service_string);

    zend_string *env_string = NULL;
    if (root) {
        zval *env = zend_hash_str_find(ddtrace_property_array(&root->property_meta), ZEND_STRL("env"));
        if (!env) {
            env = &root->property_env;
        }
        if (Z_TYPE_P(env) == IS_STRING && Z_STRLEN_P(env) > 0) {
            env_string = zend_string_copy(Z_STR_P(env));
        }
    } else if (ZSTR_LEN(cfg_env)) {
        env_string = zend_string_copy(cfg_env);
    }
    if (!env_string) {
        env_string = zend_string_init(ZEND_STRL("none"), 0);
    }
    ddog_CharSlice env_slice = dd_zend_string_to_CharSlice(env_string);

    zend_string *version_string = NULL;
    if (root) {
        zval *version = zend_hash_str_find(ddtrace_property_array(&root->property_meta), ZEND_STRL("version"));
        if (!version) {
            version = &root->property_version;
        }
        if (version && Z_TYPE_P(version) == IS_STRING && Z_STRLEN_P(version) > 0) {
            version_string = zend_string_copy(Z_STR_P(version));
        }
    } else if (ZSTR_LEN(cfg_version)) {
        version_string = zend_string_copy(cfg_version);
    }
    if (!version_string) {
        version_string = ZSTR_EMPTY_ALLOC();
    }
    ddog_CharSlice version_slice = dd_zend_string_to_CharSlice(version_string);

    const ddog_Vec_Tag *process_tags = ddtrace_process_tags_get_vec();

    bool changed = true;
    if (DDTRACE_G(remote_config_state)) {
        changed = ddog_remote_configs_service_env_change(DDTRACE_G(remote_config_state), service_slice, env_slice, version_slice, &DDTRACE_G(active_global_tags), process_tags);
    }

    // Force resend on reconnect
    if (changed || !root || *transport != DDTRACE_G(sidecar)) {
        tsrm_mutex_lock(DDTRACE_G(sidecar_universal_service_tags_mutex));
        if (DDTRACE_G(last_service_name)) {
            zend_string_release(DDTRACE_G(last_service_name));
        }
        DDTRACE_G(last_service_name) = service_string;
        if (DDTRACE_G(last_env_name)) {
            zend_string_release(DDTRACE_G(last_env_name));
        }
        DDTRACE_G(last_env_name) = env_string;
        if (DDTRACE_G(last_version)) {
            zend_string_release(DDTRACE_G(last_version));
        }
        DDTRACE_G(last_version) = version_string;
        tsrm_mutex_unlock(DDTRACE_G(sidecar_universal_service_tags_mutex));

        // This must not be in mutex, as a reconnect may happen here
        ddtrace_ffi_try("Failed sending config data",
                        ddog_sidecar_set_universal_service_tags(transport, ddtrace_sidecar_instance_id, &DDTRACE_G(sidecar_queue_id), service_slice, env_slice, version_slice, &DDTRACE_G(active_global_tags), ddtrace_dynamic_instrumentation_state()));
    } else {
        zend_string_release(service_string);
        zend_string_release(env_string);
        zend_string_release(version_string);
    }

    if ((changed || !root) && DDTRACE_G(telemetry_buffer)) {
        ddtrace_ffi_try("Failed flushing filtered telemetry buffer",
            ddog_sidecar_telemetry_filter_flush(transport, ddtrace_sidecar_instance_id, &DDTRACE_G(sidecar_queue_id), ddtrace_telemetry_buffer(), ddtrace_telemetry_cache(), service_slice, env_slice));
    }

    if (DDTRACE_G(remote_config_state)) {
        // Must happen after ddog_sidecar_set_universal_service_tags (session state fully initialized)
        ddog_process_remote_configs(DDTRACE_G(remote_config_state));
    }
}

void ddtrace_sidecar_submit_root_span_data(void) {
    if (DDTRACE_G(active_stack)) {
        ddtrace_root_span_data *root = DDTRACE_G(active_stack)->root_span;
        if (root) {
            ddtrace_sidecar_submit_root_span_data_direct_defaults(&DDTRACE_G(sidecar), root);
        }
    }
}

void ddtrace_sidecar_send_debugger_data(ddog_Vec_DebuggerPayload payloads) {
    LOGEV(DEBUG, UNUSED(log); ddog_log_debugger_data(&payloads););
    ddog_sidecar_send_debugger_data(&DDTRACE_G(sidecar), ddtrace_sidecar_instance_id, DDTRACE_G(sidecar_queue_id), payloads);
}

void ddtrace_sidecar_send_debugger_datum(ddog_DebuggerPayload *payload) {
    LOGEV(DEBUG, UNUSED(log); ddog_log_debugger_datum(payload););
    ddog_sidecar_send_debugger_datum(&DDTRACE_G(sidecar), ddtrace_sidecar_instance_id, DDTRACE_G(sidecar_queue_id), payload);
}

void ddtrace_sidecar_activate(void) {
    DDTRACE_G(sidecar_queue_id) = ddog_sidecar_queueId_generate();

    DDTRACE_G(active_global_tags) = ddog_Vec_Tag_new();
    zend_string *tag;
    zval *value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(get_DD_TAGS(), tag, value) {
        UNUSED(ddog_Vec_Tag_push(&DDTRACE_G(active_global_tags), dd_zend_string_to_CharSlice(tag), dd_zend_string_to_CharSlice(Z_STR_P(value))));
    } ZEND_HASH_FOREACH_END();
}

void ddtrace_sidecar_rinit(void) {
    if (get_DD_TRACE_GIT_METADATA_ENABLED()) {
        zval git_object;
        ZVAL_UNDEF(&git_object);
        ddtrace_inject_git_metadata(&git_object);
        if (Z_TYPE(git_object) == IS_OBJECT) {
            ddtrace_git_metadata *git_metadata = (ddtrace_git_metadata *) Z_OBJ(git_object);
            if (Z_TYPE(git_metadata->property_commit) == IS_STRING) {
                UNUSED(ddog_Vec_Tag_push(&DDTRACE_G(active_global_tags), DDOG_CHARSLICE_C("git.commit.sha"),
                                         dd_zend_string_to_CharSlice(Z_STR(git_metadata->property_commit))));
            }
            if (Z_TYPE(git_metadata->property_repository) == IS_STRING) {
                UNUSED(ddog_Vec_Tag_push(&DDTRACE_G(active_global_tags), DDOG_CHARSLICE_C("git.repository_url"),
                                         dd_zend_string_to_CharSlice(Z_STR(git_metadata->property_repository))));
            }
            OBJ_RELEASE(&git_metadata->std);
        }
    }

    ddtrace_sidecar_submit_root_span_data_direct_defaults(&DDTRACE_G(sidecar), NULL);
}

void ddtrace_sidecar_rshutdown(void) {
    ddog_Vec_Tag_drop(DDTRACE_G(active_global_tags));
}

void ddtrace_sidecar_gshutdown(zend_ddtrace_globals *ddtrace_globals) {
    // NOTE: do not use DDTRACE_G() in this function; it may be called from the
    // main thread via ts_free_id()
    if (ddtrace_globals->sidecar) {
        if (ddtrace_globals->sidecar == ddtrace_sidecar_for_signal) {
            ddtrace_sidecar_for_signal = NULL;
        }

        // Drain any accumulated background-sender metrics before the transport goes away.
        ddtrace_telemetry_flush_bgs_metrics_final(ddtrace_globals);
        ddog_sidecar_transport_drop(ddtrace_globals->sidecar);
        ddtrace_globals->sidecar = NULL;
    }
}

bool ddtrace_alter_test_session_token(zval *old_value, zval *new_value, zend_string *new_str) {
    UNUSED(old_value, new_str);
    if (DDTRACE_G(sidecar)) {
        ddog_endpoint_set_test_token(ddtrace_endpoint, dd_zend_string_to_CharSlice(Z_STR_P(new_value)));
        ddtrace_ffi_try("Failed updating test session token",
                        ddog_sidecar_set_test_session_token(&DDTRACE_G(sidecar), dd_zend_string_to_CharSlice(Z_STR_P(new_value))));
    }
#ifndef _WIN32
    ddtrace_coms_set_test_session_token(Z_STRVAL_P(new_value), Z_STRLEN_P(new_value));
#endif
    return true;
}

bool ddtrace_exception_debugging_is_active(void) {
    return DDTRACE_G(sidecar) && ddtrace_sidecar_instance_id && get_DD_EXCEPTION_REPLAY_ENABLED();
}

ddog_crasht_Metadata ddtrace_setup_crashtracking_metadata(ddog_Vec_Tag *tags) {
    ddtrace_sidecar_push_tags(tags, NULL);

    ddtrace_sidecar_push_tag(tags, DDOG_CHARSLICE_C("is_crash"), DDOG_CHARSLICE_C("true"));
    ddtrace_sidecar_push_tag(tags, DDOG_CHARSLICE_C("severity"), DDOG_CHARSLICE_C("crash"));
    ddtrace_sidecar_push_tag(tags, DDOG_CHARSLICE_C("library_version"), DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION));
    ddtrace_sidecar_push_tag(tags, DDOG_CHARSLICE_C("language"), DDOG_CHARSLICE_C("php"));
    ddtrace_sidecar_push_tag(tags, DDOG_CHARSLICE_C("runtime"), DDOG_CHARSLICE_C("php"));
    ddtrace_sidecar_push_tag(tags, DDOG_CHARSLICE_C("runtime-id"), (ddog_CharSlice) {.ptr = (char *) ddtrace_formatted_session_id, .len = sizeof(ddtrace_formatted_session_id)});

    const char *runtime_version = zend_get_module_version("Reflection");
    ddtrace_sidecar_push_tag(tags, DDOG_CHARSLICE_C("runtime_version"), (ddog_CharSlice) {.ptr = (char *) runtime_version, .len = strlen(runtime_version)});

    zend_string *process_tags = ddtrace_process_tags_get_serialized();
    if (ZSTR_LEN(process_tags)) {
        ddtrace_sidecar_push_tag(tags, DDOG_CHARSLICE_C("process_tags"), (ddog_CharSlice) {.ptr = ZSTR_VAL(process_tags), .len = ZSTR_LEN(process_tags)});
    }

    return (ddog_crasht_Metadata){
        .library_name = DDOG_CHARSLICE_C_BARE("dd-trace-php"),
        .library_version = DDOG_CHARSLICE_C_BARE(PHP_DDTRACE_VERSION),
        .family = DDOG_CHARSLICE_C("php"),
        .tags = tags
    };
}
