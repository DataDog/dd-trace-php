#include <php.h>
#include <ext/spl/spl_exceptions.h>

#include "ddtrace.h"
#include "dispatch.h"

#include <Zend/zend.h>
#include "compat_zend_string.h"
#include "dispatch_compat.h"

#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include "debug.h"

#define BUSY_FLAG 1

#if PHP_VERSION_ID >= 70100
#define RETURN_VALUE_USED(opline) ((opline)->result_type != IS_UNUSED)
#else
#define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))
#endif

#if PHP_VERSION_ID < 70000
#undef EX
#define EX(x) ((execute_data)->x)
#endif

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

extern user_opcode_handler_t ddtrace_old_fcall_handler;
extern user_opcode_handler_t ddtrace_old_icall_handler;
extern user_opcode_handler_t ddtrace_old_fcall_by_name_handler;

static ddtrace_dispatch_t *lookup_dispatch(const HashTable *lookup, const char *function_name,
                                           uint32_t function_name_length) {
    if (function_name_length == 0) {
        function_name_length = strlen(function_name);
    }

    char *key = zend_str_tolower_dup(function_name, function_name_length);
    ddtrace_dispatch_t *dispatch = NULL;
    dispatch = zend_hash_str_find_ptr(lookup, key, function_name_length);

    efree(key);
    return dispatch;
}

static ddtrace_dispatch_t *find_dispatch(const char *scope_name, uint32_t scope_name_length, const char *function_name,
                                         uint32_t function_name_length TSRMLS_DC) {
    if (!function_name) {
        return NULL;
    }
    HashTable *class_lookup = NULL;

    class_lookup = zend_hash_str_find_ptr(&DDTRACE_G(class_lookup), scope_name, scope_name_length);

    if (!class_lookup) {
        DD_PRINTF("Dispatch Lookup for class: %s", scope_name);
        return NULL;
    }

    return lookup_dispatch(class_lookup, function_name, function_name_length);
}

static void execute_fcall(ddtrace_dispatch_t *dispatch, zend_execute_data *execute_data,
                          zval **return_value_ptr TSRMLS_DC) {
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    char *error = NULL;
    zval closure, rv, *rv_ptr;
    INIT_ZVAL(closure);
    INIT_ZVAL(rv);
    rv_ptr = &rv;

    zval **result_ptr = return_value_ptr;// ? return_value_ptr : &rv_ptr;
    zval *this = NULL;

    zend_function *func;
#if PHP_VERSION_ID < 70000
    func = datadog_current_function(execute_data);

    if (dispatch->clazz) {
        this = datadog_this(func, execute_data);
    }

    zend_function *callable = (zend_function *)zend_get_closure_method_def(&dispatch->callable TSRMLS_CC);

    // convert passed callable to not be static as we're going to bind it to *this
    if (this) {
        callable->common.fn_flags &= ~ZEND_ACC_STATIC;
    }

    zend_create_closure(&closure, callable, dispatch->clazz, this TSRMLS_CC);
#else
    func = EX(func);
    this = Z_OBJ(EX(This)) ? &EX(This) : NULL;
    zend_create_closure(&closure, (zend_function *)zend_get_closure_method_def(&dispatch->callable), dispatch->clazz,
                        dispatch->clazz, this TSRMLS_CC);
#endif

    if (zend_fcall_info_init(&closure, 0, &fci, &fcc, NULL, &error TSRMLS_CC) != SUCCESS) {
        if (!DDTRACE_G(ignore_missing_overridables)) {
            if (func->common.scope) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                        "cannot set override for %s::%s - %s", STRING_VAL(func->common.scope->name),
                                        STRING_VAL(func->common.function_name), error);
            } else {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC, "cannot set override for %s - %s",
                                        STRING_VAL(func->common.function_name), error);
            }
        }

        if (error) {
            efree(error);
        }
        goto _exit_cleanup;
    }

    ddtrace_setup_fcall(execute_data, &fci, result_ptr TSRMLS_CC);
            DD_PRINTF("WTF");

    if (zend_call_function(&fci, &fcc TSRMLS_CC) == SUCCESS) {

            DD_PRINTF("WTF^3");

        if (!return_value_ptr) {
            DD_PRINTF("WTF");
            // zval_dtor(&rv);
        }
    } else {
            DD_PRINTF("WTF^2");

    }
                DD_PRINTF("WTF^2");


#if PHP_VERSION_ID < 70000
    if (fci.params) {
        efree(fci.params);
    }
#endif

_exit_cleanup:
#if PHP_VERSION_ID < 70000
    if (this) {
        Z_DELREF_P(this);
    }
#endif

    zval_dtor(&closure);
}

static int is_anonymous_closure(zend_function *fbc, const char *function_name, uint32_t *function_name_length_p) {
    if (!(fbc->common.fn_flags & ZEND_ACC_CLOSURE) || !function_name_length_p) {
        return 0;
    }

    if (*function_name_length_p == 0) {
        *function_name_length_p = strlen(function_name);
    }

    if ((*function_name_length_p == (sizeof("{closure}") - 1)) && strcmp(function_name, "{closure}") == 0) {
        return 1;
    } else {
        return 0;
    }
}

static zend_always_inline zend_bool executing_method(zend_execute_data *execute_data, zval *object) {
#if PHP_VERSION_ID < 70000
    return EX(opline)->opcode != ZEND_DO_FCALL && object;
#else
    return execute_data && object;
#endif
}

