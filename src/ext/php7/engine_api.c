#include "engine_api.h"

extern inline zval ddtrace_zval_long(zend_long num);
extern inline zval ddtrace_zval_null(void);

zval ddtrace_zval_stringl(const char *str, size_t len) {
    zval zv;
    ZVAL_STRINGL(&zv, str, len);
    return zv;
}

// Do not pass things like "parent", "self", "static" -- fully qualified names only!
zend_class_entry *ddtrace_lookup_ce(const char *str, size_t len) {
    zend_string *name = zend_string_init(str, len, 0);
#if PHP_VERSION_ID < 70400
    zend_class_entry *ce = zend_lookup_class_ex(name, NULL, 0);
#else
    zend_class_entry *ce = zend_lookup_class_ex(name, NULL, ZEND_FETCH_CLASS_NO_AUTOLOAD);
#endif
    zend_string_release(name);
    return ce;
}

/**
 * Calls the method `fname` on the object, or static method on the `ce` if no object.
 * If you only have 0-2 args, consider zend_call_method_with_{0..2}_params instead
 *
 * @param obj May be null
 * @param ce Must not be null
 * @param fn_proxy The result of the method lookup is cached here, unless NULL
 * @param fname Must not be null
 * @param retval Must not be null
 * @param argc The number of items in @param argv
 * @param argv This is a plain C array e.g. `zval args[argc]`
 * @return
 */
ZEND_RESULT_CODE ddtrace_call_method(zend_object *obj, zend_class_entry *ce, zend_function **fn_proxy,
                                     const char *fname, size_t fname_len, zval *retval, int argc, zval *argv) {
    zend_function *method;
    if (fn_proxy && *fn_proxy) {
        method = *fn_proxy;
    } else {
        zend_string *zstr = zend_string_init(fname, fname_len, 0);
        method = obj ? obj->handlers->get_method(&obj, zstr, NULL) : zend_std_get_static_method(ce, zstr, NULL);
        if (fn_proxy) {
            *fn_proxy = method;
        }
        zend_string_release(zstr);
    }

    zend_fcall_info fci = {
        .size = sizeof(zend_fcall_info),
        .retval = retval,
        .params = argv,
        .object = obj,
        /* Must be 0 to allow for by-ref args
         * BUT if you use by-ref args, you need to free the ref if it gets separated!
         */
        .no_separation = 0,
        .param_count = argc,
    };
    ZVAL_STR(&fci.function_name, method->common.function_name);

    zend_fcall_info_cache fcc = {
#if PHP_VERSION_ID < 70300
        .initialized = 1,
#endif
        .function_handler = method,
        .calling_scope = ce,
        .called_scope = ce,
        .object = obj,
    };

    ZEND_RESULT_CODE result = zend_call_function(&fci, &fcc);
    return result;
}

ZEND_RESULT_CODE ddtrace_call_function(const char *name, size_t name_len, zval *retval, int argc, zval argv[]) {
    zval fname = ddtrace_zval_stringl(name, name_len);
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    zend_fcall_info_init(&fname, IS_CALLABLE_CHECK_SILENT, &fci, &fcc, NULL, NULL);
    fci.retval = retval;
    fci.params = argv;
    fci.no_separation = 0;  // allow for by-ref args
    fci.param_count = argc;
    ZEND_RESULT_CODE result = zend_call_function(&fci, &fcc);
    zend_string_release(Z_STR(fname));
    return result;
}

void ddtrace_write_property(zval *obj, const char *prop, size_t prop_len, zval *value) {
    zval member = ddtrace_zval_stringl(prop, prop_len);
    // the underlying API doesn't tell you if it worked _shrug_
    Z_OBJ_P(obj)->handlers->write_property(obj, &member, value, NULL);
    zend_string_release(Z_STR(member));
}

// Modeled after PHP's property_exists for the Z_TYPE_P(object) == IS_OBJECT case
bool ddtrace_property_exists(zval *object, zval *property) {
    zend_class_entry *ce;
    zend_property_info *property_info;

    ZEND_ASSERT(Z_TYPE_P(object) == IS_OBJECT);
    ZEND_ASSERT(Z_TYPE_P(property) == IS_STRING);

    ce = Z_OBJCE_P(object);
    property_info = zend_hash_find_ptr(&ce->properties_info, Z_STR_P(property));
#if PHP_VERSION_ID < 70400
    if (property_info && (property_info->flags & ZEND_ACC_SHADOW) == 0) {
        return true;
    }

    if (Z_OBJ_HANDLER_P(object, has_property) && Z_OBJ_HANDLER_P(object, has_property)(object, property, 2, NULL)) {
        return true;
    }
#else
    if (property_info && (!(property_info->flags & ZEND_ACC_PRIVATE) || property_info->ce == ce)) {
        return true;
    }

    if (Z_OBJ_HANDLER_P(object, has_property)(object, property, 2, NULL)) {
        return true;
    }
#endif
    return false;
}

ZEND_RESULT_CODE ddtrace_read_property(zval *dest, zval *obj, const char *prop, size_t prop_len) {
    zval rv, member = ddtrace_zval_stringl(prop, prop_len);
    if (ddtrace_property_exists(obj, &member)) {
        zval *result = Z_OBJ_P(obj)->handlers->read_property(obj, &member, BP_VAR_R, NULL, &rv);
        zend_string_release(Z_STR(member));
        if (result) {
            ZVAL_COPY(dest, result);
            zval_ptr_dtor(result);
            return SUCCESS;
        }
    }
    zend_string_release(Z_STR(member));
    return FAILURE;
}
