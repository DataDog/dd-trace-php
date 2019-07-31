#ifndef DDTRACE_H
#define DDTRACE_H
#include <stdint.h>

#include "random.h"
#include "version.h"
extern zend_module_entry ddtrace_module_entry;

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

uint64_t root_span_id;
ddtrace_span_ids_t *span_ids_top;
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
