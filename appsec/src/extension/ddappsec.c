// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <SAPI.h>
#include <Zend/zend_extensions.h>
#include <ext/standard/info.h>
#include <php.h>

// for open(2)
#include <dlfcn.h>
#include <fcntl.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/types.h>

#include <pthread.h>
#include <stdatomic.h>

#include "backtrace.h"
#include "commands/client_init.h"
#include "commands/config_sync.h"
#include "commands/request_exec.h"
#include "commands/request_init.h"
#include "commands/request_shutdown.h"
#include "commands_ctx.h"
#include "configuration.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "ddtrace.h"
#include "entity_body.h"
#include "helper_process.h"
#include "ip_extraction.h"
#include "logging.h"
#include "network.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "request_abort.h"
#include "request_lifecycle.h"
#include "string_helpers.h"
#include "tags.h"
#include "user_tracking.h"
#include "msgpack_helpers.h"

#include <json/json.h>

#if ZTS
static atomic_int _thread_count;
#endif

static void _check_enabled(void);
#ifdef TESTING
static void _register_testing_objects(void);
#endif

static PHP_MINIT_FUNCTION(ddappsec);
static PHP_MSHUTDOWN_FUNCTION(ddappsec);
static PHP_RINIT_FUNCTION(ddappsec);
static PHP_RSHUTDOWN_FUNCTION(ddappsec);
static PHP_MINFO_FUNCTION(ddappsec);
static PHP_GINIT_FUNCTION(ddappsec);
static PHP_GSHUTDOWN_FUNCTION(ddappsec);
static int ddappsec_startup(zend_extension *extension);
#if PHP_VERSION_ID < 80000
int _post_deactivate(void);
#else
zend_result _post_deactivate(void);
#endif

ZEND_DECLARE_MODULE_GLOBALS(ddappsec)

// clang-format off
static const  zend_module_dep _ddappsec_deps[] = {
    ZEND_MOD_OPTIONAL("ddtrace")
    ZEND_MOD_END
};

static zend_module_entry ddappsec_module_entry = {
    STANDARD_MODULE_HEADER_EX,
    NULL,
    _ddappsec_deps,
    PHP_DDAPPSEC_EXTNAME,
    NULL,
    PHP_MINIT(ddappsec),
    PHP_MSHUTDOWN(ddappsec),
    PHP_RINIT(ddappsec),
    PHP_RSHUTDOWN(ddappsec),
    PHP_MINFO(ddappsec),
    PHP_DDAPPSEC_VERSION,
    PHP_MODULE_GLOBALS(ddappsec),
    PHP_GINIT(ddappsec),
    PHP_GSHUTDOWN(ddappsec),
    _post_deactivate,
    STANDARD_MODULE_PROPERTIES_EX
};

static zend_extension ddappsec_extension_entry = {
    PHP_DDAPPSEC_EXTNAME,
    PHP_DDAPPSEC_VERSION,
    "Datadog",
    "https://github.com/DataDog/dd-appsec-php",
    "Copyright Datadog",
    ddappsec_startup,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    STANDARD_ZEND_EXTENSION_PROPERTIES};
// clang-format on

ZEND_GET_MODULE(ddappsec)

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static void ddappsec_sort_modules(void *base, size_t count, size_t siz,
    compare_func_t compare, swap_func_t swp)
{
    UNUSED(siz);
    UNUSED(compare);
    UNUSED(swp);

    // Reorder ddappsec to ensure it's always after ddtrace
    for (Bucket *module = base, *end = module + count, *ddappsec_module = NULL;
         module < end; ++module) {
        zend_module_entry *m = (zend_module_entry *)Z_PTR(module->val);
        if (m->name == ddappsec_module_entry.name) {
            ddappsec_module = module;
            continue;
        }
        if (ddappsec_module && strcmp(m->name, "ddtrace") == 0) {
            Bucket tmp = *ddappsec_module;
            *ddappsec_module = *module;
            *module = tmp;
            break;
        }
    }
}

