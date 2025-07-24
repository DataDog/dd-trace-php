#ifndef DDTRACE_H
#define DDTRACE_H
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include <Zend/zend_types.h>
#include <stdbool.h>
#include <stdint.h>
#include <components-rs/ddtrace.h>
#include <components/sapi/sapi.h>

#ifndef _WIN32
#include <dogstatsd_client/client.h>
#endif

#include "ext/version.h"
#include "compatibility.h"
#include "git.h"

extern zend_module_entry ddtrace_module_entry;
extern zend_class_entry *ddtrace_ce_span_data;
extern zend_class_entry *ddtrace_ce_inferred_span_data;
extern zend_class_entry *ddtrace_ce_root_span_data;
extern zend_class_entry *ddtrace_ce_span_stack;
extern zend_class_entry *ddtrace_ce_fatal_error;
extern zend_class_entry *ddtrace_ce_span_link;
extern zend_class_entry *ddtrace_ce_span_event;
extern zend_class_entry *ddtrace_ce_exception_span_event;
extern zend_class_entry *ddtrace_ce_integration;
extern zend_class_entry *ddtrace_ce_git_metadata;

typedef struct ddtrace_span_ids_t ddtrace_span_ids_t;
typedef struct ddtrace_span_data ddtrace_span_data;
typedef struct ddtrace_inferred_span_data ddtrace_inferred_span_data;
typedef struct ddtrace_root_span_data ddtrace_root_span_data;
typedef struct ddtrace_span_stack ddtrace_span_stack;
typedef struct ddtrace_span_link ddtrace_span_link;
typedef struct ddtrace_span_event ddtrace_span_event;
typedef struct ddtrace_exception_span_event ddtrace_exception_span_event;
typedef struct ddtrace_git_metadata ddtrace_git_metadata;

extern datadog_php_sapi ddtrace_active_sapi;

static inline zend_array *ddtrace_property_array(zval *zv) {
    ZVAL_DEREF(zv);
    if (Z_TYPE_P(zv) != IS_ARRAY) {
        zval garbage;
        ZVAL_COPY_VALUE(&garbage, zv);
        array_init(zv);
        zval_ptr_dtor(&garbage);
    }
    SEPARATE_ARRAY(zv);
    return Z_ARR_P(zv);
}

#if defined(COMPILE_DL_DDTRACE) && defined(__GLIBC__) && __GLIBC_MINOR__
#define CXA_THREAD_ATEXIT_WRAPPER 1
#endif

bool ddtrace_tracer_is_limited(void);
// prepare the tracer state to start handling a new trace
void dd_prepare_for_new_trace(void);
void ddtrace_disable_tracing_in_current_request(void);
bool ddtrace_alter_dd_trace_disabled_config(zval *old_value, zval *new_value, zend_string *new_str);
bool ddtrace_alter_sampling_rules_file_config(zval *old_value, zval *new_value, zend_string *new_str);
bool ddtrace_alter_default_propagation_style(zval *old_value, zval *new_value, zend_string *new_str);
bool ddtrace_alter_dd_service(zval *old_value, zval *new_value, zend_string *new_str);
bool ddtrace_alter_dd_env(zval *old_value, zval *new_value, zend_string *new_str);
bool ddtrace_alter_dd_version(zval *old_value, zval *new_value, zend_string *new_str);
void dd_force_shutdown_tracing(void);
void dd_internal_handle_fork(void);
#ifdef CXA_THREAD_ATEXIT_WRAPPER
void dd_run_rust_thread_destructors(void *unused);
#endif

typedef struct {
    int type;
    zend_string *message;
} ddtrace_error_data;

typedef struct {
    uint64_t low;
    union {
        uint64_t high;
        struct {
            ZEND_ENDIAN_LOHI(
                uint32_t padding // zeroes
            ,
                uint32_t time
            )
        };
    };
} ddtrace_trace_id;

// clang-format off
ZEND_BEGIN_MODULE_GLOBALS(ddtrace)
    zend_bool api_is_loaded;
    zend_bool otel_is_loaded;
    zend_bool legacy_tracer_is_loaded;

    uint32_t traces_group_id;
    zend_array *additional_global_tags;
    zend_array root_span_tags_preset;
    zend_array propagated_root_span_tags;
    zend_string *tracestate;
    zend_array tracestate_unknown_dd_keys;
    zend_bool backtrace_handler_already_run;
    ddtrace_error_data active_error;
    HashTable baggage;
#ifndef _WIN32
    dogstatsd_client dogstatsd_client;
#endif
    zend_bool in_shutdown;

#if PHP_VERSION_ID < 70100
    bool zai_vm_interrupt;
