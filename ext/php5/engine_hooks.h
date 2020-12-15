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

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

void ddtrace_engine_hooks_minit(void);
void ddtrace_engine_hooks_rinit(TSRMLS_D);
void ddtrace_engine_hooks_rshutdown(TSRMLS_D);
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
    zend_op *opline_before_exception;
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

inline ddtrace_sandbox_backup ddtrace_sandbox_begin(zend_op *opline_before_exception TSRMLS_DC) {
    ddtrace_sandbox_backup backup = {.exception = NULL, .prev_exception = NULL};
    if (EG(exception)) {
        backup.exception = EG(exception);
        backup.prev_exception = EG(prev_exception);
        EG(exception) = NULL;
        EG(prev_exception) = NULL;
        backup.opline_before_exception = opline_before_exception;
    }

    zend_error_handling_t mode = EH_SUPPRESS;
    ddtrace_backup_error_handling(&backup.eh, mode TSRMLS_CC);
    return backup;
}

inline void ddtrace_sandbox_end(ddtrace_sandbox_backup *backup TSRMLS_DC) {
    ddtrace_restore_error_handling(&backup->eh TSRMLS_CC);
    ddtrace_maybe_clear_exception(TSRMLS_C);

    if (backup->exception) {
        EG(exception) = backup->exception;
        EG(prev_exception) = backup->prev_exception;

        EG(opline_before_exception) = backup->opline_before_exception;
#if PHP_VERSION_ID >= 50500
        EG(current_execute_data)->opline = EG(exception_op);
#endif
    }
}

#define DDTRACE_ERROR_CB_PARAMETERS \
    int type, const char *error_filename, const uint error_lineno, const char *format, va_list args

#define DDTRACE_ERROR_CB_PARAM_PASSTHRU type, error_filename, error_lineno, format, args

extern void (*ddtrace_prev_error_cb)(DDTRACE_ERROR_CB_PARAMETERS);
void ddtrace_error_cb(DDTRACE_ERROR_CB_PARAMETERS);
ddtrace_exception_t *ddtrace_make_exception_from_error(DDTRACE_ERROR_CB_PARAMETERS TSRMLS_DC);

void ddtrace_span_attach_exception(ddtrace_span_fci *span_fci, ddtrace_exception_t *exception);
void ddtrace_close_all_open_spans(TSRMLS_D);

inline zval *ddtrace_exception_get_entry(zval *object, char *name, int name_len TSRMLS_DC) {
    zend_class_entry *exception_ce = zend_exception_get_default(TSRMLS_C);
    return zend_read_property(exception_ce, object, name, name_len, 1 TSRMLS_CC);
}

#endif  // DD_ENGINE_HOOKS_H
