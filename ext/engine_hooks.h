#ifndef DD_ENGINE_HOOKS_H
#define DD_ENGINE_HOOKS_H

#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#if PHP_VERSION_ID >= 80000
#include <Zend/zend_observer.h>
#endif
#include <php.h>
#include <stdint.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "ddtrace_string.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

void ddtrace_engine_hooks_minit(void);
void ddtrace_fetch_profiling_symbols(void);
void ddtrace_engine_hooks_mshutdown(void);

void ddtrace_compile_time_reset(void);
int64_t ddtrace_compile_time_get(void);

struct ddtrace_error_handling {
    int type;
    int lineno;
#if PHP_VERSION_ID < 80000
    char *message;
#else
    zend_string *message;
#endif
#if PHP_VERSION_ID < 80100
    char *file;
#else
    zend_string *file;
#endif
    int error_reporting;
    zend_error_handling error_handling;
};
typedef struct ddtrace_error_handling ddtrace_error_handling;

struct ddtrace_sandbox_backup {
    ddtrace_error_handling eh;
    zend_object *exception, *prev_exception;
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

void ddtrace_restore_error_handling(ddtrace_error_handling *eh);

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
    ddtrace_backup_error_handling(&backup.eh, EH_THROW);
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

#if PHP_VERSION_ID < 80000
PHP_FUNCTION(ddtrace_internal_function_handler);

#define DDTRACE_ERROR_CB_PARAMETERS \
    int orig_type, const char *error_filename, const uint32_t error_lineno, const char *format, va_list args

#define DDTRACE_ERROR_CB_PARAM_PASSTHRU orig_type, error_filename, error_lineno, format, args
#else
#if PHP_VERSION_ID < 80100
#define DDTRACE_ERROR_CB_PARAMETERS \
    int orig_type, const char *error_filename, const uint32_t error_lineno, zend_string *message
#else
#define DDTRACE_ERROR_CB_PARAMETERS \
    int orig_type, zend_string *error_filename, const uint32_t error_lineno, zend_string *message
#endif

#define DDTRACE_ERROR_CB_PARAM_PASSTHRU orig_type, error_filename, error_lineno, message
#endif

extern void (*ddtrace_prev_error_cb)(DDTRACE_ERROR_CB_PARAMETERS);

void ddtrace_error_cb(DDTRACE_ERROR_CB_PARAMETERS);

#endif  // DD_ENGINE_HOOKS_H