static int ddappsec_startup(zend_extension *extension)
{
    UNUSED(extension);

    zend_hash_sort_ex(&module_registry, ddappsec_sort_modules, NULL, 0);
    return SUCCESS;
}

// GINIT/GSHUTDOWN run before/after MINIT/MSHUTDOWN
static PHP_GINIT_FUNCTION(ddappsec)
{
#if defined(ZTS)
    TSRMLS_CACHE = tsrm_get_ls_cache();
#endif

#if ZTS
    atomic_fetch_add(&_thread_count, 1);
#endif

    memset(ddappsec_globals, '\0', sizeof(*ddappsec_globals)); // NOLINT
    ddappsec_globals->to_be_configured = true;
}

static PHP_GSHUTDOWN_FUNCTION(ddappsec)
{
    dd_entity_body_gshutdown();
    dd_helper_gshutdown();
    // delay log shutdown until the last possible moment, so that TSRM
    // destructors can run with logging
#if ZTS
    int prev = atomic_fetch_add(&_thread_count, -1);
    if (prev == 1) {
        dd_log_shutdown();
        zai_config_mshutdown();
        zai_json_shutdown_bindings();
    }
#else
    dd_log_shutdown();
    zai_config_mshutdown();
    zai_json_shutdown_bindings();
#endif

    memset(ddappsec_globals, '\0', sizeof(*ddappsec_globals)); // NOLINT
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static PHP_MINIT_FUNCTION(ddappsec)
{
    UNUSED(type);

    zend_module_entry *mod_ptr = zend_hash_str_find_ptr(&module_registry,
        PHP_DDAPPSEC_EXTNAME, sizeof(PHP_DDAPPSEC_EXTNAME) - 1);
    zend_register_extension(&ddappsec_extension_entry, mod_ptr->handle);
    mod_ptr->handle = NULL;

    dd_phpobj_startup(module_number);

    if (!dd_config_minit(module_number)) {
        return FAILURE;
    }

    DDAPPSEC_G(enabled) = APPSEC_ENABLED_VIA_REMCFG;

    dd_log_startup();

#ifdef TESTING
    _register_testing_objects();
#endif

    dd_helper_startup();
    dd_trace_startup();
    dd_req_lifecycle_startup();
    dd_user_tracking_startup();
    dd_request_abort_startup();
    dd_tags_startup();
    dd_ip_extraction_startup();
    dd_entity_body_startup();
    dd_backtrace_startup();
    dd_msgpack_helpers_startup();

    return SUCCESS;
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static PHP_MSHUTDOWN_FUNCTION(ddappsec)
{
    UNUSED(type);
    UNUSED(module_number);

    // no other thread is running now. reset config to global config only.
    runtime_config_first_init = false;

    dd_tags_shutdown();
    dd_user_tracking_shutdown();
    dd_trace_shutdown();
    dd_helper_shutdown();

    dd_phpobj_shutdown();

    return SUCCESS;
}

static void _rinit_once() { dd_config_first_rinit(); }

void dd_appsec_rinit_once()
{
    static pthread_once_t _rinit_once_control = PTHREAD_ONCE_INIT;
    pthread_once(&_rinit_once_control, _rinit_once);
}

// NOLINTNEXTLINE
static PHP_RINIT_FUNCTION(ddappsec)
{
    UNUSED(type);
    UNUSED(module_number);

    // Safety precaution
    DDAPPSEC_G(during_request_shutdown) = false;

    dd_appsec_rinit_once();
    zai_config_rinit();
    _check_enabled();

    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED) {
        return SUCCESS;
    }
    DDAPPSEC_G(skip_rshutdown) = false;

    dd_entity_body_rinit();

    dd_req_lifecycle_rinit(false);

    if (UNEXPECTED(get_global_DD_APPSEC_TESTING())) {
        if (get_global_DD_APPSEC_TESTING_ABORT_RINIT()) {
            const char *pt = SG(request_info).path_translated;
            if (pt && !strstr(pt, "skip.php")) {
                dd_request_abort_static_page();
            }
        }
    }

    return SUCCESS;
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static PHP_RSHUTDOWN_FUNCTION(ddappsec)
{
    UNUSED(type);
    UNUSED(module_number);

    DDAPPSEC_G(during_request_shutdown) = true;

    ZEND_RESULT_CODE result = SUCCESS;

    // Here now we have to disconnect from the helper in all the cases but when
    // disabled by config
    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED) {
        goto exit;
    }

    if (DDAPPSEC_G(skip_rshutdown)) {
        goto exit;
    }

    result = dd_appsec_rshutdown(false);

exit:
    DDAPPSEC_G(during_request_shutdown) = false;
    return result;
}

int dd_appsec_rshutdown(bool ignore_verdict)
{
    dd_req_lifecycle_rshutdown(ignore_verdict, false);
    return SUCCESS;
}

#if PHP_VERSION_ID < 80000
int _post_deactivate(void)
#else
zend_result _post_deactivate(void)
#endif
{
    // zai config may be accessed indirectly via other modules RSHUTDOWN, so
    // delay this until the last possible time
    zai_config_rshutdown();
    return SUCCESS;
}

static PHP_MINFO_FUNCTION(ddappsec)
{
    php_info_print_box_start(0);
    PUTS("Datadog PHP AppSec extension");
    PUTS(!sapi_module.phpinfo_as_text ? "<br>" : "\n");
    PUTS("(c) Datadog 2021\n");
    php_info_print_box_end();

    php_info_print_table_start();
    php_info_print_table_row(2, "State managed by remote config",
        DDAPPSEC_G(enabled) == APPSEC_ENABLED_VIA_REMCFG ? "Yes" : "No");
    php_info_print_table_row(2, "Current state",
        DDAPPSEC_G(active)             ? "Enabled"
        : DDAPPSEC_G(to_be_configured) ? "Not configured"
                                       : "Disabled");
    php_info_print_table_row(2, "Version", PHP_DDAPPSEC_VERSION);
    php_info_print_table_row(
        2, "Connected to helper?", dd_helper_mgr_cur_conn() ? "Yes" : "No");
    php_info_print_table_end();

    DISPLAY_INI_ENTRIES();
}

#ifdef ZTS
__thread void *unspecnull TSRMLS_CACHE = NULL;
#endif

static void _check_enabled()
{
    if ((!get_global_DD_APPSEC_TESTING() && !dd_trace_enabled()) ||
        (strcmp(sapi_module.name, "cli") != 0 && sapi_module.phpinfo_as_text) ||
        (strcmp(sapi_module.name, "frankenphp") == 0)) {
        DDAPPSEC_G(enabled) = APPSEC_FULLY_DISABLED;
        DDAPPSEC_G(active) = false;
        DDAPPSEC_G(to_be_configured) = false;
    } else if (!dd_cfg_enable_via_remcfg()) {
        DDAPPSEC_G(enabled) = get_DD_APPSEC_ENABLED() ? APPSEC_FULLY_ENABLED
                                                      : APPSEC_FULLY_DISABLED;
        DDAPPSEC_G(active) = get_DD_APPSEC_ENABLED() ? true : false;
        DDAPPSEC_G(to_be_configured) = false;
    } else {
        DDAPPSEC_G(enabled) = APPSEC_ENABLED_VIA_REMCFG;
        // leave DDAPPSEC_G(active) as is
    };
}

static PHP_FUNCTION(datadog_appsec_is_enabled)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    RETURN_BOOL(DDAPPSEC_G(active));
}

