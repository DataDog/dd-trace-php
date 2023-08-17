#ifndef HAVE_ZAI_SYMBOLS_API_CALL_H
#define HAVE_ZAI_SYMBOLS_API_CALL_H

static inline bool zai_symbol_call(
                        zai_symbol_scope_t scope_type, void *scope,
                        zai_symbol_function_t function_type, void *function,
                        zval *rv	,
                        uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result = zai_symbol_call_impl(scope_type, scope, function_type, function, rv, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_known(
                    zai_symbol_scope_t scope_type, void *scope,
                    zend_function *function,
                    zval *rv,
                    uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(scope_type, scope, ZAI_SYMBOL_FUNCTION_KNOWN, function, rv, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_named(
                    zai_symbol_scope_t scope_type, void *scope,
                    zai_str *function,
                    zval *rv,
                    uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(scope_type, scope, ZAI_SYMBOL_FUNCTION_NAMED, function, rv, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_static(zend_class_entry *scope, zai_str function, zval *rv, uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(ZAI_SYMBOL_SCOPE_CLASS, scope, ZAI_SYMBOL_FUNCTION_NAMED, &function, rv, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_global(zai_str vfn,
                                           zval *rv,
                                           uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_NAMED, &vfn, rv, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_ns(
    zai_str vns,
    zai_str vfn,
    zval *rv,
    uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(ZAI_SYMBOL_SCOPE_NAMESPACE, &vns, ZAI_SYMBOL_FUNCTION_NAMED, &vfn, rv, argc, &args);
    va_end(args);

    return result;
}

#endif
