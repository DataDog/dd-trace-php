#ifndef DDTRACE_H
#define DDTRACE_H
#include <dogstatsd_client/client.h>
#include <stdint.h>

#include "env_config.h"
#include "random.h"
#include "span.h"
#include "version.h"

extern zend_module_entry ddtrace_module_entry;
extern zend_class_entry *ddtrace_ce_span_data;

BOOL_T ddtrace_tracer_is_limited(TSRMLS_D);

typedef struct _ddtrace_original_context {
    zend_function *fbc;
    zend_function *calling_fbc;
    zend_class_entry *calling_ce;
    zend_execute_data *execute_data;
#if PHP_VERSION_ID < 70000
    zval *function_name;
    zval *this;
#else
    zend_object *this;
#endif
} ddtrace_original_context;

ZEND_BEGIN_MODULE_GLOBALS(ddtrace)
char *auto_prepend_file;
zend_bool disable;
zend_bool disable_in_current_request;
char *traced_internal_functions;
char *request_init_hook;
zend_bool request_init_hook_loaded;
zend_bool strict_mode;

uint32_t traces_group_id;
HashTable *class_lookup;
HashTable *function_lookup;
zend_bool log_backtrace;
zend_bool backtrace_handler_already_run;
dogstatsd_client dogstatsd_client;
char *dogstatsd_host;
char *dogstatsd_port;
char *dogstatsd_buffer;
ddtrace_original_context original_context;

uint64_t trace_id;
ddtrace_span_ids_t *span_ids_top;
ddtrace_span_t *open_spans_top;
ddtrace_span_t *closed_spans_top;
uint32_t open_spans_count;
uint32_t closed_spans_count;
int64_t compile_time_microseconds;
ZEND_END_MODULE_GLOBALS(ddtrace)

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
#if PHP_VERSION_ID < 70000
#define DDTRACE_ARG_INFO_SIZE(arg_info) ((zend_uint)(sizeof(arg_info) / sizeof(struct _zend_arg_info) - 1))
#elif PHP_VERSION_ID < 80000
#define DDTRACE_ARG_INFO_SIZE(arg_info) ((uint32_t)(sizeof(arg_info) / sizeof(struct _zend_internal_arg_info) - 1))
#else
#error Check if ZEND_FENTRY has changed in PHP 8 and if we need to update the macros
#endif

#define DDTRACE_FENTRY(zend_name, name, arg_info, flags) \
    { #zend_name, name, arg_info, DDTRACE_ARG_INFO_SIZE(arg_info), flags }

#define DDTRACE_FE(name, arg_info) DDTRACE_FENTRY(name, ZEND_FN(name), arg_info, 0)
#define DDTRACE_FALIAS(name, alias, arg_info) DDTRACE_FENTRY(name, ZEND_FN(alias), arg_info, 0)
#define DDTRACE_FE_END ZEND_FE_END

#endif  // DDTRACE_H