static PHP_FUNCTION(datadog_appsec_testing_rinit)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    mlog(dd_log_debug, "Running rinit actions");
    dd_req_lifecycle_rinit(true);
    RETURN_TRUE;
}

static PHP_FUNCTION(datadog_appsec_testing_rshutdown)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    DDAPPSEC_G(during_request_shutdown) = true;
    mlog(dd_log_debug, "Running rshutdown actions");
    dd_req_lifecycle_rshutdown(false, true);
    DDAPPSEC_G(during_request_shutdown) = false;
    RETURN_TRUE;
}
static PHP_FUNCTION(datadog_appsec_testing_helper_mgr_acquire_conn)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    struct req_info ctx = {
        .root_span = dd_trace_get_active_root_span(),
    };
    dd_conn *conn =
        dd_helper_mgr_acquire_conn((client_init_func)dd_client_init, &ctx);
    if (conn) {
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}

static PHP_FUNCTION(datadog_appsec_testing_stop_for_debugger)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    int fd = open(
        "/tmp/pid", O_CREAT | O_WRONLY | O_TRUNC | O_CLOEXEC, 0600); // NOLINT
    char pid[sizeof("-2147483648")] = "";
    sprintf(pid, "%" PRIi32, (int32_t)getpid()); // NOLINT
    ATTR_UNUSED ssize_t unused_ = write(fd, pid, strlen(pid));
    sleep(6); // NOLINT

    RETURN_TRUE;
}

