#ifndef DDTRACE_H
#define DDTRACE_H
#ifndef _WIN32
#include <dogstatsd_client/client.h>
#endif

#include <ext/datadog.h>

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
typedef struct datadog_git_metadata datadog_git_metadata;

typedef struct dd_refcounted_linked dd_refcounted_linked;

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

extern bool dd_rinit_once_done;

bool ddtrace_tracer_is_limited(void);
// prepare the tracer state to start handling a new trace
void dd_prepare_for_new_trace(void);
void datadog_disable_tracing_in_current_request(void);
bool datadog_alter_dd_trace_disabled_config(zval *old_value, zval *new_value, zend_string *new_str);
bool ddtrace_alter_sampling_rules_file_config(zval *old_value, zval *new_value, zend_string *new_str);
bool ddtrace_alter_default_propagation_style(zval *old_value, zval *new_value, zend_string *new_str);
bool datadog_alter_dd_service(zval *old_value, zval *new_value, zend_string *new_str);
bool datadog_alter_dd_env(zval *old_value, zval *new_value, zend_string *new_str);
bool datadog_alter_dd_version(zval *old_value, zval *new_value, zend_string *new_str);
void dd_force_shutdown_tracing(bool fast_shutdown);
void ddtrace_internal_handle_fork(void);

#endif  // DDTRACE_H
