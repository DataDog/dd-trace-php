#pragma GCC diagnostic push
#pragma GCC diagnostic ignored "-Wunused-parameter"
#include <php.h>

#include <zend_closures.h>
#if PHP_VERSION_ID >= 50500
#include <zend_generators.h>
#endif

#include <hook/hook.h>

#include "uhook.h"

#if PHP_VERSION_ID < 70000
#define ZEND_OBJECT_HEADER(name) zend_object name;
#define ZEND_OBJECT_FOOTER(name)
#define ZEND_CREATE_OBJECT_RETURN zend_object_value
#define ZEND_CREATE_OBJECT_ARGS zend_class_entry *type ZAI_TSRMLS_DC
#define ZEND_CREATE_OBJECT_SIZE(structure) sizeof(structure)
#define ZEND_FREE_STORAGE_ARGS void *object ZAI_TSRMLS_DC
#else
#define ZEND_OBJECT_HEADER(name)
#define ZEND_OBJECT_FOOTER(name) zend_object name;
#define ZEND_CREATE_OBJECT_RETURN zend_object *
#define ZEND_CREATE_OBJECT_ARGS zend_class_entry *type
#define ZEND_CREATE_OBJECT_SIZE(structure) (sizeof(structure) + zend_object_properties_size(type))
#define ZEND_FREE_STORAGE_ARGS zend_object *object
#endif

/* {{{ DDTrace\Hook\Data */
typedef struct {
    ZEND_OBJECT_HEADER(std)
    /* how exactly do we want to do this ? */
    zval span;
    zval rv;
    ZEND_OBJECT_FOOTER(std)
} ddtrace_hook_data;

#if PHP_VERSION_ID >= 70000
#define ddtrace_hook_data_fetch_object(object) \
    ((ddtrace_hook_data *)(((char *)object) - XtOffsetOf(ddtrace_hook_data, std)))
#define ddtrace_hook_data_fetch_zval(zv) ddtrace_hook_data_fetch_object(Z_OBJ_P(zv))
#else
#define ddtrace_hook_data_fetch_object(object) ((ddtrace_hook_data *)object)
#define ddtrace_hook_data_fetch_zval(zv) ddtrace_hook_data_fetch_object(zend_object_store_get_object(zv ZAI_TSRMLS_CC))
#endif

zend_class_entry *ddtrace_hook_data_ce;
zend_object_handlers ddtrace_hook_data_handlers;

static void ddtrace_hook_data_free_storage(ZEND_FREE_STORAGE_ARGS) {
    ddtrace_hook_data *hd = ddtrace_hook_data_fetch_object(object);

    zval_dtor(&hd->span);
    zval_dtor(&hd->rv);

    zend_object_std_dtor(object ZAI_TSRMLS_CC);
#if PHP_VERSION_ID < 70000
    efree(hd);
#endif
}

// clang-format off
static ZEND_CREATE_OBJECT_RETURN ddtrace_hook_data_create(ZEND_CREATE_OBJECT_ARGS) {
    ddtrace_hook_data *hd = ecalloc(1, ZEND_CREATE_OBJECT_SIZE(ddtrace_hook_data));

    zend_object_std_init(&hd->std, type ZAI_TSRMLS_CC);

    object_properties_init(&hd->std, type);

#if PHP_VERSION_ID >= 70000
    hd->std.handlers = &ddtrace_hook_data_handlers;

    return &hd->std;
#else
    return (zend_object_value) {
        .handle = zend_objects_store_put(
            hd, NULL,
            ddtrace_hook_data_free_storage, NULL ZAI_TSRMLS_CC),
        .handlers = &ddtrace_hook_data_handlers
    };
#endif
}
// clang-format on

ZEND_BEGIN_ARG_INFO_EX(zai_uhook_void_arginfo, 0, 0, 0)
ZEND_END_ARG_INFO()

static PHP_METHOD(DDTrace_Hook_Data, getSpanData) {
    ddtrace_hook_data *hd = ddtrace_hook_data_fetch_zval(getThis());

#if PHP_VERSION_ID >= 70000
    ZVAL_COPY(return_value, &hd->span);
#else
    ZVAL_COPY_VALUE(return_value, &hd->span);

    zval_copy_ctor(return_value);
#endif
}

static PHP_METHOD(DDTrace_Hook_Data, getReturnValue) {
    ddtrace_hook_data *hd = ddtrace_hook_data_fetch_zval(getThis());

#if PHP_VERSION_ID >= 70000
    ZVAL_COPY(return_value, &hd->rv);
#else
    ZVAL_COPY_VALUE(return_value, &hd->rv);

    zval_copy_ctor(return_value);
#endif
}

