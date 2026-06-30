#include "datadog.h"
#include <SAPI.h>
#include <php.h>
#include <json/json.h>
#include <components-rs/datadog.h>
#include <components-rs/sidecar.h>

#include <tracer/tracer_api.h>

#include "configuration.h"
#include "excluded_modules.h"
#include "agent_info.h"
#include "ffi_utils.h"
#include "logging.h"
#include "phpinfo.h"
#include "process_tags.h"
#include "remote_config.h"
#include "sidecar.h"
#include "signals.h"
#include "startup_logging.h"
#include "telemetry.h"
#include "zend_hrtime.h"
#ifndef _WIN32
#include <pthread.h>
#include <unistd.h>
#else
#include <components/pthread_polyfill.h>
#include "crashtracking_windows.h"
#endif
#include <hook/hook.h>
#include <string.h>
#if PHP_VERSION_ID < 80000
#include <interceptor/php7/interceptor.h>
#endif

bool datadog_has_excluded_module;
zend_module_entry *datadog_module;
static int dd_main_pid;
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
static bool dd_has_other_observers;
static int dd_observer_extension_backup = -1;

void datadog_patch_zend_call_known_function();
#endif

datadog_php_sapi datadog_active_sapi = DATADOG_PHP_SAPI_UNKNOWN;

ddog_CharSlice php_version_rt;

ZEND_DECLARE_MODULE_GLOBALS(datadog)

#ifdef COMPILE_DL_DDTRACE
ZEND_GET_MODULE(datadog)
#ifdef ZTS
TSRM_TLS void *TSRMLS_CACHE = NULL;
#endif
#endif

int datadog_disable = 0; // 0 = enabled, 1 = disabled via INI, 2 = disabled, but MINIT was fully executed
static ZEND_INI_MH(dd_OnUpdateDisabled) {
    UNUSED(entry, mh_arg1, mh_arg2, mh_arg3, stage);
    if (!datadog_disable) {
        datadog_disable = zend_ini_parse_bool(new_value);
    }
    return SUCCESS;
}

PHP_INI_BEGIN()
    ZEND_INI_ENTRY("ddtrace.disable", "0", PHP_INI_SYSTEM, dd_OnUpdateDisabled)

    // Exposed for testing only
    STD_PHP_INI_ENTRY("ddtrace.cgroup_file", "/proc/self/cgroup", PHP_INI_SYSTEM, OnUpdateString, cgroup_file,
                      zend_datadog_globals, datadog_globals)
PHP_INI_END()

#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400
static void datadog_sort_modules(void *base, size_t count, size_t siz, compare_func_t compare, swap_func_t swp) {
    UNUSED(siz);
    UNUSED(compare);
    UNUSED(swp);

    // swap ddtrace and opcache for the rest of the modules lifecycle, so that opcache is always executed after ddtrace
    for (Bucket *module = base, *end = module + count, *datadog_module = NULL; module < end; ++module) {
        zend_module_entry *m = (zend_module_entry *)Z_PTR(module->val);
        if (m->name == datadog_module_entry.name) {
            datadog_module = module;
        }
        if (datadog_module && strcmp(m->name, "Zend OPcache") == 0) {
            Bucket tmp = *datadog_module;
            *datadog_module = *module;
            *module = tmp;
            break;
        }
    }
}
#endif

#ifndef _WIN32
void datadog_signal_block_handlers_startup(void);
#endif
void datadog_pcntl_handlers_startup(void);

