#ifndef ZAI_SANDBOX_H
#define ZAI_SANDBOX_H

#include <main/php.h>
#include <stdbool.h>

/* Work in progress
 *
 * The long-term goal is to provide version-agnostic ZAI error types that have
 * sandboxing capabilities baked in. For now this is a direct migration of the
 * existing ddtrace sandbox API.
 */

/******************************************************************************
 ************** WARNING: The sandbox does NOT catch zend_bailout **************
 ******************************************************************************
 *** The sandbox API is only concerned with backing up and restoring error  ***
 *** and exception state. Fatal errors are always accompanied by a          ***
 *** zend_bailout but a zend_bailout may occur in unsuspecting code paths   ***
 *** therefore usage of the sandbox API MUST be accompanied by a 'zend_try' ***
 *** block to catch a possible zend_bailout.                                ***
 ******************************************************************************
 ******************************************************************************
 */

/* Obscured in the compiler directives below are the following APIs: */

/* ######## Error & exception sandbox (Does NOT catch a zend_bailout) #########
 *
 * Convenience function that backs up the error, exception, and engine states
 * using the 'zai_sandbox_*_backup' APIs below.
 *
 *     void zai_sandbox_open(zai_sandbox *sandbox);
 *
 * Convenience function that restores the error and exception states using the
 * 'zai_sandbox_*_restore' APIs below.
 *
 *     void zai_sandbox_close(zai_sandbox *sandbox);
 *
 * NOTE: zai_sandbox_close does NOT restore engine state
 *         see zai_sandbox_bailout
 */

/* ########### Error state sandbox (Does NOT catch a zend_bailout) ############
 *
 * Backs up the error handler and the active error if present. Disables
 * 'EH_NORMAL' error handling so that user error handlers are not called.
 * Provides a clean slate of error state.
 *
 *     void zai_sandbox_error_state_backup(zai_error_state *es);
 *
 * Restores the error handler back to 'EH_NORMAL'. Clears any errors
 * encountered since the backup. If an error was present during backup, the
 * error is restored.
 *
 *     void zai_sandbox_error_state_restore(zai_error_state *es);
 */

/* ######### Exception state sandbox (Does NOT catch a zend_bailout) ##########
 *
 * Backs up exception state if there is an unhandled exception present during
 * backup. Provides a clean slate of exception state.
 *
 *     void zai_sandbox_exception_state_backup(zai_exception_state *es);
 *
 * Restores the exception state that was present during backup. Clears any
 * unhandled exception state that may have occurred since the last backup.
 *
 *     void zai_sandbox_exception_state_restore(zai_exception_state *es);
 */

/* ######### Bailouts ##########
 *
 * void zai_sandbox_bailout(zai_sandbox *sandbox)
 *
 * This function should be invoked when a bailout has been caught.
 *
 * It will restore engine state and continue in all but the case of a timeout
 */

/* ######### Timeout ##########
 *
 * void zai_sandbox_timed_out()
 *
 * This function is used to determine if a timeout has occured by bailout handling
 */

/* ######### Engine state sandbox ##########
 *
 * When a sandbox is opened, relevant engine state is backed up with
 *
 *     void zai_sandbox_engine_state_backup(zai_engine_state *es);
 *
 * When it is necessary, the engine state may be restored with
 *
 *     void zai_sandbox_engine_state_restore(zai_engine_state *es);
 */

#if PHP_VERSION_ID >= 80000
/********************************** <PHP 8> **********************************/
#include <Zend/zend_exceptions.h>

typedef struct zai_error_state_s {
    int type;
    int lineno;
    zend_string *message;
#if PHP_VERSION_ID < 80100
    char *file;
#else
    zend_string *file;
#endif
    int error_reporting;
    zend_error_handling error_handling;
} zai_error_state;

typedef struct zai_exception_state_s {
    zend_object *exception;
    zend_object *prev_exception;
    const zend_op *opline_before_exception;
} zai_exception_state;

typedef struct zai_engine_state_s {
    zend_execute_data *current_execute_data;
} zai_engine_state;

typedef struct zai_sandbox_s {
    zai_error_state error_state;
    zai_exception_state exception_state;
    zai_engine_state engine_state;
} zai_sandbox;