const zend_function_entry DDTrace_Hook_Data_methods[] = {
    // clang-format off
    PHP_ME(DDTrace_Hook_Data, getSpanData,    zai_uhook_void_arginfo, ZEND_ACC_PUBLIC)
    PHP_ME(DDTrace_Hook_Data, getReturnValue, zai_uhook_void_arginfo, ZEND_ACC_PUBLIC)
    PHP_FE_END
    // clang-format on
}; /* }}} */

/* {{{ */
// clang-format off
static inline bool zai_uhook_install_prologue(INTERNAL_FUNCTION_PARAMETERS,
    zai_string_view *scope, zai_string_view *name, zval **resolved, zend_class_entry **rtype, zval **begin, zval **end) {

    switch (ZEND_NUM_ARGS()) {
        case 4:
            if (zend_parse_parameters_ex(
                    ZEND_PARSE_PARAMS_QUIET, 4 ZAI_TSRMLS_CC, "ssOO",
                    &scope->ptr, &scope->len,
                    &name->ptr, &name->len,
                    begin, zend_ce_closure,
                    end, zend_ce_closure) != SUCCESS) {
                return false;
            }
            break;

        case 3:
            if (zend_parse_parameters_ex(
                    ZEND_PARSE_PARAMS_QUIET, 3 ZAI_TSRMLS_CC, "ssO",
                    &scope->ptr, &scope->len,
                    &name->ptr, &name->len,
                    begin, zend_ce_closure) != SUCCESS &&
                zend_parse_parameters_ex(
                    ZEND_PARSE_PARAMS_QUIET, 3 ZAI_TSRMLS_CC, "sOO",
                    &name->ptr, &name->len,
                    begin, zend_ce_closure,
                    end, zend_ce_closure) != SUCCESS) {

                if (zend_parse_parameters_ex(
                        ZEND_PARSE_PARAMS_QUIET, 3 ZAI_TSRMLS_CC, "OOO",
                        resolved, zend_ce_closure,
                        begin, zend_ce_closure,
                        end, zend_ce_closure) == SUCCESS) {
                    *rtype = zend_ce_closure;
                    return true;
                }
#if PHP_VERSION_ID >= 50500
                if (zend_parse_parameters_ex(
                        ZEND_PARSE_PARAMS_QUIET, 3 ZAI_TSRMLS_CC, "OOO",
                        resolved, zend_ce_generator,
                        begin, zend_ce_closure,
                        end, zend_ce_closure) == SUCCESS) {
                    *rtype = zend_ce_generator;
                    return true;
                }
#endif
                return false;
            }
            break;

        case 2:
            if (zend_parse_parameters_ex(
                    ZEND_PARSE_PARAMS_QUIET, 2 ZAI_TSRMLS_CC, "sO",
                    &name->ptr, &name->len,
                    begin, zend_ce_closure) != SUCCESS) {
                if (zend_parse_parameters_ex(
                        ZEND_PARSE_PARAMS_QUIET, 2 ZAI_TSRMLS_CC, "OO",
                        resolved, zend_ce_closure,
                        begin, zend_ce_closure) == SUCCESS) {
                    *rtype = zend_ce_closure;
                    return true;
                }
#if PHP_VERSION_ID >= 50500
                if (zend_parse_parameters_ex(
                        ZEND_PARSE_PARAMS_QUIET, 2 ZAI_TSRMLS_CC, "OO",
                        resolved, zend_ce_generator,
                        begin, zend_ce_closure) == SUCCESS) {
                    *rtype = zend_ce_generator;
                    return true;
                }
#endif
                return false;
            }
            break;

        default:
            /* throw */
            return false;
    }

    return true;
}
// clang-format on
/* }}} */

static void zai_uhook_constructor(zval *zv) {
    ZAI_TSRMLS_FETCH();

    object_init_ex(zv, ddtrace_hook_data_ce);

    ddtrace_hook_data *hd = ddtrace_hook_data_fetch_zval(zv);

    /* do stuff to it */
    (void)hd;
}

// clang-format off

static zend_function* zai_uhook_resolve(zval *target, zend_class_entry *type ZAI_TSRMLS_DC) {
    if (type == zend_ce_closure) {
#if PHP_VERSION_ID >= 80000
        zend_function *closure = (zend_function*)
            zend_get_closure_method_def(Z_OBJ_P(target));
#else
        zend_function *closure = (zend_function*)
            zend_get_closure_method_def(target ZAI_TSRMLS_CC);
#endif
        return closure;
    }

#if PHP_VERSION_ID >= 50500
    if (type == zend_ce_generator) {
#if PHP_VERSION_ID >= 70000
        zend_generator *generator =
            (zend_generator*) Z_OBJ_P(target);

        return generator->execute_data->func;
#else
        zend_generator *generator =
            (zend_generator*)
                zend_object_store_get_object(
                    target ZAI_TSRMLS_CC);

        return generator->execute_data->function_state.function;
#endif
    }
#endif

    /* unreachable */
    return NULL;
}

