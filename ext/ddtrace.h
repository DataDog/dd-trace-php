#ifndef DDTRACE_H
#define DDTRACE_H
#include <Zend/zend_types.h>
#include <stdbool.h>
#include <stdint.h>
#include <components-rs/ddtrace.h>

#ifndef _WIN32
#include <dogstatsd_client/client.h>
#endif

#include "ext/version.h"
#include "compatibility.h"

extern zend_module_entry ddtrace_module_entry;
extern zend_class_entry *ddtrace_ce_span_data;
extern zend_class_entry *ddtrace_ce_root_span_data;
extern zend_class_entry *ddtrace_ce_span_stack;
extern zend_class_entry *ddtrace_ce_fatal_error;
extern zend_class_entry *ddtrace_ce_span_link;
extern zend_class_entry *ddtrace_ce_integration;

typedef struct ddtrace_span_ids_t ddtrace_span_ids_t;
typedef struct ddtrace_span_data ddtrace_span_data;
typedef struct ddtrace_root_span_data ddtrace_root_span_data;
typedef struct ddtrace_span_stack ddtrace_span_stack;
typedef struct ddtrace_span_link ddtrace_span_link;

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

bool ddtrace_tracer_is_limited(void);
// prepare the tracer state to start handling a new trace
void dd_prepare_for_new_trace(void);
void ddtrace_disable_tracing_in_current_request(void);
bool ddtrace_alter_dd_trace_disabled_config(zval *old_value, zval *new_value);
bool ddtrace_alter_sampling_rules_file_config(zval *old_value, zval *new_value);
bool ddtrace_alter_default_propagation_style(zval *old_value, zval *new_value);
bool ddtrace_alter_dd_env(zval *old_value, zval *new_value);
bool ddtrace_alter_dd_version(zval *old_value, zval *new_value);
void dd_force_shutdown_tracing(void);

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
#ifndef _WIN32
    dogstatsd_client dogstatsd_client;
#endif
    zend_bool in_shutdown;

    zend_long default_priority_sampling;
    zend_long propagated_priority_sampling;
    ddtrace_span_stack *active_stack; // never NULL except tracer is disabled
    ddtrace_span_stack *top_closed_stack;
    HashTable traced_spans; // tie a span to a specific active execute_data
    uint32_t open_spans_count;
    uint32_t closed_spans_count;
    uint32_t dropped_spans_count;
    int64_t compile_time_microseconds;
    ddtrace_trace_id distributed_trace_id;
    uint64_t distributed_parent_trace_id;
    zend_string *dd_origin;
    zend_reference *curl_multi_injecting_spans;

    char *cgroup_file;
    ddog_QueueId telemetry_queue_id;
    ddog_AgentRemoteConfigReader *remote_config_reader;
    HashTable *agent_rate_by_service;
    zend_string *last_flushed_root_service_name;
    zend_string *last_flushed_root_env_name;

    HashTable uhook_active_hooks;
    HashTable uhook_closure_hooks;
ZEND_END_MODULE_GLOBALS(ddtrace)
// clang-format on

#ifdef ZTS
#  if defined(__has_attribute) && __has_attribute(tls_model)
#    define ATTR_TLS_GLOBAL_DYNAMIC __attribute__((tls_model("global-dynamic")))
#  else
#    define ATTR_TLS_GLOBAL_DYNAMIC
#  endif
extern TSRM_TLS void *ATTR_TLS_GLOBAL_DYNAMIC TSRMLS_CACHE;
#  define DDTRACE_G(v) TSRMG(ddtrace_globals_id, zend_ddtrace_globals *, v)
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

#endif  // DDTRACE_H
