#ifndef DDTRACE_H
#define DDTRACE_H
#include <dogstatsd_client/client.h>
#include <stdbool.h>
#include <stdint.h>

#include "env_config.h"
#include "ext/version.h"
#include "random.h"

extern zend_module_entry ddtrace_module_entry;
extern zend_class_entry *ddtrace_ce_span_data;
extern zend_class_entry *ddtrace_ce_fatal_error;

typedef struct ddtrace_span_ids_t ddtrace_span_ids_t;
typedef struct ddtrace_span_fci ddtrace_span_fci;
typedef struct ddtrace_span_t ddtrace_span_t;

zval *ddtrace_spandata_property_name(ddtrace_span_t *span);
zval *ddtrace_spandata_property_resource(ddtrace_span_t *span);
zval *ddtrace_spandata_property_service(ddtrace_span_t *span);
zval *ddtrace_spandata_property_type(ddtrace_span_t *span);
zval *ddtrace_spandata_property_meta(ddtrace_span_t *span);
zval *ddtrace_spandata_property_metrics(ddtrace_span_t *span);

BOOL_T ddtrace_tracer_is_limited(void);
// prepare the tracer state to start handling a new trace
void dd_prepare_for_new_trace(void);

// clang-format off
ZEND_BEGIN_MODULE_GLOBALS(ddtrace)
    char *auto_prepend_file;
    zend_bool disable;
    zend_bool disable_in_current_request;
    char *request_init_hook;
    zend_bool request_init_hook_loaded;
    // When 'drop_all_spans' is set, traces have to be dropped and not sent to the serializer and the sender.
    zend_bool drop_all_spans;

    uint32_t traces_group_id;
    HashTable *class_lookup;
    HashTable *function_lookup;
    zval additional_trace_meta; // IS_ARRAY
    zend_array *additional_global_tags;
    zend_bool log_backtrace;
    zend_bool backtrace_handler_already_run;
    dogstatsd_client dogstatsd_client;
    char *dogstatsd_host;
    char *dogstatsd_port;
    char *dogstatsd_buffer;

    uint64_t trace_id;
    ddtrace_span_ids_t *span_ids_top;
    ddtrace_span_fci *open_spans_top;
    ddtrace_span_fci *closed_spans_top;
    uint32_t open_spans_count;
    uint32_t closed_spans_count;
    int64_t compile_time_microseconds;
    uint64_t distributed_parent_trace_id;

    char *cgroup_file;
ZEND_END_MODULE_GLOBALS(ddtrace)
// clang-format on

#ifdef ZTS
#define DDTRACE_G(v) TSRMG(ddtrace_globals_id, zend_ddtrace_globals *, v)
#else
#define DDTRACE_G(v) (ddtrace_globals.v)
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

#endif  // DDTRACE_H