/* {{{ proto bool DDTrace\Hook\install(string function, Closure begin)
       proto bool DDTrace\Hook\install(string function, Closure begin, Closure end)
       proto bool DDTrace\Hook\install(string class, string method, Closure begin)
       proto bool DDTrace\Hook\install(string class, string method, Closure begin, Closure end)
       proto bool DDTrace\Hook\install(Closure target, Closure begin)
       proto bool DDTrace\Hook\install(Closure target, Closure begin, Closure end)
       proto bool DDTrace\Hook\install(Generator target, Closure begin)
       proto bool DDTrace\Hook\install(Generator target, Closure begin, Closure end) */
static ZEND_NAMED_FUNCTION(zai_uhook_install) {
    zai_string_view scope = ZAI_STRING_EMPTY;
    zai_string_view name  = ZAI_STRING_EMPTY;
    zval *target = NULL;
    zend_class_entry *type = NULL;
    zval *begin = NULL;
    zval *end = NULL;

    if (!zai_uhook_install_prologue(INTERNAL_FUNCTION_PARAM_PASSTHRU, &scope, &name, &target, &type, &begin, &end)) {
        /* throw something ? */
        return;
    }

    if (target) {
        zend_function *resolved = zai_uhook_resolve(target, type ZAI_TSRMLS_CC);

        RETURN_BOOL(zai_hook_install_resolved(
            ZAI_HOOK_USER,
            begin ?
                ZAI_HOOK_BEGIN_USER(*begin) :
                ZAI_HOOK_UNUSED(begin),
            end ?
                ZAI_HOOK_END_USER(*end) :
                ZAI_HOOK_UNUSED(end),
            ZAI_HOOK_AUX_USER(zai_uhook_constructor),
            0, resolved ZAI_TSRMLS_CC));
    }

    RETURN_BOOL(zai_hook_install(
        ZAI_HOOK_USER,
        scope, name,
        begin ?
            ZAI_HOOK_BEGIN_USER(*begin) :
            ZAI_HOOK_UNUSED(begin),
        end ?
            ZAI_HOOK_END_USER(*end) :
            ZAI_HOOK_UNUSED(end),
        ZAI_HOOK_AUX_USER(zai_uhook_constructor),
        0 ZAI_TSRMLS_CC));
} /* }}} */
// clang-foramt on

/* {{{ DDTrace\Hook */
ZEND_BEGIN_ARG_INFO_EX(zai_uhook_install_arginfo, 0, 0, 0)
#if defined(ZEND_ARG_VARIADIC_INFO)
ZEND_ARG_VARIADIC_INFO(0, args)
#endif
ZEND_END_ARG_INFO()

// clang-format off
const zend_function_entry DDTrace_Hook_functions[] = {
    ZEND_NS_NAMED_FE("DDTrace\\Hook", install, zai_uhook_install, zai_uhook_install_arginfo)
    PHP_FE_END
};
// clang-foramt on
/* }}} */

void zai_uhook_minit() {
    ZAI_TSRMLS_FETCH();

    memcpy(&ddtrace_hook_data_handlers, &std_object_handlers, sizeof(zend_object_handlers));

#if PHP_VERSION_ID >= 70000
    ddtrace_hook_data_handlers.free_obj = ddtrace_hook_data_free_storage;
#endif

    zend_class_entry ce_hook_data;
    INIT_NS_CLASS_ENTRY(ce_hook_data, "DDTrace\\Hook", "Data", DDTrace_Hook_Data_methods);
    ddtrace_hook_data_ce = zend_register_internal_class(&ce_hook_data ZAI_TSRMLS_CC);
    ddtrace_hook_data_ce->create_object = ddtrace_hook_data_create;

    zend_register_functions(NULL, DDTrace_Hook_functions, NULL, MODULE_PERSISTENT ZAI_TSRMLS_CC);
}

// clang-format off
void zai_uhook_mshutdown() {
    ZAI_TSRMLS_FETCH();

    zend_unregister_functions(
        DDTrace_Hook_functions,
        sizeof(DDTrace_Hook_functions) / sizeof(zend_function_entry) - 1,
        NULL ZAI_TSRMLS_CC);
}
// clang-format on

#pragma GCC diagnostic pop
