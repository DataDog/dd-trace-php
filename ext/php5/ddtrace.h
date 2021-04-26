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

BOOL_T ddtrace_tracer_is_limited(TSRMLS_D);

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
    zend_bool log_backtrace;
    zend_bool backtrace_handler_already_run;
    dogstatsd_client dogstatsd_client;
    char *dogstatsd_host;
    char *dogstatsd_port;
    char *dogstatsd_buffer;

    // Distributed tracing & curl
    HashTable *curl_headers;
    zend_bool curl_back_up_headers;
    int le_curl;  // ext/curl's list entry resource type
    HashTable *curl_multi_handles;
    HashTable *curl_multi_handles_cache;
    int curl_multi_handles_cache_id;
    HashTable *curl_bug_71523_copied_ch;

    /* These ones are used for measuring the call stack depth so that we can
     * emit a warning prior to encountering a stack overflow.
     *
     * A 16-bit call depth would allow us to count to 65,535, which is way more
     * than necessary. An 8-bit depth would be inadequate (255).
     */
    bool should_warn_call_depth;
    uint16_t call_depth;

    uint64_t trace_id;
    ddtrace_span_ids_t *span_ids_top;
    ddtrace_span_fci *open_spans_top;
    ddtrace_span_fci *closed_spans_top;
    uint32_t open_spans_count;
    uint32_t closed_spans_count;
    int64_t compile_time_microseconds;

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
#define DDTRACE_ARG_INFO_SIZE(arg_info) ((zend_uint)(sizeof(arg_info) / sizeof(struct _zend_arg_info) - 1))

#define DDTRACE_FENTRY(zend_name, name, arg_info, flags) \
    { #zend_name, name, arg_info, DDTRACE_ARG_INFO_SIZE(arg_info), flags }
#define DDTRACE_RAW_FENTRY(zend_name, name, arg_info, flags) \
    { zend_name, name, arg_info, DDTRACE_ARG_INFO_SIZE(arg_info), flags }

#define DDTRACE_FE(name, arg_info) DDTRACE_FENTRY(name, zif_##name, arg_info, 0)
#define DDTRACE_NS_FE(name, arg_info) DDTRACE_RAW_FENTRY("DDTrace\\" #name, zif_##name, arg_info, 0)
#define DDTRACE_SUB_NS_FE(ns, name, arg_info) DDTRACE_RAW_FENTRY("DDTrace\\" ns #name, zif_##name, arg_info, 0)
#define DDTRACE_FALIAS(name, alias, arg_info) DDTRACE_RAW_FENTRY(#name, zif_##alias, arg_info, 0)
#define DDTRACE_FE_END ZEND_FE_END

/* Currently used on PHP 5. After a zend_execute_ex has called the previous hook
 * the execute_data cannot be trusted for some things, notably function_state.
 * So we use this struct to back up the data.
 */
struct ddtrace_execute_data {
    zval *This;
    zend_class_entry *scope;
    zend_function *fbc;
    const zend_op *opline;
    void **arguments;
    zval *retval;
    bool free_retval;
};
typedef struct ddtrace_execute_data ddtrace_execute_data;

#endif  // DDTRACE_H
