#include "../../tsrmls_cache.h"
#include "../sandbox.h"

ZEND_EXT_TLS long zai_sandbox_active = 0;

extern inline void zai_sandbox_open(zai_sandbox *sandbox);
extern inline void zai_sandbox_close(zai_sandbox *sandbox);
extern inline void zai_sandbox_bailout(zai_sandbox *sandbox);
extern inline bool zai_sandbox_timed_out(void);
extern inline bool zai_is_request_blocked(void);

extern inline void zai_sandbox_error_state_backup(zai_error_state *es);

/* In PHP 8, we cannot extern inline zai_sandbox_error_state_restore() because
 * zend_string_release() is static which generates the following compiler
 * warning:
 *
 *   "warning: 'zend_string_release' is static but used in inline function
 *    'zai_sandbox_error_state_restore' which is not static"
 */
void zai_sandbox_error_state_restore(zai_error_state *es) {
#if PHP_VERSION_ID >= 80200
    if (zai_sandbox_has_oom() && PG(last_error_message)) {
        // Keep the OOM error for error_get_last(), the request is over
        if (es->message) {
            zend_string_release(es->message);
        }
        if (es->file) {
            zend_string_release(es->file);
        }
        zend_restore_error_handling(&es->error_handling);
        EG(error_reporting) = es->error_reporting;
        return;
    }
#endif
    if (PG(last_error_message)) {
        zend_string_release(PG(last_error_message));
    }
    if (PG(last_error_file)) {
#if PHP_VERSION_ID < 80100
        free(PG(last_error_file));
#else
        zend_string_release(PG(last_error_file));
#endif
    }
    zend_restore_error_handling(&es->error_handling);
    PG(last_error_type) = es->type;
    PG(last_error_message) = es->message;
    PG(last_error_file) = es->file;
    PG(last_error_lineno) = es->lineno;
    EG(error_reporting) = es->error_reporting;
}

extern inline void zai_sandbox_exception_state_backup(zai_exception_state *es);

/* Not extern inline either: OBJ_RELEASE() and zai_resolve__femalloc() are static too */
void zai_sandbox_exception_state_restore(zai_exception_state *es) {
    if (EG(exception)) {
        if (zai_sandbox_has_oom() && zend_is_unwind_exit(EG(exception))) {
            // Keep unwinding for the OOM, which supersedes the saved exception like in zend_handle_delayed_oom()
            if (es->exception) {
                OBJ_RELEASE(es->exception);
#if PHP_VERSION_ID < 80600
                if (es->prev_exception) {
                    OBJ_RELEASE(es->prev_exception);
                }
#endif
            }
            return;
        }
        zend_clear_exception();
    }

    if (es->exception) {
        EG(exception) = es->exception;
#if PHP_VERSION_ID < 80600
        EG(prev_exception) = es->prev_exception;
#endif
        if (EG(current_execute_data)) {
            // ensure that we continue handling an exception if we were handling one before the sandbox call
            EG(current_execute_data)->opline = EG(exception_op);
        }
        EG(opline_before_exception) = es->opline_before_exception;
    }
}

extern inline void zai_sandbox_engine_state_backup(zai_engine_state *es);
extern inline void zai_sandbox_engine_state_restore(zai_engine_state *es);
