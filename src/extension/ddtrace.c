// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "ddtrace.h"
#include <fcntl.h>
#include <unistd.h>

#include "configuration.h"
#include "ddappsec.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "string_helpers.h"

static int (*_orig_ddtrace_shutdown)(SHUTDOWN_FUNC_ARGS);
static int _mod_type;
static int _mod_number;
static zend_string *_ddtrace_root_span_fname;
static zend_string *_meta_propname;
static zend_string *_metrics_propname;
static THREAD_LOCAL_ON_ZTS bool _suppress_ddtrace_rshutdown;

static zend_module_entry *_find_ddtrace_module(void);
static int _ddtrace_rshutdown_testing(SHUTDOWN_FUNC_ARGS);
static void _register_testing_objects(void);

static bool (*nullable _ddtrace_root_span_add_tag)(zend_string *, zval *);

static void dd_trace_load_symbols(void)
{
    bool testing = get_global_DD_APPSEC_TESTING();
    void *handle = dlopen(NULL, RTLD_NOW | RTLD_GLOBAL);
    if (handle == NULL) {
        if (!testing) {
            // NOLINTNEXTLINE(concurrency-mt-unsafe)
            mlog(dd_log_error, "Failed load process symbols: %s", dlerror());
        }
        return;
    }

    _ddtrace_root_span_add_tag = dlsym(handle, "ddtrace_root_span_add_tag");
    if (_ddtrace_root_span_add_tag == NULL && !testing) {
        // NOLINTNEXTLINE(concurrency-mt-unsafe)
        mlog(dd_log_error, "Failed to load _ddtrace_root_span_add_tag: %s",
            dlerror());
    }
    dlclose(handle);
}

void dd_trace_startup()
{
    dd_trace_load_symbols();

    _ddtrace_root_span_fname = zend_string_init_interned(
        LSTRARG("ddtrace\\root_span"), 1 /* permanent */);
    _meta_propname = zend_string_init_interned(LSTRARG("meta"), 1);
    _metrics_propname = zend_string_init_interned(LSTRARG("metrics"), 1);

    if (get_global_DD_APPSEC_TESTING()) {
        _register_testing_objects();
    }

    zend_module_entry *mod = _find_ddtrace_module();
    if (!mod) {
        mlog(dd_log_debug, "Cannot find ddtrace extension");
        return;
    }
    _mod_type = mod->type;
    _mod_number = mod->module_number;

    if (get_global_DD_APPSEC_TESTING()) {
        _orig_ddtrace_shutdown = mod->request_shutdown_func;
        mod->request_shutdown_func = _ddtrace_rshutdown_testing;
    }
}

static zend_module_entry *_find_ddtrace_module()
{
    zend_string *ddtrace_name =
        zend_string_init("ddtrace", LSTRLEN("ddtrace"), 0 /* persistent */);
    zend_module_entry *mod = zend_hash_find_ptr(&module_registry, ddtrace_name);
    zend_string_free(ddtrace_name);
    return mod;
}

void dd_trace_shutdown()
{
    zend_module_entry *mod = _find_ddtrace_module();
    if (mod) {
        mod->request_shutdown_func = _orig_ddtrace_shutdown;
    }
    _orig_ddtrace_shutdown = NULL;
    _mod_type = 0;
    _mod_number = 0;
}

static int _ddtrace_rshutdown_testing(SHUTDOWN_FUNC_ARGS)
{
    if (_suppress_ddtrace_rshutdown) {
        _suppress_ddtrace_rshutdown = false;
        return SUCCESS;
    }

    return _orig_ddtrace_shutdown(SHUTDOWN_FUNC_ARGS_PASSTHRU);
}

bool dd_trace_root_span_add_tag(zend_string *nonnull tag, zval *nonnull value)
{
    if (UNEXPECTED(_ddtrace_root_span_add_tag == NULL)) {
        mlog_g(dd_log_debug,
            "Skipping adding tag '%s'; ddtrace symbol unresolved",
            ZSTR_VAL(tag));
        return false;
    }

    if (Z_TYPE_P(value) == IS_STRING) {
        mlog(dd_log_debug, "Adding to root span the tag '%s' with value '%s'",
            ZSTR_VAL(tag), Z_STRVAL_P(value));
    } else {
        mlog(dd_log_debug, "Adding to root span the tag '%s'", ZSTR_VAL(tag));
    }

    bool res = (*_ddtrace_root_span_add_tag)(tag, value);
    if (!res) {
        zval_ptr_dtor(value);
    }

    return res;
}

bool dd_trace_root_span_add_tag_str(const char *nonnull tag, size_t tag_len,
    const char *nonnull value, size_t value_len)
{
    if (UNEXPECTED(value_len > INT_MAX)) {
        mlog(dd_log_warning, "Value for tag is too large");
        return false;
    }
    if (UNEXPECTED(_ddtrace_root_span_add_tag == NULL)) {
        mlog_g(dd_log_debug,
            "Skipping adding tag '%.*s'; ddtrace symbol unresolved",
            (int)tag_len, tag);
        return false;
    }

    zend_string *ztag = zend_string_init(tag, tag_len, 0);

    zval zvalue;
    ZVAL_STRINGL(&zvalue, value, value_len);

    mlog(dd_log_debug, "Adding to root span the tag '%.*s' with value '%.*s'",
        (int)tag_len, tag, (int)value_len, value);

    bool res = (*_ddtrace_root_span_add_tag)(ztag, &zvalue);
    zend_string_release(ztag);

    if (!res) {
        mlog(dd_log_info,
            "Failed adding the tag '%.*s': no root span or it already exists",
            (int)tag_len, tag);
        zval_ptr_dtor(&zvalue);
        return false;
    }

    return true;
}