// put this into startup so that other extensions running code as part of rinit do not crash
static int datadog_startup(zend_extension *extension) {
    UNUSED(extension);

#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400
    // Turns out with zai config we have dynamically allocated INI entries. This does not play well with PHP 7.3
    // As of PHP 7.3 opcache stores INI entry values in SHM. However, only as of PHP 7.4 opcache delays detaching SHM.
    // In PHP 7.3 SHM is freed in MSHUTDOWN, which may be executed before our extension, if we do not force an order.
    // We have to sort this manually here, as opcache only registers itself as extension during zend_extension.startup.
    zend_hash_sort_ex(&module_registry, datadog_sort_modules, NULL, 0);
#endif

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
    dd_has_other_observers = ZEND_OBSERVER_ENABLED;
#endif

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
#if PHP_VERSION_ID < 80100
#define BUG_STACK_ALLOCATED_CALL_PATCH_VERSION 16
#else
#define BUG_STACK_ALLOCATED_CALL_PATCH_VERSION 3
#endif
    zend_long patch_version = Z_LVAL_P(zend_get_constant_str(ZEND_STRL("PHP_RELEASE_VERSION")));
    if (patch_version < BUG_STACK_ALLOCATED_CALL_PATCH_VERSION) {
        datadog_patch_zend_call_known_function();
    }
#endif

    if (!datadog_disable) {
        datadog_excluded_modules_startup();

        // pcntl handlers have to run even if tracing of pcntl extension is not enabled.
        datadog_pcntl_handlers_startup();

#ifndef _WIN32
        // Block remote-config signals of some functions
        datadog_signal_block_handlers_startup();
#endif
    }

#ifdef DDTRACE
    ddtrace_startup();
#endif

    return SUCCESS;
}

static void datadog_shutdown(zend_extension *extension) {
    UNUSED(extension);

    #ifdef DDTRACE
    ddtrace_shutdown();
#endif
}

static void datadog_publish_configured_otel_process_context(void);

static void dd_activate_once(void) {
    datadog_config_first_rinit();
    if (dd_main_pid != getpid()) { // equal to session id if not a fork
        datadog_generate_runtime_id();
    }
    datadog_publish_configured_otel_process_context();

    // must run before the first zai_hook_activate as tracer telemetry setup installs a global hook
    if (!datadog_disable) {
        // Only set up the sidecar when it's actually needed (appsec, telemetry, trace sender, or OTLP metrics).
        ddog_RemoteConfigFlags flags = {0};
        bool enable_sidecar = datadog_sidecar_should_enable(&flags);
        if (enable_sidecar) {
            datadog_sidecar_setup(flags);
        }
#ifdef DDTRACE
        ddtrace_activate_once();
#endif
    }
}

static void datadog_publish_configured_otel_process_context(void) {
    ddog_CharSlice hostname = DDOG_CHARSLICE_C("");

    if (!get_DD_TRACE_REPORT_HOSTNAME()) {
        datadog_publish_otel_process_context(hostname);
        return;
    }

    if (ZSTR_LEN(get_DD_HOSTNAME())) {
        hostname = dd_zend_string_to_CharSlice(get_DD_HOSTNAME());
        datadog_publish_otel_process_context(hostname);
        return;
    }

    // Match tracer/serializer.c hostname publishing: DD_HOSTNAME wins, then gethostname().
#ifndef HOST_NAME_MAX
#define HOST_NAME_MAX 255
#endif
    char hostname_buf[HOST_NAME_MAX + 1];
    if (gethostname(hostname_buf, sizeof(hostname_buf)) == 0) {
        hostname_buf[HOST_NAME_MAX] = '\0';
        hostname = (ddog_CharSlice){.ptr = hostname_buf, .len = strlen(hostname_buf)};
        datadog_publish_otel_process_context(hostname);
        return;
    }

    datadog_publish_otel_process_context(hostname);
}

static pthread_once_t dd_activate_once_control = PTHREAD_ONCE_INIT;

static bool dd_is_cli_autodisabled(const char *arg) {
    const char *slashend = strrchr(arg, '/');
    const char *backslashend = strrchr(arg, '\\');
    arg = MAX(MAX(slashend, backslashend) + 1, arg);
    return strcmp(arg, "composer") == 0 || strcmp(arg, "composer.phar") == 0;
}

