#ifndef DDTRACE_H
#define DDTRACE_H
#include <stdint.h>

#include "random.h"
#include "version.h"
extern zend_module_entry ddtrace_module_entry;

extern zend_class_entry *ddtrace_ce_span_data;

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

// We don't store trace_id since it is added at
// serialization from DDTRACE_G(root_span_id)
typedef struct _ddtrace_span_stack_t {
    zval *span;
    uint64_t span_id;
    uint64_t parent_id;
    uint64_t start;
    uint64_t duration;
    struct _ddtrace_span_stack_t *next;
} ddtrace_span_stack_t;

typedef struct _ddtrace_closed_spans_t {
    zval *span;
    struct _ddtrace_closed_spans_t *next;
} ddtrace_closed_spans_t;

// "EmbedTrace" is for BC.
// Once all the integrations have been updated to use,
// `dd_trace_method()` and `dd_trace_function()`,
// we can remove this enum and also the `dd_trace()` function
enum ddtrace_callback_behavior{EmbedTrace, AppendTrace};

ZEND_BEGIN_MODULE_GLOBALS(ddtrace)
zend_bool disable;
zend_bool disable_in_current_request;
char *request_init_hook;
char *internal_blacklisted_modules_list;
zend_bool strict_mode;

uint32_t traces_group_id;
HashTable *class_lookup;
HashTable *function_lookup;
zend_bool log_backtrace;
ddtrace_original_context original_context;

user_opcode_handler_t ddtrace_old_fcall_handler;
user_opcode_handler_t ddtrace_old_icall_handler;
user_opcode_handler_t ddtrace_old_ucall_handler;
user_opcode_handler_t ddtrace_old_fcall_by_name_handler;

zval service_name;
uint64_t root_span_id;
ddtrace_span_ids_t *span_ids_top;
ddtrace_span_stack_t *span_stack_top;
ddtrace_closed_spans_t *closed_spans_top;
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

#endif  // DDTRACE_H
