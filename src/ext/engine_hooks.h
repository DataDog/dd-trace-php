#ifndef DD_ENGINE_HOOKS_H
#define DD_ENGINE_HOOKS_H

#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include <php.h>
#include <stdint.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "ddtrace_string.h"

extern int ddtrace_resource;

#if PHP_VERSION_ID >= 70400
extern int ddtrace_op_array_extension;
#define DDTRACE_OP_ARRAY_EXTENSION(op_array) ZEND_OP_ARRAY_EXTENSION(op_array, ddtrace_op_array_extension)
#endif

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

void ddtrace_engine_hooks_minit(void);
void ddtrace_engine_hooks_mshutdown(void);

void ddtrace_compile_time_reset(TSRMLS_D);
int64_t ddtrace_compile_time_get(TSRMLS_D);

struct ddtrace_error_handling {
    int type;
    int lineno;
    char *message;
    char *file;
    int error_reporting;
    zend_error_handling error_handling;
};
typedef struct ddtrace_error_handling ddtrace_error_handling;

struct ddtrace_sandbox_backup {
    ddtrace_error_handling eh;
    ddtrace_exception_t *exception, *prev_exception;
#if PHP_VERSION_ID < 70000
    zend_op *opline_before_exception;
#endif
};
typedef struct ddtrace_sandbox_backup ddtrace_sandbox_backup;

inline void ddtrace_backup_error_handling(ddtrace_error_handling *eh, zend_error_handling_t mode TSRMLS_DC) {
    eh->type = PG(last_error_type);
    eh->lineno = PG(last_error_lineno);
    eh->message = PG(last_error_message);
    eh->file = PG(last_error_file);

    // Need to null these so that if another error comes along that they don't get double-freed
    PG(last_error_message) = NULL;
    PG(last_error_file) = NULL;

    eh->error_reporting = EG(error_reporting);
    EG(error_reporting) = 0;
    zend_replace_error_handling(mode, NULL, &eh->error_handling TSRMLS_CC);
}

inline void ddtrace_restore_error_handling(ddtrace_error_handling *eh TSRMLS_DC) {
    if (PG(last_error_message)) {
        if (PG(last_error_message) != eh->message) {
            free(PG(last_error_message));
        }
        if (PG(last_error_file) != eh->file) {
            free(PG(last_error_file));
        }
    }
    zend_restore_error_handling(&eh->error_handling TSRMLS_CC);
    PG(last_error_type) = eh->type;
    PG(last_error_message) = eh->message;
    PG(last_error_file) = eh->file;
    PG(last_error_lineno) = eh->lineno;
    EG(error_reporting) = eh->error_reporting;
}

#if PHP_VERSION_ID < 70000
inline void ddtrace_maybe_clear_exception(TSRMLS_D) {
    if (EG(exception)) {
        // Cannot use zend_clear_exception() in PHP 5 since there is no NULL check on the opline
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
}
#else
inline void ddtrace_maybe_clear_exception(void) {
    if (EG(exception)) {
        zend_clear_exception();
    }
}
#endif

#if PHP_VERSION_ID < 70000
inline ddtrace_sandbox_backup ddtrace_sandbox_begin(zend_op *opline_before_exception TSRMLS_DC) {
#else
inline ddtrace_sandbox_backup ddtrace_sandbox_begin(void) {
#endif
    ddtrace_sandbox_backup backup = {.exception = NULL, .prev_exception = NULL};
    if (EG(exception)) {
        backup.exception = EG(exception);
        backup.prev_exception = EG(prev_exception);
        EG(exception) = NULL;
        EG(prev_exception) = NULL;

#if PHP_VERSION_ID < 70000
        backup.opline_before_exception = opline_before_exception;
#endif
    }

#if PHP_VERSION_ID < 70000
    zend_error_handling_t mode = EH_SUPPRESS;
#else
    zend_error_handling_t mode = EH_THROW;
#endif

    ddtrace_backup_error_handling(&backup.eh, mode TSRMLS_CC);
    return backup;
}

inline void ddtrace_sandbox_end(ddtrace_sandbox_backup *backup TSRMLS_DC) {
    ddtrace_restore_error_handling(&backup->eh TSRMLS_CC);
    ddtrace_maybe_clear_exception(TSRMLS_C);

    if (backup->exception) {
        EG(exception) = backup->exception;
        EG(prev_exception) = backup->prev_exception;

#if PHP_VERSION_ID < 70000
        EG(opline_before_exception) = backup->opline_before_exception;
#if PHP_VERSION_ID >= 50500
        EG(current_execute_data)->opline = EG(exception_op);
#endif
#else
        zend_throw_exception_internal(NULL);
#endif
    }
}

#if PHP_VERSION_ID >= 70000
PHP_FUNCTION(ddtrace_internal_function_handler);
#endif

#endif  // DD_ENGINE_HOOKS_H
