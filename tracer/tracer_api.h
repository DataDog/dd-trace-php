#include <ext/datadog.h>
#include <Zend/zend_extensions.h>

#ifdef DDTRACE
// Primary lifecycle
int ddtrace_startup(void);
void ddtrace_shutdown(void);
void ddtrace_activate_once(void);
void ddtrace_activate_early(void);
void ddtrace_activate_late(void);
void ddtrace_ginit(zend_datadog_globals *ddtrace_globals);
void ddtrace_gshutdown(zend_datadog_globals *datadog_globals);
void ddtrace_pre_config_minit(void);
void ddtrace_minit_early(int module_number);
void ddtrace_minit_late(void);
void ddtrace_mshutdown(void);
void ddtrace_first_rinit(void);
void ddtrace_rinit_early(void);
void ddtrace_rinit(void);
void ddtrace_rshutdown(bool fast_shutdown);
void ddtrace_post_deactivate(void);

// fork handling
void ddtrace_internal_handle_fork(void);
void ddtrace_internal_handle_prefork(void);
void ddtrace_internal_handle_postfork(void);

// telemetry lifecycle stuff
void ddtrace_telemetry_first_init(void);
void ddtrace_telemetry_register_services(ddog_SidecarTransport **sidecar);
void ddtrace_telemetry_finalize(void);

// Other
ddtrace_span_data *ddtrace_active_span(void);
bool ddtrace_update_remote_config_flags(ddog_RemoteConfigFlags *flags);
extern ddog_LiveDebuggerSetup ddtrace_live_debugger_setup;
void ddtrace_live_debugger_rinit(void);
void ddtrace_live_debugger_rshutdown(void);
#endif

// Miscellaneous stuff with fallback functions
#ifdef DDTRACE
ddog_DynamicInstrumentationConfigState ddtrace_dynamic_instrumentation_state(void);
void ddtrace_populate_span_data(ddtrace_span_data *span, zend_string **service, zend_string **env, zend_string **version);
#else
static inline void ddtrace_populate_span_data(ddtrace_span_data *span, zend_string **service, zend_string **env, zend_string **version) {
    *service = NULL;
    *env = NULL;
    *version = NULL;
}

static inline ddog_DynamicInstrumentationConfigState ddtrace_dynamic_instrumentation_state(void) {
    return DDOG_DYNAMIC_INSTRUMENTATION_CONFIG_STATE_DISABLED;
}
#endif
