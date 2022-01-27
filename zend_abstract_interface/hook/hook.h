#ifndef ZAI_HOOK_H
#define ZAI_HOOK_H
// clang-format off
#include <symbols/symbols.h>

/* The Hook interface intends to abstract away the storage and resolution of hook targets
 *
 * Internal Hooks:
 *  An internal hook is intended to be used by extension or interface code. An internal
 *  hook can recieve a fixed address, and dynamically allocated reigon of memory.
 *
 *  An internal hook may have a begin and end handler (either may be null).
 *
 * User Hooks:
 *  A user hook may be used by an extension, or in userland. User hooks can not recieve
 *  a fixed address, nor reserve dynamically allocated memory, and can only be installed
 *  during the request stage.
 *
 *  A user hook may have a begin and end handler, which are Closures (either may be null).
 *
 * Begin Handlers:
 *  Should a begin handler return falsy, the remaining begin hooks will not be executed
 *  but the end hooks installed will be (with a null return value).
 *
 * Note that the hook interface does not instrument Zend itself, the instrumentor API
 * is at the bottom of this header.
 */

/* {{{ staging functions
        Note: installation of hooks may occur after minit */
bool zai_hook_minit(void);
bool zai_hook_rinit(void);
void zai_hook_rshutdown(void);
void zai_hook_mshutdown(void); /* }}} */

typedef zval zai_hook_begin_u;
typedef zval zai_hook_end_u;

typedef bool (*zai_hook_begin_i)(zend_execute_data *frame, void *fixed, void *dynamic ZAI_TSRMLS_DC);
typedef void (*zai_hook_end_i)(zend_execute_data *frame, zval *retval, void *fixed, void *dynamic ZAI_TSRMLS_DC);

typedef union {
    zai_hook_begin_u u;
    zai_hook_begin_i i;
} zai_hook_begin;

typedef union {
    zai_hook_end_u u;
    zai_hook_end_i i;
} zai_hook_end;

typedef enum {
    ZAI_HOOK_INTERNAL,
    ZAI_HOOK_USER,
} zai_hook_type_t;

/* {{ convenience and tidyness is important, see inlines */
#define ZAI_HOOK_BEGIN_USER(zv) \
    (zai_hook_begin) { .u = (zai_hook_begin_u)zv }
#define ZAI_HOOK_BEGIN_INTERNAL(handler) \
    (zai_hook_begin) { .i = (zai_hook_begin_i)handler }

#define ZAI_HOOK_END_USER(zv) \
    (zai_hook_end) { .u = (zai_hook_end_u)zv }
#define ZAI_HOOK_END_INTERNAL(handler) \
    (zai_hook_end) { .i = (zai_hook_end_i)handler } /* }}} */

bool zai_hook_install(
        zai_hook_type_t type,
        zai_string_view scope,
        zai_string_view function,
        zai_hook_begin  begin,
        zai_hook_end    end,
        void *fixed, size_t dynamic ZAI_TSRMLS_DC);

/* {{{ Internal installs may pass a fixed address, and or dynamic size.
        A non zero dynamic size will result in the begin and end handlers
        recieving zeroed memory managed by the hook interface */
static inline bool zai_hook_install_internal(
                    zai_string_view  scope,
                    zai_string_view  function,
                    zai_hook_begin_i begin,
                    zai_hook_end_i   end,
                    void *fixed, size_t dynamic ZAI_TSRMLS_DC) {
    return zai_hook_install(
            ZAI_HOOK_INTERNAL,
            scope,
            function,
            ZAI_HOOK_BEGIN_INTERNAL(begin),
            ZAI_HOOK_END_INTERNAL(end),
            fixed, dynamic ZAI_TSRMLS_CC);
} /* }}} */

/* {{{ User installs may not pass fixed or reserve dynamic data */
static inline bool zai_hook_install_user(
                    zai_string_view  scope,
                    zai_string_view  function,
                    zai_hook_begin_u begin,
                    zai_hook_end_u   end ZAI_TSRMLS_DC) {
    return zai_hook_install(
            ZAI_HOOK_USER,
            scope,
            function,
            ZAI_HOOK_BEGIN_USER(begin),
            ZAI_HOOK_END_USER(end),
            NULL, 0 ZAI_TSRMLS_CC);
} /* }}} */

/* {{{ zai_hook_installed shall return true if there are installs for this frame */
bool zai_hook_installed(zend_execute_data *ex ZAI_TSRMLS_CC); /* }}} */

/* {{{ zai_hook_continue shall execute begin handlers and return false if
        the caller should bail out (one of the handlers returned false) */
bool zai_hook_continue(zend_execute_data *ex, void **reserved ZAI_TSRMLS_DC); /* }}} */

/* {{{ zai_hook_finish shall execute end handlers and cleanup reserved memory */
void zai_hook_finish(zend_execute_data *ex, zval *rv, void **reserved ZAI_TSRMLS_DC); /* }}} */

/* {{{ zai_hook_resolve should be called as little as possible
        NOTE: will be called by hook interface on rinit, to resolve internal installs early */
void zai_hook_resolve(ZAI_TSRMLS_D); /* }}} */

// clang-format on
#endif  // ZAI_HOOK_H