inline void zai_sandbox_error_state_backup(zai_error_state *es) {
    es->type = PG(last_error_type);
    es->lineno = PG(last_error_lineno);
    es->message = PG(last_error_message);
    es->file = PG(last_error_file);

    PG(last_error_type) = 0;
    PG(last_error_lineno) = 0;
    /* We need to null these so that if another error comes along they do not
     * get double-freed.
     */
    PG(last_error_message) = NULL;
    PG(last_error_file) = NULL;

    es->error_reporting = EG(error_reporting);
    EG(error_reporting) = 0;
    zend_replace_error_handling(EH_THROW, NULL, &es->error_handling);
}

void zai_sandbox_error_state_restore(zai_error_state *es);

inline void zai_sandbox_exception_state_backup(zai_exception_state *es) {
    if (UNEXPECTED(EG(exception) != NULL)) {
        es->exception = EG(exception);
        es->prev_exception = EG(prev_exception);
        es->opline_before_exception = EG(opline_before_exception);
        EG(exception) = NULL;
        EG(prev_exception) = NULL;
    } else {
        es->exception = NULL;
        es->prev_exception = NULL;
    }
}

inline void zai_sandbox_exception_state_restore(zai_exception_state *es) {
    if (EG(exception)) {
        zend_clear_exception();
    }

    if (es->exception) {
        EG(exception) = es->exception;
        EG(prev_exception) = es->prev_exception;
        EG(opline_before_exception) = es->opline_before_exception;
    }
}

inline void zai_sandbox_engine_state_backup(zai_engine_state *es) {
    es->current_execute_data = EG(current_execute_data);
}

inline void zai_sandbox_engine_state_restore(zai_engine_state *es) {
    EG(current_execute_data) = es->current_execute_data;
}

inline void zai_sandbox_open(zai_sandbox *sandbox) {
    zai_sandbox_exception_state_backup(&sandbox->exception_state);
    zai_sandbox_error_state_backup(&sandbox->error_state);
    zai_sandbox_engine_state_backup(&sandbox->engine_state);
}

inline void zai_sandbox_close(zai_sandbox *sandbox) {
    zai_sandbox_error_state_restore(&sandbox->error_state);
    zai_sandbox_exception_state_restore(&sandbox->exception_state);
}

inline bool zai_sandbox_timed_out(void) {
    if (EG(timed_out)) {
        return true;
    }

    if (PG(connection_status) & PHP_CONNECTION_TIMEOUT) {
        return true;
    }

    return false;
}

inline void zai_sandbox_bailout(zai_sandbox *sandbox) {
    if (!zai_sandbox_timed_out()) {
        zai_sandbox_engine_state_restore(&sandbox->engine_state);

        return;
    }

    zend_bailout();
}
/********************************** </PHP 8> *********************************/
#elif PHP_VERSION_ID >= 70000
/********************************** <PHP 7> **********************************/
#include <Zend/zend_exceptions.h>

typedef struct zai_error_state_s {
    int type;
    int lineno;
    char *message;
    char *file;
    int error_reporting;
    zend_error_handling error_handling;
} zai_error_state;

typedef struct zai_exception_state_s {
    zend_object *exception;
    zend_object *prev_exception;
    const zend_op *opline_before_exception;
} zai_exception_state;

typedef struct zai_engine_state_s {
    zend_execute_data *current_execute_data;
} zai_engine_state;

typedef struct zai_sandbox_s {
    zai_error_state error_state;
    zai_exception_state exception_state;
    zai_engine_state engine_state;
} zai_sandbox;

inline void zai_sandbox_error_state_backup(zai_error_state *es) {
    es->type = PG(last_error_type);
    es->lineno = PG(last_error_lineno);
    es->message = PG(last_error_message);
    es->file = PG(last_error_file);

    PG(last_error_type) = 0;
    PG(last_error_lineno) = 0;
    /* We need to null these so that if another error comes along they do not
     * get double-freed.
     */
    PG(last_error_message) = NULL;
    PG(last_error_file) = NULL;

    es->error_reporting = EG(error_reporting);
    EG(error_reporting) = 0;
    zend_replace_error_handling(EH_THROW, NULL, &es->error_handling);
}

inline void zai_sandbox_error_state_restore(zai_error_state *es) {
    if (PG(last_error_message)) {
        if (PG(last_error_message) != es->message) {
            free(PG(last_error_message));
        }
        if (PG(last_error_file) != es->file) {
            free(PG(last_error_file));
        }
    }
    zend_restore_error_handling(&es->error_handling);
    PG(last_error_type) = es->type;
    PG(last_error_message) = es->message;
    PG(last_error_file) = es->file;
    PG(last_error_lineno) = es->lineno;
    EG(error_reporting) = es->error_reporting;
}

