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

extern int ddtrace_op_array_extension;
#define DDTRACE_OP_ARRAY_EXTENSION(op_array) ZEND_OP_ARRAY_EXTENSION(op_array, ddtrace_op_array_extension)

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
    zend_string *message;
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

inline void ddtrace_sandbox_end(ddtrace_sandbox_backup *backup TSRMLS_DC) {
    ddtrace_restore_error_handling(&backup->eh TSRMLS_CC);
    ddtrace_maybe_clear_exception(TSRMLS_C);

    if (backup->exception) {
        EG(exception) = backup->exception;
        EG(prev_exception) = backup->prev_exception;
        zend_throw_exception_internal(NULL);
    }
}

PHP_FUNCTION(ddtrace_internal_function_handler);

void ddtrace_observer_error_cb(int type, const char *error_filename, uint32_t error_lineno, zend_string *message);
void ddtrace_span_attach_exception(ddtrace_span_fci *span_fci, ddtrace_exception_t *exception);
void ddtrace_close_all_open_spans(void);

inline zend_class_entry *ddtrace_get_exception_base(zval *object) {
    return (Z_OBJCE_P(object) == zend_ce_exception || instanceof_function_slow(Z_OBJCE_P(object), zend_ce_exception))
               ? zend_ce_exception
               : zend_ce_error;
}
#define GET_PROPERTY(object, id) \
    zend_read_property_ex(ddtrace_get_exception_base(object), Z_OBJ_P(object), ZSTR_KNOWN(id), 1, &rv)

#endif  // DD_ENGINE_HOOKS_H
