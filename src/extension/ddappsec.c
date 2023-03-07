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

#include "commands/client_init.h"
#include "commands/request_exec.h"
#include "commands/request_init.h"
#include "commands/request_shutdown.h"
#include "configuration.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "ddtrace.h"
#include "helper_process.h"
#include "ip_extraction.h"
#include "logging.h"
#include "network.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "request_abort.h"
#include "src/extension/commands/config_sync.h"
#include "src/extension/user_tracking.h"
#include "string_helpers.h"
#include "tags.h"

#if ZTS
static atomic_int _thread_count;
#endif

static int _do_rinit(INIT_FUNC_ARGS);
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
}

static PHP_GSHUTDOWN_FUNCTION(ddappsec)
{
    dd_helper_gshutdown();
    // delay log shutdown until the last possible moment, so that TSRM
    // destructors can run with logging
#if ZTS
    int prev = atomic_fetch_add(&_thread_count, -1);
    if (prev == 1) {
        dd_log_shutdown();
        zai_config_mshutdown();
    }
#else
    dd_log_shutdown();
    zai_config_mshutdown();
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

    DDAPPSEC_G(enabled) = NOT_CONFIGURED;

    dd_log_startup();

#ifdef TESTING
    _register_testing_objects();
#endif

    dd_helper_startup();
    dd_trace_startup();
    dd_user_tracking_startup();
    dd_request_abort_startup();
    dd_tags_startup();
    dd_ip_extraction_startup();

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

static pthread_once_t _rinit_once_control = PTHREAD_ONCE_INIT;

static void _rinit_once()
{
    dd_config_first_rinit();
    dd_request_abort_rinit_once();
}

static PHP_RINIT_FUNCTION(ddappsec)
{
    pthread_once(&_rinit_once_control, _rinit_once);
    zai_config_rinit();

    //_check_enabled should be run only once. However, pthread_once approach
    // does not work with ZTS.
    if (DDAPPSEC_G(enabled) == NOT_CONFIGURED) {
        mlog_g(
            dd_log_trace, "Enabled not configured, computing enabled status");
        _check_enabled();
    }

    if (DDAPPSEC_G(enabled_by_configuration) == DISABLED) {
        return SUCCESS;
    }
    DDAPPSEC_G(skip_rshutdown) = false;

    dd_ip_extraction_rinit();

    if (UNEXPECTED(get_global_DD_APPSEC_TESTING())) {
        if (get_global_DD_APPSEC_TESTING_ABORT_RINIT()) {
            const char *pt = SG(request_info).path_translated;
            if (pt && !strstr(pt, "skip.php")) {
                dd_request_abort_static_page();
            }
        }
        return SUCCESS;
    }
    return _do_rinit(INIT_FUNC_ARGS_PASSTHRU);
}

static dd_result _acquire_conn_cb(dd_conn *nonnull conn)
{
    return dd_client_init(conn);
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static int _do_rinit(INIT_FUNC_ARGS)
{
    UNUSED(type);
    UNUSED(module_number);

    if (DDAPPSEC_G(enabled_by_configuration) == DISABLED) {
        return SUCCESS;
    }

    dd_tags_rinit();

    // connect/client_init
    dd_conn *conn = dd_helper_mgr_acquire_conn(_acquire_conn_cb);
    if (conn == NULL) {
        mlog_g(dd_log_debug, "No connection; skipping rest of RINIT");
        return SUCCESS;
    }

    int res = dd_success;
    if (DDAPPSEC_G(enabled) == ENABLED) {
        // request_init
        res = dd_request_init(conn);
    } else {
        // config_sync
        res = dd_config_sync(conn);
        if (res == SUCCESS && DDAPPSEC_G(enabled) == ENABLED) {
            // Since it came as enabled, lets proceed
            res = dd_request_init(conn);
        }
    }
    if (res == dd_network) {
        mlog_g(dd_log_info,
            "request_init/config_sync failed with dd_network; closing "
            "connection to helper");
        dd_helper_close_conn();
    } else if (res == dd_should_block) {
        dd_request_abort_static_page();
    } else if (res == dd_should_redirect) {
        dd_request_abort_redirect();
    } else if (res) {
        mlog_g(
            dd_log_info, "request init failed: %s", dd_result_to_string(res));
    }

    return SUCCESS;
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static PHP_RSHUTDOWN_FUNCTION(ddappsec)
{
    UNUSED(type);
    UNUSED(module_number);

    ZEND_RESULT_CODE result = SUCCESS;

    // Here now we have to disconnect from the helper in all the cases but when
    // disabled by config
    if (DDAPPSEC_G(enabled_by_configuration) == DISABLED) {
        goto exit;
    }

    if (DDAPPSEC_G(skip_rshutdown)) {
        goto exit;
    }

    if (UNEXPECTED(get_global_DD_APPSEC_TESTING())) {
        dd_tags_rshutdown_testing();
        goto exit;
    }

    result = dd_appsec_rshutdown();

exit:
    dd_ip_extraction_rshutdown();
    return result;
}

int dd_appsec_rshutdown()
{
    dd_conn *conn = dd_helper_mgr_cur_conn();
    if (conn && DDAPPSEC_G(enabled) == ENABLED) {
        int res = dd_request_shutdown(conn);
        if (res == dd_network) {
            mlog_g(dd_log_info,
                "request_shutdown failed with dd_network; closing "
                "connection to helper");
            dd_helper_close_conn();
        } else if (res == dd_should_block) {
            dd_request_abort_static_page();
        } else if (res) {
            mlog_g(dd_log_info, "request shutdown failed: %s",
                dd_result_to_string(res));
        }
    }

    dd_helper_rshutdown();

    if (DDAPPSEC_G(enabled) == ENABLED) {
        dd_tags_add_tags();
    }
    dd_tags_rshutdown();

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
    php_info_print_table_row(2, "Datadog AppSec support",
        DDAPPSEC_G(enabled) == ENABLED ? "enabled" : "disabled");
    php_info_print_table_row(2, "Version", PHP_DDAPPSEC_VERSION);
    php_info_print_table_end();

    DISPLAY_INI_ENTRIES();
}

#ifdef ZTS
__thread void *unspecnull TSRMLS_CACHE = NULL;
#endif

static void _check_enabled()
{
    if ((strcmp(sapi_module.name, "cli") == 0 || sapi_module.phpinfo_as_text) &&
        !get_global_DD_APPSEC_TESTING()) {
        DDAPPSEC_G(enabled_by_configuration) = DISABLED;
    } else if (!dd_is_config_using_default(DDAPPSEC_CONFIG_DD_APPSEC_ENABLED)) {
        DDAPPSEC_G(enabled_by_configuration) =
            get_global_DD_APPSEC_ENABLED() ? ENABLED : DISABLED;
    } else {
        DDAPPSEC_G(enabled_by_configuration) = NOT_CONFIGURED;
    };

    // If not enabled explicitly and RC is disabled, then extension is disabled
    if (DDAPPSEC_G(enabled_by_configuration) == NOT_CONFIGURED &&
        !get_global_DD_REMOTE_CONFIG_ENABLED()) {
        DDAPPSEC_G(enabled_by_configuration) = DISABLED;
    }
    DDAPPSEC_G(enabled) = DDAPPSEC_G(enabled_by_configuration);
}

static PHP_FUNCTION(datadog_appsec_is_enabled)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    RETURN_BOOL(DDAPPSEC_G(enabled) == ENABLED);
}

static PHP_FUNCTION(datadog_appsec_testing_rinit)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    mlog(dd_log_debug, "Running rinit actions");
    int res = _do_rinit(MODULE_PERSISTENT, 0 /* we don't use it */);
    if (res == 0) {
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}

static PHP_FUNCTION(datadog_appsec_testing_rshutdown)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    mlog(dd_log_debug, "Running rshutdown actions");
    int res = dd_appsec_rshutdown();
    if (res == 0) {
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}
static PHP_FUNCTION(datadog_appsec_testing_helper_mgr_acquire_conn)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    dd_conn *conn = dd_helper_mgr_acquire_conn(_acquire_conn_cb);
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

    dd_conn *conn = dd_helper_mgr_acquire_conn(_acquire_conn_cb);
    if (conn == NULL) {
        mlog_g(dd_log_debug, "No connection; skipping request_exec");
        RETURN_FALSE;
    }

    if (dd_request_exec(conn, data) != dd_success) {
        RETURN_FALSE;
    }

    RETURN_TRUE;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(request_exec_arginfo, 0, 1, _IS_BOOL, 0)
ZEND_ARG_INFO(0, "data")
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_APPSEC_NS "is_enabled", PHP_FN(datadog_appsec_is_enabled), void_ret_bool_arginfo, 0)
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
