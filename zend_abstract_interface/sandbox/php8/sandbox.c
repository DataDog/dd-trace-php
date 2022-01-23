#include "../sandbox.h"

extern inline void zai_sandbox_open(zai_sandbox *sandbox);
extern inline void zai_sandbox_close(zai_sandbox *sandbox);
extern inline void zai_sandbox_bailout(zai_sandbox *sandbox);
extern inline bool zai_sandbox_timed_out(void);

extern inline void zai_sandbox_error_state_backup(zai_error_state *es);

/* In PHP 8, we cannot extern inline zai_sandbox_error_state_restore() because
 * zend_string_release() is static which generates the following compiler
 * warning:
 *
 *   "warning: 'zend_string_release' is static but used in inline function
 *    'zai_sandbox_error_state_restore' which is not static"
 */
void zai_sandbox_error_state_restore(zai_error_state *es) {
    if (PG(last_error_message)) {
        if (PG(last_error_message) != es->message) {
            zend_string_release(PG(last_error_message));
        }
        if (PG(last_error_file) != es->file) {
#if PHP_VERSION_ID < 80100
            free(PG(last_error_file));
#else
            zend_string_release(PG(last_error_file));
#endif
        }
    }
    zend_restore_error_handling(&es->error_handling);
    PG(last_error_type) = es->type;
    PG(last_error_message) = es->message;
    PG(last_error_file) = es->file;
    PG(last_error_lineno) = es->lineno;
    EG(error_reporting) = es->error_reporting;
}

extern inline void zai_sandbox_exception_state_backup(zai_exception_state *es);
extern inline void zai_sandbox_exception_state_restore(zai_exception_state *es);

extern inline void zai_sandbox_engine_state_backup(zai_engine_state *es);
extern inline void zai_sandbox_engine_state_restore(zai_engine_state *es);