#endif
    bool reread_remote_configuration;
    bool root_span_data_submitted;

    zend_long default_priority_sampling;
    zend_long propagated_priority_sampling;
    ddtrace_span_stack *active_stack; // never NULL except tracer is disabled
    ddtrace_span_stack *top_closed_stack;
    HashTable traced_spans; // tie a span to a specific active execute_data
    uint32_t open_spans_count;
    uint32_t baggage_extract_count;
    uint32_t baggage_inject_count;
    uint32_t baggage_malformed_count;
    uint32_t baggage_max_item_count;
    uint32_t baggage_max_byte_count;
    uint32_t closed_spans_count;
    uint32_t dropped_spans_count;
    int64_t compile_time_microseconds;
    ddtrace_trace_id distributed_trace_id;
    uint64_t distributed_parent_trace_id;
    zend_string *dd_origin;
    zend_reference *curl_multi_injecting_spans;

    char *cgroup_file;
    ddog_QueueId sidecar_queue_id;
    ddog_AgentRemoteConfigReader *agent_config_reader;
    ddog_RemoteConfigState *remote_config_state;
    ddog_AgentInfoReader *agent_info_reader;
    zend_arena *debugger_capture_arena;
    HashTable debugger_capture_ephemerals;
    ddog_Vec_DebuggerPayload exception_debugger_buffer;
    HashTable active_rc_hooks;
    HashTable *agent_rate_by_service;
    zend_string *last_flushed_root_service_name;
    zend_string *last_flushed_root_env_name;
    ddog_Vec_Tag active_global_tags;

    bool request_initialized;
    HashTable telemetry_spans_created_per_integration;
    ddog_SidecarActionsBuffer *telemetry_buffer;

    bool asm_event_emitted;

#if PHP_VERSION_ID >= 80000
    HashTable curl_headers;
    // Multi-handle API: curl_multi_*()
    HashTable curl_multi_handles;
#endif

    HashTable uhook_active_hooks;
    HashTable uhook_closure_hooks;

    HashTable git_metadata;
    zend_object *git_object;

    bool inferred_span_created;
ZEND_END_MODULE_GLOBALS(ddtrace)
// clang-format on

#ifdef ZTS
#  if defined(__has_attribute) && __has_attribute(tls_model)
#    define ATTR_TLS_GLOBAL_DYNAMIC __attribute__((tls_model("global-dynamic")))
#  else
#    define ATTR_TLS_GLOBAL_DYNAMIC
#  endif
extern TSRM_TLS void *ATTR_TLS_GLOBAL_DYNAMIC TSRMLS_CACHE;
#  define DDTRACE_G(v) ZEND_TSRMG(ddtrace_globals_id, zend_ddtrace_globals *, v)
#else
#  define DDTRACE_G(v) (ddtrace_globals.v)
#endif

#define PHP_DDTRACE_EXTNAME "ddtrace"
#ifndef PHP_DDTRACE_VERSION
#define PHP_DDTRACE_VERSION "0.0.0-unknown"
#endif

#define DDTRACE_CALLBACK_NAME "dd_trace_callback"

/* The clang formatter does not handle the ZEND macros these mirror, due to the
 * missing comma in the usage site. It was making PRs unreviewable, so this
 * defines these macros without the comma in the definition site, so that it
 * exists at the usage site.
 */
#define DDTRACE_ARG_INFO_SIZE(arg_info) ((uint32_t)(sizeof(arg_info) / sizeof(struct _zend_internal_arg_info) - 1))

#define DDTRACE_FENTRY(zend_name, name, arg_info, flags) \
    { #zend_name, name, arg_info, DDTRACE_ARG_INFO_SIZE(arg_info), flags }
#define DDTRACE_RAW_FENTRY(zend_name, name, arg_info, flags) \
    { zend_name, name, arg_info, DDTRACE_ARG_INFO_SIZE(arg_info), flags }

#define DDTRACE_FE(name, arg_info) DDTRACE_FENTRY(name, zif_##name, arg_info, 0)
#define DDTRACE_NS_FE(name, arg_info) DDTRACE_RAW_FENTRY("DDTrace\\" #name, zif_##name, arg_info, 0)
#define DDTRACE_SUB_NS_FE(ns, name, arg_info) DDTRACE_RAW_FENTRY("DDTrace\\" ns #name, zif_##name, arg_info, 0)
#define DDTRACE_FALIAS(name, alias, arg_info) DDTRACE_RAW_FENTRY(#name, zif_##alias, arg_info, 0)
#define DDTRACE_FE_END ZEND_FE_END

#include "random.h"

#define HOST_V6_FORMAT_STR "http://[%s]:%u"
#define HOST_V4_FORMAT_STR "http://%s:%u"

#endif  // DDTRACE_H