static zend_always_inline zend_bool wrap_and_run(zend_execute_data *execute_data, zend_function *fbc,
                                                 const char *function_name, uint32_t function_name_length TSRMLS_DC) {
    zval *object = NULL;
    const char *common_scope = NULL;
    uint32_t common_scope_length = 0;

    if (fbc->common.scope) {
#if PHP_VERSION_ID < 70000
        object = EG(This);
        // if (!object && EX(object)) {
        //     object = EX(call)->object;
        // }
        if (!object && OBJECT()) {
            object = OBJECT();
        }

        common_scope = fbc->common.scope->name;
        common_scope_length = fbc->common.scope->name_length;
#else
        object = &EX(This);
        common_scope = ZSTR_VAL(fbc->common.scope->name);
        common_scope_length = ZSTR_LEN(fbc->common.scope->name);
#endif
    }

    ddtrace_dispatch_t *dispatch;

    if (executing_method(execute_data, object)) {
        DD_PRINTF("Looking for handler for %s#%s", common_scope, function_name);
        dispatch = find_dispatch(common_scope, common_scope_length, function_name, function_name_length TSRMLS_CC);
    } else {
        dispatch = lookup_dispatch(&DDTRACE_G(function_lookup), function_name, function_name_length);
    }

    if (!dispatch) {
        DD_PRINTF("Handler for %s not found", function_name);
    } else if (dispatch->flags & BUSY_FLAG) {
        DD_PRINTF("Handler for %s is BUSY", function_name);
    }

    if (dispatch && (dispatch->flags ^ BUSY_FLAG)) {
        const zend_op *opline = EX(opline);
        zval rv;
        INIT_ZVAL(rv);

        dispatch->flags ^= BUSY_FLAG;  // guard against recursion, catching only topmost execution

#define EX_T(offset) (*(temp_variable *)((char *) EX(Ts) + offset))
#if PHP_VERSION_ID < 70000
        zval **return_value = NULL;
        DD_PRINTF("ehlo");
        execute_fcall(dispatch, execute_data, return_value TSRMLS_CC);
                            DD_PRINTF("OHSHIT");

        if (return_value != NULL) {
            // EX_TMP_VAR(execute_data, opline->result.var)->var.ptr = return_value;
            // EX_T(opline->result.var).var.ptr_ptr = return_value;
                    DD_PRINTF("OHSHIT");

        }

        EG(return_value_ptr_ptr) = return_value;

#else
        zval *return_value = (RETURN_VALUE_USED(opline) ? EX_VAR(EX(opline)->result.var) : &rv);
        execute_fcall(dispatch, EX(call), &return_value TSRMLS_CC);
#endif

        dispatch->flags ^= BUSY_FLAG;

        // if (!RETURN_VALUE_USED(opline)) {
        //     zval_dtor(&rv);
        // }

        return 1;
    } else {
        return 0;
    }
}

static zend_always_inline zend_bool get_wrappable_function(zend_execute_data *execute_data, zend_function **fbc_p,
                                                           char const **function_name_p,
                                                           uint32_t *function_name_length_p) {
    zend_function *fbc = NULL;
    const char *function_name = NULL;
    uint32_t function_name_length = 0;

#if PHP_VERSION_ID < 70000
    if (EX(opline)->opcode == ZEND_DO_FCALL_BY_NAME) {
        fbc = FBC();
        function_name = fbc->common.function_name;
        function_name_length = 0;
    } else {
        fbc = EX(function_state).function;
        function_name = Z_STRVAL_P(EX(opline)->op1.zv);
        function_name_length = Z_STRLEN_P(EX(opline)->op1.zv);
    }
#else
    fbc = FBC();
    // fbc = EX(call)->func;
    if (fbc->common.function_name) {
        function_name = ZSTR_VAL(fbc->common.function_name);
        function_name_length = ZSTR_LEN(fbc->common.function_name);
    }
#endif

    if (!function_name) {
        DD_PRINTF("No function name, skipping lookup");
        return 0;
    }

    if (!fbc) {
        DD_PRINTF("No function obj found, skipping lookup");
        return 0;
    }

    if (is_anonymous_closure(fbc, function_name, &function_name_length)) {
        DD_PRINTF("Anonymous closure, skipping lookup");
        return 0;
    }

    *fbc_p = fbc;
    *function_name_p = function_name;
    *function_name_length_p = function_name_length;
    return 1;
}

static int update_opcode_leave(zend_execute_data *execute_data TSRMLS_DC) {
#if PHP_VERSION_ID < 70000
    zend_vm_stack_clear_multiple(TSRMLS_CC);
    // EX(call)--;
#else
    EX(call) = EX(call)->prev_execute_data;
#endif
    EX(opline) = EX(opline) + 1;

    return ZEND_USER_OPCODE_LEAVE;
}

int ddtrace_wrap_fcall(zend_execute_data *execute_data TSRMLS_DC) {
    const char *function_name = NULL;
    uint32_t function_name_length = 0;
    zend_function *fbc = NULL;

    DD_PRINTF("OPCODE: %s", zend_get_opcode_name(EX(opline)->opcode));

    if (get_wrappable_function(execute_data, &fbc, &function_name, &function_name_length) &&
        wrap_and_run(execute_data, fbc, function_name, function_name_length TSRMLS_CC)) {
        return update_opcode_leave(execute_data TSRMLS_CC);
    }

    if (EX(opline)->opcode == ZEND_DO_FCALL_BY_NAME) {
        if (ddtrace_old_fcall_by_name_handler) {
            return ddtrace_old_fcall_by_name_handler(execute_data TSRMLS_CC);
        }
    } else {
        if (ddtrace_old_fcall_handler) {
            return ddtrace_old_fcall_handler(execute_data TSRMLS_CC);
        }
    }

    return ZEND_USER_OPCODE_DISPATCH;
}