static zval *nullable _root_span_get_prop(zend_string *propname);

zval *nullable dd_trace_root_span_get_meta()
{
    return _root_span_get_prop(_meta_propname);
}

zval *nullable dd_trace_root_span_get_metrics()
{
    return _root_span_get_prop(_metrics_propname);
}

static zval *nullable _root_span_get_prop(zend_string *propname)
{
    zval retval;
    zend_fcall_info fci = {
        .size = sizeof(fci),
        .retval = &retval,
    };
    static THREAD_LOCAL_ON_ZTS zend_fcall_info_cache fci_cache;

    ZVAL_STR(&fci.function_name, _ddtrace_root_span_fname);

    int res = zend_call_function(&fci, &fci_cache);

    // PHP 8.2 silently fails, so we need to check if there's an exception
    // after the function call and suppress it.
    zend_object *exc = EG(exception);
    if (res != SUCCESS || exc) {
        mlog(get_global_DD_APPSEC_TESTING() ? dd_log_debug : dd_log_warning,
            "Call to \\ddtrace\\root_span failed");
        if (exc) {
            mlog(dd_log_debug, "Ignoring raised exception");
            zval zv;
            ZVAL_OBJ(&zv, exc);
            zval_ptr_dtor(&zv);
            EG(exception) = NULL;
        }
        return NULL;
    }
    if (Z_TYPE(retval) != IS_OBJECT) {
        mlog(dd_log_warning, "Expecting an object from \\ddtrace\\root_span");
        goto error;
    }

    __auto_type handlers = Z_OBJ_HT(retval);
    if (!handlers->get_property_ptr_ptr) {
        mlog(dd_log_warning, "Object type has no get_property_ptr_ptr handler");
        goto error;
    }
#if PHP_MAJOR_VERSION >= 8
    zval *prop_val = handlers->get_property_ptr_ptr(
        Z_OBJ(retval), propname, BP_VAR_IS, NULL);
#else
    zval prop;
    ZVAL_STR(&prop, propname);
    zval *prop_val =
        handlers->get_property_ptr_ptr(&retval, &prop, BP_VAR_IS, NULL);
#endif

    if (UNEXPECTED(!prop_val || prop_val == &EG(uninitialized_zval) ||
                   prop_val == &EG(error_zval))) {
        mlog(dd_log_warning, "No property '%s' found on span",
            ZSTR_VAL(propname));
        goto error;
    }
    ZVAL_DEREF(prop_val);
    if (Z_TYPE_P(prop_val) != IS_ARRAY) {
        mlog(dd_log_warning, "The property '%s' on span is no array",
            ZSTR_VAL(propname));
        goto error;
    }

    SEPARATE_ARRAY(prop_val);

    zval_ptr_dtor(&retval);
    return prop_val;

error:
    zval_ptr_dtor(&retval);
    return NULL;
}

static PHP_FUNCTION(datadog_appsec_testing_ddtrace_rshutdown)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    if (!_orig_ddtrace_shutdown) {
        mlog(dd_log_warning,
            "ddtrace was not searched or found during startup; skipping");
        RETURN_FALSE;
    }

    _suppress_ddtrace_rshutdown = true;
    mlog(dd_log_debug, "Calling ddtrace's RSHUTDOWN");
    int res = _orig_ddtrace_shutdown(_mod_type, _mod_number);
    if (res == SUCCESS) {
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}

static PHP_FUNCTION(datadog_appsec_testing_root_span_add_tag)
{
    zend_string *tag = NULL;
    zval *value = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "Sz", &tag, &value) != SUCCESS) {
        RETURN_FALSE;
    }

    if (!tag || !value || Z_TYPE_P(value) != IS_STRING) {
        RETURN_FALSE;
    }

    Z_TRY_ADDREF_P(value);
    bool result = dd_trace_root_span_add_tag(tag, value);

    RETURN_BOOL(result);
}

static PHP_FUNCTION(datadog_appsec_testing_root_span_get_meta) // NOLINT
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    zval *meta_zv = dd_trace_root_span_get_meta();
    if (meta_zv) {
        RETURN_ZVAL(meta_zv, 1 /* copy */, 0 /* no destroy original */);
    }
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_nullable_array, 0, 0, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_root_span_add_tag, 0, 0, _IS_BOOL, 2)
ZEND_ARG_TYPE_INFO(0, tag, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "ddtrace_rshutdown", PHP_FN(datadog_appsec_testing_ddtrace_rshutdown), void_ret_bool_arginfo, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "root_span_add_tag", PHP_FN(datadog_appsec_testing_root_span_add_tag), arginfo_root_span_add_tag, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "root_span_get_meta", PHP_FN(datadog_appsec_testing_root_span_get_meta), void_ret_nullable_array, 0)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects() { dd_phpobj_reg_funcs(functions); }
