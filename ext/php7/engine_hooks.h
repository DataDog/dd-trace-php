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
void ddtrace_engine_hooks_rinit(void);
void ddtrace_engine_hooks_rshutdown(void);
void ddtrace_engine_hooks_mshutdown(void);

void ddtrace_compile_time_reset(void);
int64_t ddtrace_compile_time_get(void);

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
};
typedef struct ddtrace_sandbox_backup ddtrace_sandbox_backup;

inline void ddtrace_backup_error_handling(ddtrace_error_handling *eh, zend_error_handling_t mode) {
    eh->type = PG(last_error_type);
    eh->lineno = PG(last_error_lineno);
    eh->message = PG(last_error_message);
    eh->file = PG(last_error_file);

    // Need to null these so that if another error comes along that they don't get double-freed
    PG(last_error_message) = NULL;
    PG(last_error_file) = NULL;

    eh->error_reporting = EG(error_reporting);
    EG(error_reporting) = 0;
    zend_replace_error_handling(mode, NULL, &eh->error_handling);
}

inline void ddtrace_restore_error_handling(ddtrace_error_handling *eh) {
    if (PG(last_error_message)) {
        if (PG(last_error_message) != eh->message) {
            free(PG(last_error_message));
        }
        if (PG(last_error_file) != eh->file) {
            free(PG(last_error_file));
        }
    }
    zend_restore_error_handling(&eh->error_handling);
    PG(last_error_type) = eh->type;
    PG(last_error_message) = eh->message;
    PG(last_error_file) = eh->file;
    PG(last_error_lineno) = eh->lineno;
    EG(error_reporting) = eh->error_reporting;
}

inline void ddtrace_maybe_clear_exception(void) {
    if (EG(exception)) {
        zend_clear_exception();
    }
}

inline ddtrace_sandbox_backup ddtrace_sandbox_begin(void) {
    ddtrace_sandbox_backup backup = {.exception = NULL, .prev_exception = NULL};
    if (EG(exception)) {
        backup.exception = EG(exception);
        backup.prev_exception = EG(prev_exception);
        EG(exception) = NULL;
        EG(prev_exception) = NULL;
    }

    zend_error_handling_t mode = EH_THROW;

    ddtrace_backup_error_handling(&backup.eh, mode);
    return backup;
}

inline void ddtrace_sandbox_end(ddtrace_sandbox_backup *backup) {
    ddtrace_restore_error_handling(&backup->eh);
    ddtrace_maybe_clear_exception();

    if (backup->exception) {
        EG(exception) = backup->exception;
        EG(prev_exception) = backup->prev_exception;

        zend_throw_exception_internal(NULL);
    }
}

PHP_FUNCTION(ddtrace_internal_function_handler);

#define DDTRACE_ERROR_CB_PARAMETERS \
    int type, const char *error_filename, const uint error_lineno, const char *format, va_list args

#define DDTRACE_ERROR_CB_PARAM_PASSTHRU type, error_filename, error_lineno, format, args

extern void (*ddtrace_prev_error_cb)(DDTRACE_ERROR_CB_PARAMETERS);

void ddtrace_error_cb(DDTRACE_ERROR_CB_PARAMETERS);
void ddtrace_span_attach_exception(ddtrace_span_fci *span_fci, ddtrace_exception_t *exception);
void ddtrace_close_all_open_spans(void);

#endif  // DD_ENGINE_HOOKS_H