inline void zai_sandbox_exception_state_backup(zai_exception_state *es) {
    if (UNEXPECTED(EG(exception) != NULL)) {
        es->exception = EG(exception);
        es->prev_exception = EG(prev_exception);
        es->opline_before_exception = EG(opline_before_exception);
        EG(exception) = NULL;
        EG(prev_exception) = NULL;
    } else {
        es->exception = NULL;
        es->prev_exception = NULL;
    }
}

inline void zai_sandbox_exception_state_restore(zai_exception_state *es) {
    if (EG(exception)) {
        zend_clear_exception();
    }

    if (es->exception) {
        EG(exception) = es->exception;
        EG(prev_exception) = es->prev_exception;
        EG(opline_before_exception) = es->opline_before_exception;
    }
}

inline void zai_sandbox_engine_state_backup(zai_engine_state *es) {
    es->current_execute_data = EG(current_execute_data);
}

inline void zai_sandbox_engine_state_restore(zai_engine_state *es) {
    EG(current_execute_data) = es->current_execute_data;
}

inline void zai_sandbox_open(zai_sandbox *sandbox) {
    zai_sandbox_exception_state_backup(&sandbox->exception_state);
    zai_sandbox_error_state_backup(&sandbox->error_state);
    zai_sandbox_engine_state_backup(&sandbox->engine_state);
}

inline void zai_sandbox_close(zai_sandbox *sandbox) {
    zai_sandbox_error_state_restore(&sandbox->error_state);
    zai_sandbox_exception_state_restore(&sandbox->exception_state);
}

inline bool zai_sandbox_timed_out(void) {
#if PHP_VERSION_ID >= 70100
    if (EG(timed_out)) {
        return true;
    }
#endif

    if (PG(connection_status) & PHP_CONNECTION_TIMEOUT) {
        return true;
    }

    return false;
}

inline void zai_sandbox_bailout(zai_sandbox *sandbox) {
    if (!zai_sandbox_timed_out()) {
        zai_sandbox_engine_state_restore(&sandbox->engine_state);

        return;
    }

    zend_bailout();
}
/********************************** </PHP 7> *********************************/
#else
/********************************** <PHP 5> **********************************/
typedef struct zai_error_state_s {
    int type;
    int lineno;
    char *message;
    char *file;
    int error_reporting;
    zend_error_handling error_handling;
} zai_error_state;

typedef struct zai_exception_state_s {
    zval *exception;
    zval *prev_exception;
    zend_op *opline_before_exception;
} zai_exception_state;

typedef struct zai_engine_state_s {
    zend_execute_data *current_execute_data;
} zai_engine_state;

typedef struct zai_sandbox_s {
    zai_error_state error_state;
    zai_exception_state exception_state;
    zai_engine_state engine_state;
} zai_sandbox;

inline void zai_sandbox_error_state_backup_ex(zai_error_state *es TSRMLS_DC) {
    es->type = PG(last_error_type);
    es->lineno = PG(last_error_lineno);
    es->message = PG(last_error_message);
    es->file = PG(last_error_file);

    PG(last_error_type) = 0;
    PG(last_error_lineno) = 0;
    /* We need to null these so that if another error comes along they do not
     * get double-freed.
     */
    PG(last_error_message) = NULL;
    PG(last_error_file) = NULL;

    es->error_reporting = EG(error_reporting);
    EG(error_reporting) = 0;
    zend_replace_error_handling(EH_SUPPRESS, NULL, &es->error_handling TSRMLS_CC);
}

inline void zai_sandbox_error_state_restore_ex(zai_error_state *es TSRMLS_DC) {
    if (PG(last_error_message)) {
        if (PG(last_error_message) != es->message) {
            free(PG(last_error_message));
        }
        if (PG(last_error_file) != es->file) {
            free(PG(last_error_file));
        }
    }
    zend_restore_error_handling(&es->error_handling TSRMLS_CC);
    PG(last_error_type) = es->type;
    PG(last_error_message) = es->message;
    PG(last_error_file) = es->file;
    PG(last_error_lineno) = es->lineno;
    EG(error_reporting) = es->error_reporting;
}

