#ifndef DDTRACE_H
#define DDTRACE_H
#include "version.h"
extern zend_module_entry ddtrace_module_entry;

ZEND_BEGIN_MODULE_GLOBALS(ddtrace)
zend_bool disable;
zend_bool disable_in_current_request;
char *request_init_hook;
char *internal_blacklisted_modules_list;
zend_bool strict_mode;

HashTable class_lookup;
HashTable function_lookup;
zend_bool log_backtrace;
zend_function *current_fbc;
zend_execute_data *original_execute_data;
#if PHP_VERSION_ID < 70000
zend_op **original_opline_ptr;
#endif

user_opcode_handler_t ddtrace_old_fcall_handler;
user_opcode_handler_t ddtrace_old_icall_handler;
user_opcode_handler_t ddtrace_old_fcall_by_name_handler;
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

#endif  // DDTRACE_H