static void datadog_activate(void) {
    ddog_reset_logger();

    if (!datadog_disable && datadog_has_excluded_module == true) {
        datadog_disable = 2;
    }

    datadog_telemetry_rinit();

#ifdef DDTRACE
    ddtrace_activate_early();
#endif

    // ZAI config is always set up
    pthread_once(&dd_activate_once_control, dd_activate_once);
    zai_config_rinit();

    if (!datadog_disable) {
        datadog_sidecar_ensure_active();
    }

    datadog_sidecar_activate();

    if (!datadog_disable && strcmp(sapi_module.name, "cli") == 0) {
        if (zai_config_memoized_entries[DATADOG_CONFIG_DD_TRACE_CLI_ENABLED].name_index == ZAI_CONFIG_ORIGIN_DEFAULT && SG(request_info).argv && dd_is_cli_autodisabled(SG(request_info).argv[0])) {
            zend_string *zero = zend_string_init("0", 1, 0);
            zend_alter_ini_entry(zai_config_memoized_entries[DATADOG_CONFIG_DD_TRACE_CLI_ENABLED].ini_entries[0]->name, zero,
                                 ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
            zend_string_release(zero);
        }
        if (!get_DD_TRACE_CLI_ENABLED()) {
            datadog_disable = 2;
        }
    }

#ifdef DDTRACE
    ddtrace_activate_late();
#endif
}

static void datadog_deactivate(void) {
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
    if (dd_observer_extension_backup != -1) {
        zend_observer_fcall_op_array_extension = dd_observer_extension_backup;
        dd_observer_extension_backup = -1;
    }
#endif
}

static zend_extension dd_zend_extension_entry = {"ddtrace",
                                                  PHP_DDTRACE_VERSION,
                                                  "Datadog",
                                                  "https://github.com/DataDog/dd-trace-php",
                                                  "Copyright Datadog",
                                                  datadog_startup,
                                                  datadog_shutdown,
                                                  datadog_activate,
                                                  datadog_deactivate,
                                                  NULL,
#if PHP_VERSION_ID < 80000 && defined(DDTRACE)
                                                  zai_interceptor_op_array_pass_two,
#else
                                                  NULL,
#endif
                                                  NULL,
                                                  NULL,
                                                  NULL,
#if PHP_VERSION_ID < 80000 && defined(DDTRACE)
                                                  zai_interceptor_op_array_ctor,
#else
                                                  NULL,
#endif
#ifdef DDTRACE
                                                  zai_hook_unresolve_op_array,
#else
                                                  NULL,
#endif
                                                  STANDARD_ZEND_EXTENSION_PROPERTIES};

static void php_datadog_init_globals(zend_datadog_globals *ng) { memset(ng, 0, sizeof(zend_datadog_globals)); }

static PHP_GINIT_FUNCTION(datadog) {
#if defined(COMPILE_DL_DDTRACE) && defined(ZTS)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    php_datadog_init_globals(datadog_globals);
#if ZTS
    datadog_thread_ginit();
#endif
    datadog_globals->sidecar_universal_service_tags_mutex = tsrm_mutex_alloc();
    zend_hash_init(&datadog_globals->git_metadata, 8, unused, (dtor_func_t)datadog_git_metadata_dtor, 1);

#ifdef DDTRACE
    ddtrace_ginit(datadog_globals);
#endif
}

// Rust code will call __cxa_thread_atexit_impl. This is a weak symbol; it's defined by glibc.
// The problem is that calls to __cxa_thread_atexit_impl cause shared libraries to remain referenced until the calling thread terminates.
// However in NTS builds the calling thread is the main thread and thus the shared object (i.e. ddtrace.so) WILL remain loaded.
// This is problematic with e.g. apache which, upon reloading will unload all PHP modules including PHP itself, then reload.
// This prevents us from a) having the weak symbols updated to the new locations and b) having ddtrace updates going live without hard restart.
// Thus, we need to intercept it: define it ourselves so that the linker will force the rust code to call our code here.
// Then we can collect the callbacks and invoke them ourselves right at thread shutdown, i.e. GSHUTDOWN.
#ifdef CXA_THREAD_ATEXIT_WRAPPER
#define CXA_THREAD_ATEXIT_PHP ((void *)0)
#define CXA_THREAD_ATEXIT_UNINITIALIZED ((void *)1)
#define CXA_THREAD_ATEXIT_UNAVAILABLE ((void *)2)

static int (*glibc__cxa_thread_atexit_impl)(void (*func)(void *), void *obj, void *dso_symbol) = CXA_THREAD_ATEXIT_UNINITIALIZED;
static pthread_key_t dd_cxa_thread_atexit_key; // fallback for sidecar

struct dd_rust_thread_destructor {
    void (*dtor)(void *);
    void *obj;
    struct dd_rust_thread_destructor *next;
};
// Use __thread explicitly: ZEND_TLS is empty on NTS builds.
static __thread struct dd_rust_thread_destructor *dd_rust_thread_destructors = NULL;
ZEND_TLS bool dd_is_main_thread = false;

void dd_run_rust_thread_destructors(void *unused) {
    UNUSED(unused);
    struct dd_rust_thread_destructor *entry = dd_rust_thread_destructors;
    dd_rust_thread_destructors = NULL; // destructors _may_ be invoked multiple times. We need to reset thus.
    while (entry) {
        struct dd_rust_thread_destructor *cur = entry;
        cur->dtor(cur->obj);
        entry = entry->next;
        free(cur);
    }
}

// Note: this symbol is not public
int __cxa_thread_atexit_impl(void (*func)(void *), void *obj, void *dso_symbol) {
    if (glibc__cxa_thread_atexit_impl == CXA_THREAD_ATEXIT_UNINITIALIZED) {
        glibc__cxa_thread_atexit_impl = NULL; // DL_FETCH_SYMBOL(RTLD_DEFAULT, "__cxa_thread_atexit_impl");
        if (glibc__cxa_thread_atexit_impl == NULL) {
            // no race condition here: logging is initialized in MINIT, at which point only a single thread lives
            glibc__cxa_thread_atexit_impl = CXA_THREAD_ATEXIT_UNAVAILABLE;
            pthread_key_create(&dd_cxa_thread_atexit_key, dd_run_rust_thread_destructors);
        }
    }

    if (glibc__cxa_thread_atexit_impl != CXA_THREAD_ATEXIT_PHP && glibc__cxa_thread_atexit_impl != CXA_THREAD_ATEXIT_UNAVAILABLE) {
        return glibc__cxa_thread_atexit_impl(func, obj, dso_symbol);
    }

    if (glibc__cxa_thread_atexit_impl == CXA_THREAD_ATEXIT_UNAVAILABLE) {
        pthread_setspecific(dd_cxa_thread_atexit_key, (void *)0x1); // needs to be non-NULL
    }

    struct dd_rust_thread_destructor *entry = malloc(sizeof(struct dd_rust_thread_destructor));
    entry->dtor = func;
    entry->obj = obj;
    entry->next = dd_rust_thread_destructors;
    dd_rust_thread_destructors = entry;
    return 0;
}

static void dd_clean_main_thread_locals() {
    dd_run_rust_thread_destructors(NULL);
}
#endif

static PHP_GSHUTDOWN_FUNCTION(datadog) {
#if ZTS
    datadog_thread_gshutdown();
#endif
    if (datadog_globals->remote_config_state) {
        ddog_shutdown_remote_config(datadog_globals->remote_config_state);
    }
    if (datadog_globals->agent_info_reader) {
        ddog_drop_agent_info_reader(datadog_globals->agent_info_reader);
    }
    if (datadog_globals->telemetry_buffer) {
        ddog_sidecar_telemetry_buffer_drop(datadog_globals->telemetry_buffer);
    }

    if (datadog_globals->telemetry_cache) {
        ddog_sidecar_telemetry_cache_drop(datadog_globals->telemetry_cache);
    }

    zend_hash_destroy(&datadog_globals->git_metadata);

#ifdef DDTRACE
    ddtrace_gshutdown(datadog_globals);
#endif

    // Drop the per-thread sidecar transport (thread-lifetime, one per thread).
    datadog_sidecar_gshutdown(datadog_globals);

    tsrm_mutex_free(datadog_globals->sidecar_universal_service_tags_mutex);

#ifdef CXA_THREAD_ATEXIT_WRAPPER
    // FrankenPHP calls `ts_free_thread()` in rshutdown
    if (!dd_is_main_thread && datadog_active_sapi != DATADOG_PHP_SAPI_FRANKENPHP) {
        dd_run_rust_thread_destructors(NULL);
    }
#endif
}

static bool dd_is_compatible_sapi() {
    switch (datadog_active_sapi) {
        case DATADOG_PHP_SAPI_APACHE2HANDLER:
        case DATADOG_PHP_SAPI_CGI_FCGI:
        case DATADOG_PHP_SAPI_CLI:
        case DATADOG_PHP_SAPI_CLI_SERVER:
        case DATADOG_PHP_SAPI_FPM_FCGI:
        case DATADOG_PHP_SAPI_FRANKENPHP:
        case DATADOG_PHP_SAPI_TEA:
            return true;

        default:
            return false;
    }
}

static void dd_disable_if_incompatible_sapi_detected(void) {
    if (UNEXPECTED(!dd_is_compatible_sapi())) {
        LOG(WARN, "Incompatible SAPI detected '%s'; disabling ddtrace", sapi_module.name);
        datadog_disable = 1;
    }
}

static PHP_MINIT_FUNCTION(datadog) {
    UNUSED(type);
    zval *php_version = zend_get_constant_str(ZEND_STRL("PHP_VERSION"));
    if (php_version && Z_TYPE_P(php_version) == IS_STRING) {
        php_version_rt = (ddog_CharSlice){Z_STRVAL_P(php_version), Z_STRLEN_P(php_version)};
    } else {
        zend_error(E_CORE_WARNING, "Failed to get PHP_VERSION constant");
        return FAILURE;
    }

    datadog_active_sapi = datadog_php_sapi_from_name(datadog_php_string_view_from_cstr(sapi_module.name));

#ifdef CXA_THREAD_ATEXIT_WRAPPER
    // FrankenPHP calls `ts_free_thread()` in rshutdown
    if (datadog_active_sapi != DATADOG_PHP_SAPI_FRANKENPHP) {
        dd_is_main_thread = true;
        glibc__cxa_thread_atexit_impl = CXA_THREAD_ATEXIT_PHP;
        atexit(dd_clean_main_thread_locals);
    }
#endif

    // Reset on every minit for `apachectl graceful`.
    dd_activate_once_control = (pthread_once_t)PTHREAD_ONCE_INIT;


#if PHP_VERSION_ID < 70300 || (defined(_WIN32) && PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400)
    datadog_startup_hrtime();
#endif

    REGISTER_INI_ENTRIES();

    zval *datadog_module_zv = zend_hash_str_find(&module_registry, ZEND_STRL("ddtrace"));
    if (datadog_module_zv) {
        datadog_module = Z_PTR_P(datadog_module_zv);
    }

    dd_main_pid = getpid();
    datadog_generate_session_id();

    // config initialization needs to be at the top
    // This also initialiyzed logging, so no logs may be emitted before this.
    datadog_log_init();
#ifdef DDTRACE
    ddtrace_pre_config_minit();
#endif
    if (!datadog_config_minit(module_number)) {
        return FAILURE;
    }

    dd_disable_if_incompatible_sapi_detected();

#ifdef DDTRACE
    ddtrace_minit_early(module_number);
#endif

    /* This allows an extension (e.g. extension=ddtrace.so) to have zend_engine
     * hooks too, but not loadable as zend_extension=ddtrace.so.
     * See http://www.phpinternalsbook.com/php7/extensions_design/zend_extensions.html#hybrid-extensions
     * {{{ */
    zend_register_extension(&dd_zend_extension_entry, datadog_module_entry.handle);
    // The original entry is copied into the module registry when the module is
    // registered, so we search the module registry to find the right
    // zend_module_entry to modify.
    zend_module_entry *mod_ptr =
        zend_hash_str_find_ptr(&module_registry, PHP_DDTRACE_EXTNAME, sizeof(PHP_DDTRACE_EXTNAME) - 1);
    if (mod_ptr == NULL) {
        // This shouldn't happen, possibly a bug if it does.
        zend_error(E_CORE_WARNING,
                   "Failed to find ddtrace extension in "
                   "registered modules. Please open a bug report.");

        return FAILURE;
    }
    mod_ptr->handle = NULL;
    /* }}} */

    if (datadog_disable) {
        return SUCCESS;
    }

#ifndef _WIN32
    datadog_set_coredumpfilter();
#endif

    datadog_log_minit();

    datadog_sidecar_minit();

#ifdef DDTRACE
    ddtrace_minit_late();
#endif

    datadog_minit_remote_config();

#ifndef _WIN32
    datadog_signals_minit();
#endif
    ddtrace_set_container_cgroup_path((ddog_CharSlice){ .ptr = DATADOG_G(cgroup_file), .len = strlen(DATADOG_G(cgroup_file)) });

    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(datadog) {
    UNUSED(module_number, type);

    UNREGISTER_INI_ENTRIES();

#ifdef DDTRACE
    ddtrace_mshutdown();
#endif

    if (datadog_disable == 1) {
        zai_config_mshutdown();
        zai_json_shutdown_bindings();
        return SUCCESS;
    }

    datadog_mshutdown_remote_config();

#ifndef _WIN32
    datadog_signals_mshutdown();
#endif

    datadog_log_mshutdown();

    zai_config_mshutdown();
    zai_json_shutdown_bindings();

    datadog_sidecar_shutdown();

    datadog_process_tags_mshutdown();

    return SUCCESS;
}

static void dd_rinit_once(void) {
    // Collect process tags now that script path is available
    if (get_global_DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED()) {
        datadog_process_tags_first_rinit();
        datadog_sidecar_update_process_tags();
    }

    // Uses config, cannot run earlier
#ifndef _WIN32
    datadog_signals_first_rinit();
#else
    if (get_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_CRASHTRACKING_ENABLED()) {
        datadog_init_crash_tracking();
    }
#endif

    datadog_startup_logging_first_rinit();

#ifdef DDTRACE
    ddtrace_first_rinit();
#endif
}

static pthread_once_t dd_rinit_once_control = PTHREAD_ONCE_INIT;

static PHP_RINIT_FUNCTION(datadog) {
    UNUSED(module_number, type);

    if (!DATADOG_G(remote_config_state) && datadog_endpoint) {
        DATADOG_G(remote_config_state) = ddog_init_remote_config_state(datadog_endpoint, ddtrace_dynamic_instrumentation_state() == DDOG_DYNAMIC_INSTRUMENTATION_CONFIG_STATE_ENABLED);
    }

    // We need to init RC for the sidecar to write to it immediately
    if (DATADOG_G(remote_config_state)) {
        datadog_rinit_remote_config();
    }

    // Things that should only run on the first RINIT after each minit.
    pthread_once(&dd_rinit_once_control, dd_rinit_once);

    datadog_log_rinit(PG(error_log));

    datadog_agent_info_rinit();

    // Single combined read: applies env, container-hash, and concentrator config.
    datadog_apply_agent_info();

#ifdef DDTRACE
    ddtrace_rinit_early();
#endif

    // Do after env check, so that RC data is not updated before RC init
    DATADOG_G(request_initialized) = true;

    datadog_sidecar_rinit();

#ifdef DDTRACE
    ddtrace_rinit();
#endif

    return SUCCESS;
}

static void dd_shutdown_observer() {
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
#if PHP_VERSION_ID < 80100
#define RUN_TIME_CACHE_OBSERVER_PATCH_VERSION 18
#else
#define RUN_TIME_CACHE_OBSERVER_PATCH_VERSION 4
#endif

    zend_long patch_version = Z_LVAL_P(zend_get_constant_str(ZEND_STRL("PHP_RELEASE_VERSION")));
    if (patch_version < RUN_TIME_CACHE_OBSERVER_PATCH_VERSION) {
        // Just do it if we think to be the only observer ... don't want to break other functionalities
        if (!dd_has_other_observers) {
            // We really should not have to do it. But there is a bug before PHP 8.0.18 and 8.1.4 respectively, causing observer fcall info being freed before stream shutdown (which may still invoke user code)
            dd_observer_extension_backup = zend_observer_fcall_op_array_extension;
            zend_observer_fcall_op_array_extension = -1;
        }
    }
#endif

}

static PHP_RSHUTDOWN_FUNCTION(datadog) {
    UNUSED(module_number, type);

    // We deliberately select to not free some data structures, as to avoid the overhead of freeing them.
    // Just proper destruction can have significant and easily measurable overhead on applications.
    // Prior to PHP 7.2 fast shutdown was an opcache only feature
#if ZEND_DEBUG || PHP_VERSION_ID < 70200
    bool fast_shutdown = 0;
#elif defined(__SANITIZE_ADDRESS__)
    char *force_fast_shutdown = getenv("ZEND_ASAN_FORCE_FAST_SHUTDOWN");
    bool fast_shutdown = (
        is_zend_mm()
        || (force_fast_shutdown && ZEND_ATOL(force_fast_shutdown))
    ) && !EG(full_tables_cleanup);
#else
    bool fast_shutdown = is_zend_mm() && !EG(full_tables_cleanup);
#endif

    if (DATADOG_G(remote_config_state)) {
        datadog_rshutdown_remote_config();
    }

    if (!datadog_disable) {
        dd_shutdown_observer();
    }

#ifdef DDTRACE
    ddtrace_rshutdown(fast_shutdown);
#endif

    datadog_sidecar_finalize(true);
    DATADOG_G(request_initialized) = false;

    datadog_telemetry_rshutdown();
    datadog_sidecar_rshutdown();

    datadog_git_rshutdown();

    if (DATADOG_G(last_service_name)) {
        zend_string_release(DATADOG_G(last_service_name));
        DATADOG_G(last_service_name) = NULL;
    }
    if (DATADOG_G(last_env_name)) {
        zend_string_release(DATADOG_G(last_env_name));
        DATADOG_G(last_env_name) = NULL;
    }
    if (DATADOG_G(last_version)) {
        zend_string_release(DATADOG_G(last_version));
        DATADOG_G(last_version) = NULL;
    }

    return SUCCESS;
}

#if PHP_VERSION_ID < 80000
int datadog_post_deactivate(void) {
#else
zend_result datadog_post_deactivate(void) {
#endif
#ifdef DDTRACE
    ddtrace_post_deactivate();
#endif

    // zai config may be accessed indirectly via other modules RSHUTDOWN, so delay this until the last possible time
    zai_config_rshutdown();

    return SUCCESS;
}

static PHP_MINFO_FUNCTION(datadog) {
    UNUSED(zend_module);

    datadog_phpinfo();

    DISPLAY_INI_ENTRIES();
}

void datadog_internal_handle_fork(void) {
    // CHILD PROCESS
    bool runtime_id_changed = false;
    datadog_sidecar_handle_fork(&runtime_id_changed);
    if (runtime_id_changed) {
        datadog_publish_configured_otel_process_context();
    }

#ifdef DDTRACE
    ddtrace_internal_handle_fork();
#else
    ddtrace_sidecar_submit_span_data_direct_defaults(&DATADOG_G(sidecar), NULL);
#endif
}

static const zend_module_dep datadog_module_deps[] = {
    ZEND_MOD_REQUIRED("json")
    ZEND_MOD_REQUIRED("standard")
    ZEND_MOD_OPTIONAL("openetelemetry") // make sure we load after otel to insert the hook function if it doesn't exist yet
    ZEND_MOD_END};

zend_module_entry datadog_module_entry = {STANDARD_MODULE_HEADER_EX, NULL,
                                          datadog_module_deps,       PHP_DDTRACE_EXTNAME,
                                          NULL,             PHP_MINIT(datadog),
                                          PHP_MSHUTDOWN(datadog),    PHP_RINIT(datadog),
                                          PHP_RSHUTDOWN(datadog),    PHP_MINFO(datadog),
                                          PHP_DDTRACE_VERSION,       PHP_MODULE_GLOBALS(datadog),
                                          PHP_GINIT(datadog),        PHP_GSHUTDOWN(datadog),
                                          datadog_post_deactivate,   STANDARD_MODULE_PROPERTIES_EX};