static PHP_FUNCTION(datadog_appsec_testing_request_exec)
{
    zval *data = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &data) != SUCCESS) {
        RETURN_FALSE;
    }

    struct req_info ctx = {
        .root_span = dd_trace_get_active_root_span(),
    };
    dd_conn *conn =
        dd_helper_mgr_acquire_conn((client_init_func)dd_client_init, &ctx);
    if (conn == NULL) {
        mlog_g(dd_log_debug, "No connection; skipping request_exec");
        RETURN_FALSE;
    }

    if (dd_request_exec(conn, data) != dd_success) {
        RETURN_FALSE;
    }

    RETURN_TRUE;
}

static PHP_FUNCTION(datadog_appsec_push_address)
{
    UNUSED(return_value);
    if (!DDAPPSEC_G(active)) {
        mlog(dd_log_debug, "Trying to access to push_address "
                           "function while appsec is disabled");
        return;
    }

    zend_string *key = NULL;
    zval *value = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "Sz", &key, &value) == FAILURE) {
        RETURN_FALSE;
    }

    zval parameters_zv;
    zend_array *parameters_arr = zend_new_array(1);
    ZVAL_ARR(&parameters_zv, parameters_arr);
    zend_hash_add(Z_ARRVAL(parameters_zv), key, value);
    Z_TRY_ADDREF_P(value);

    dd_conn *conn = dd_helper_mgr_cur_conn();
    if (conn == NULL) {
        zval_ptr_dtor(&parameters_zv);
        mlog_g(dd_log_debug, "No connection; skipping push_address");
        return;
    }

    dd_result res = dd_request_exec(conn, &parameters_zv);
    zval_ptr_dtor(&parameters_zv);

    if (dd_req_is_user_req()) {
        if (res == dd_should_block || res == dd_should_redirect) {
            dd_req_call_blocking_function(res);
        }
    } else {
        if (res == dd_should_block) {
            dd_request_abort_static_page();
        } else if (res == dd_should_redirect) {
            dd_request_abort_redirect();
        }
    }
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(request_exec_arginfo, 0, 1, _IS_BOOL, 0)
ZEND_ARG_INFO(0, "data")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(push_address_arginfo, 0, 0, IS_VOID, 1)
ZEND_ARG_INFO(0, key)
ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_APPSEC_NS "is_enabled", PHP_FN(datadog_appsec_is_enabled), void_ret_bool_arginfo, 0)
    ZEND_RAW_FENTRY(DD_APPSEC_NS "push_address", PHP_FN(datadog_appsec_push_address), push_address_arginfo, 0)
    PHP_FE_END
};
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "rinit", PHP_FN(datadog_appsec_testing_rinit), void_ret_bool_arginfo, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "rshutdown", PHP_FN(datadog_appsec_testing_rshutdown), void_ret_bool_arginfo, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "helper_mgr_acquire_conn", PHP_FN(datadog_appsec_testing_helper_mgr_acquire_conn), void_ret_bool_arginfo, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "stop_for_debugger", PHP_FN(datadog_appsec_testing_stop_for_debugger), void_ret_bool_arginfo, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "request_exec", PHP_FN(datadog_appsec_testing_request_exec), request_exec_arginfo, 0)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects()
{
    dd_phpobj_reg_funcs(functions);

    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }

    dd_phpobj_reg_funcs(testing_functions);
}
