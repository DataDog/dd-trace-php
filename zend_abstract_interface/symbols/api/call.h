#ifndef HAVE_ZAI_SYMBOLS_API_CALL_H
#define HAVE_ZAI_SYMBOLS_API_CALL_H
// clang-format off
static inline bool zai_symbol_call(
                        zai_symbol_scope_t scope_type, void *scope,
                        zai_symbol_function_t function_type, void *function,
                        zval **rv ZAI_TSRMLS_DC,
                        uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result = zai_symbol_call_impl(scope_type, scope, function_type, function, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_known(
                    zai_symbol_scope_t scope_type, void *scope,
                    zend_function *function,
                    zval **rv ZAI_TSRMLS_DC,
                    uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(scope_type, scope, ZAI_SYMBOL_FUNCTION_KNOWN, function, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_named(
                    zai_symbol_scope_t scope_type, void *scope,
                    zai_string_view *function,
                    zval **rv ZAI_TSRMLS_DC,
                    uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(scope_type, scope, ZAI_SYMBOL_FUNCTION_NAMED, function, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_static(zend_class_entry *scope, zai_string_view *function, zval **rv ZAI_TSRMLS_DC, uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(ZAI_SYMBOL_SCOPE_CLASS, scope, ZAI_SYMBOL_FUNCTION_NAMED, function, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_static_literal(zend_class_entry *scope,
                                                  const char *fn, size_t fnl,
                                                  zval **rv ZAI_TSRMLS_DC,
                                                  uint32_t argc, ...) {
    zai_string_view vfn =
        (zai_string_view) {fnl, fn};
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(ZAI_SYMBOL_SCOPE_CLASS, scope, ZAI_SYMBOL_FUNCTION_NAMED, &vfn, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_method(zval *zv, zai_string_view *function, zval **rv ZAI_TSRMLS_DC, uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(ZAI_SYMBOL_SCOPE_OBJECT, zv, ZAI_SYMBOL_FUNCTION_NAMED, function, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_method_literal(zval *zv, const char *fn, size_t fnl, zval **rv ZAI_TSRMLS_DC, uint32_t argc, ...) {
    zai_string_view vfn =
        (zai_string_view) {fnl, fn};
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(ZAI_SYMBOL_SCOPE_OBJECT, zv, ZAI_SYMBOL_FUNCTION_NAMED, &vfn, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_literal(
                    const char *fn, size_t fnl,
                    zval **rv ZAI_TSRMLS_DC,
                    uint32_t argc, ...) {
    zai_string_view vfn =
        (zai_string_view) {fnl, fn};
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_NAMED, &vfn, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}

static inline bool zai_symbol_call_literal_ns(
                    const char *ns, size_t nsl,
                    const char *fn, size_t fnl,
                    zval **rv ZAI_TSRMLS_DC,
                    uint32_t argc, ...) {
    zai_string_view vns =
        (zai_string_view) {nsl, ns};
    zai_string_view vfn =
        (zai_string_view) {fnl, fn};
    bool result;

    va_list args;
    va_start(args, argc);
    result =
        zai_symbol_call_impl(ZAI_SYMBOL_SCOPE_NAMESPACE, &vns, ZAI_SYMBOL_FUNCTION_NAMED, &vfn, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}
// clang-format on
#endif