inline void zai_sandbox_exception_state_backup_ex(zai_exception_state *es TSRMLS_DC) {
    if (UNEXPECTED(EG(exception) != NULL)) {
        es->opline_before_exception = EG(opline_before_exception);
        es->exception = EG(exception);
        es->prev_exception = EG(prev_exception);
        EG(exception) = NULL;
        EG(prev_exception) = NULL;
    } else {
        es->exception = NULL;
        es->prev_exception = NULL;
    }
}

inline void zai_sandbox_exception_state_restore_ex(zai_exception_state *es TSRMLS_DC) {
    if (EG(exception)) {
        /* We cannot use zend_clear_exception() here in PHP 5 since there is no
         * NULL check on the opline.
         */
        zval_ptr_dtor(&EG(exception));
        EG(exception) = NULL;
        if (EG(prev_exception)) {
            zval_ptr_dtor(&EG(prev_exception));
            EG(prev_exception) = NULL;
        }
        if (EG(current_execute_data)) {
            EG(current_execute_data)->opline = EG(opline_before_exception);
        }
    }

    if (es->exception) {
        EG(exception) = es->exception;
        EG(prev_exception) = es->prev_exception;
        EG(opline_before_exception) = es->opline_before_exception;
#if PHP_VERSION_ID >= 50500
        EG(current_execute_data)->opline = EG(exception_op);
#endif
    }
}

inline void zai_sandbox_engine_state_backup_ex(zai_engine_state *es TSRMLS_DC) {
    es->current_execute_data = EG(current_execute_data);
}

inline void zai_sandbox_engine_state_restore_ex(zai_engine_state *es TSRMLS_DC) {
    EG(current_execute_data) = es->current_execute_data;
}

inline void zai_sandbox_open_ex(zai_sandbox *sandbox TSRMLS_DC) {
    zai_sandbox_exception_state_backup_ex(&sandbox->exception_state TSRMLS_CC);
    zai_sandbox_error_state_backup_ex(&sandbox->error_state TSRMLS_CC);
    zai_sandbox_engine_state_backup_ex(&sandbox->engine_state TSRMLS_CC);
}

inline void zai_sandbox_close_ex(zai_sandbox *sandbox TSRMLS_DC) {
    zai_sandbox_error_state_restore_ex(&sandbox->error_state TSRMLS_CC);
    zai_sandbox_exception_state_restore_ex(&sandbox->exception_state TSRMLS_CC);
}

inline bool zai_sandbox_timed_out_ex(TSRMLS_D) {
    if (PG(connection_status) & PHP_CONNECTION_TIMEOUT) {
        return true;
    }

    return false;
}

inline void zai_sandbox_bailout_ex(zai_sandbox *sandbox TSRMLS_DC) {
    if (!zai_sandbox_timed_out_ex(TSRMLS_C)) {
        zai_sandbox_engine_state_restore_ex(&sandbox->engine_state TSRMLS_CC);

        return;
    }

    zend_bailout();
}

/* Mask away the TSRMLS_* macros with more macros */
#define zai_sandbox_open(sandbox) zai_sandbox_open_ex(sandbox TSRMLS_CC)
#define zai_sandbox_close(sandbox) zai_sandbox_close_ex(sandbox TSRMLS_CC)
#define zai_sandbox_bailout(sandbox) zai_sandbox_bailout_ex(sandbox TSRMLS_CC)
#define zai_sandbox_timed_out() zai_sandbox_timed_out_ex(TSRMLS_C)

#define zai_sandbox_error_state_backup(es) zai_sandbox_error_state_backup_ex(es TSRMLS_CC)
#define zai_sandbox_error_state_restore(es) zai_sandbox_error_state_restore_ex(es TSRMLS_CC)

#define zai_sandbox_exception_state_backup(es) zai_sandbox_exception_state_backup_ex(es TSRMLS_CC)
#define zai_sandbox_exception_state_restore(es) zai_sandbox_exception_state_restore_ex(es TSRMLS_CC)
#define zai_sandbox_engine_state_backup(es) zai_sandbox_engine_state_backup_ex(es TSRMLS_CC)
#define zai_sandbox_engine_state_restore(es) zai_sandbox_engine_state_restore_ex(es TSRMLS_CC)
/********************************** </PHP 5> *********************************/
#endif

#endif  // ZAI_SANDBOX_H
