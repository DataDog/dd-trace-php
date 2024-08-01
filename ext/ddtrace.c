#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "ddtrace.h"
#include <SAPI.h>
#include <Zend/zend.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_smart_str.h>
#include <headers/headers.h>
#include <hook/hook.h>
#include <json/json.h>
#include <inttypes.h>
#if PHP_VERSION_ID < 80000
#include <Zend/zend_vm.h>
#include <interceptor/php7/interceptor.h>
#else
#include <interceptor/php8/interceptor.h>
#endif
#include <jit_utils/jit_blacklist.h>
#include <php.h>
#include <php_ini.h>
#ifndef _WIN32
#include <pthread.h>
#include <stdatomic.h>
#include <sys/mman.h>
#else
#include <components/pthread_polyfill.h>
#include <components/atomic_win32_polyfill.h>
#endif

#include <ext/standard/info.h>
#include <ext/standard/php_string.h>
#include <json/json.h>

#include <components-rs/ddtrace.h>
#include <components/log/log.h>

#include "auto_flush.h"
#include "compatibility.h"
#ifndef _WIN32
#include "comms_php.h"
#include "coms.h"
#endif
#include "config/config.h"
#include "configuration.h"
#include "ddshared.h"
#include "ddtrace_string.h"
#ifndef _WIN32
#include "dogstatsd_client.h"
#endif
#include "engine_hooks.h"
#include "excluded_modules.h"
#include "handlers_http.h"
#include "handlers_internal.h"
#include "integrations/exec_integration.h"
#include "integrations/integrations.h"
#include "ip_extraction.h"
#include "logging.h"
#include "memory_limit.h"
#include "limiter/limiter.h"
#include "priority_sampling/priority_sampling.h"
#include "random.h"
#include "autoload_php_files.h"
#include "serializer.h"
#include "sidecar.h"
#ifndef _WIN32
#include "signals.h"
#endif
#include "span.h"
#include "startup_logging.h"
#include "telemetry.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"
#include "user_request.h"
#include "zend_hrtime.h"
#include "ext/standard/file.h"

#include "hook/uhook.h"
#include "handlers_fiber.h"
#include "handlers_exception.h"
#include "exceptions/exceptions.h"
#include "git.h"

// On PHP 7 we cannot declare arrays as internal values. Assign null and handle in create_object where necessary.
#if PHP_VERSION_ID < 80000
#undef ZVAL_EMPTY_ARRAY
#define ZVAL_EMPTY_ARRAY ZVAL_NULL
#endif
// CG(empty_string) is not accessible during MINIT (in ZTS at least)
#if PHP_VERSION_ID < 70200
#undef ZVAL_EMPTY_STRING
#define ZVAL_EMPTY_STRING(z) ZVAL_NEW_STR(z, zend_string_init("", 0, 1))
#endif
#include "ddtrace_arginfo.h"
#include "distributed_tracing_headers.h"

#if PHP_VERSION_ID < 70200
#undef ZVAL_EMPTY_STRING
#define ZVAL_EMPTY_STRING(z) ZVAL_INTERNED_STR(z, ZSTR_EMPTY_ALLOC())
#endif
#if PHP_VERSION_ID < 80000
#undef ZVAL_EMPTY_ARRAY
#define ZVAL_EMPTY_ARRAY DD_ZVAL_EMPTY_ARRAY
#endif

// For manual ZPP
#if PHP_VERSION_ID < 70400
#define _error_code error_code
#endif

bool ddtrace_has_excluded_module;
static zend_module_entry *ddtrace_module;
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
static bool dd_has_other_observers;
static int dd_observer_extension_backup = -1;
#endif

datadog_php_sapi ddtrace_active_sapi = DATADOG_PHP_SAPI_UNKNOWN;

_Atomic(int64_t) ddtrace_warn_legacy_api;

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

#ifdef COMPILE_DL_DDTRACE
ZEND_GET_MODULE(ddtrace)
#ifdef ZTS
TSRM_TLS void *TSRMLS_CACHE = NULL;
#endif
#endif

int ddtrace_disable = 0; // 0 = enabled, 1 = disabled via INI, 2 = disabled, but MINIT was fully executed
static ZEND_INI_MH(dd_OnUpdateDisabled) {
    UNUSED(entry, mh_arg1, mh_arg2, mh_arg3, stage);
    if (!ddtrace_disable) {
        ddtrace_disable = zend_ini_parse_bool(new_value);
    }
    return SUCCESS;
}

PHP_INI_BEGIN()
    ZEND_INI_ENTRY("ddtrace.disable", "0", PHP_INI_SYSTEM, dd_OnUpdateDisabled)

    // Exposed for testing only
    STD_PHP_INI_ENTRY("ddtrace.cgroup_file", "/proc/self/cgroup", PHP_INI_SYSTEM, OnUpdateString, cgroup_file,
                      zend_ddtrace_globals, ddtrace_globals)
PHP_INI_END()

#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400
static void ddtrace_sort_modules(void *base, size_t count, size_t siz, compare_func_t compare, swap_func_t swp) {
    UNUSED(siz);
    UNUSED(compare);
    UNUSED(swp);

    // swap ddtrace and opcache for the rest of the modules lifecycle, so that opcache is always executed after ddtrace
    for (Bucket *module = base, *end = module + count, *ddtrace_module = NULL; module < end; ++module) {
        zend_module_entry *m = (zend_module_entry *)Z_PTR(module->val);
        if (m->name == ddtrace_module_entry.name) {
            ddtrace_module = module;
        }
        if (ddtrace_module && strcmp(m->name, "Zend OPcache") == 0) {
            Bucket tmp = *ddtrace_module;
            *ddtrace_module = *module;
            *module = tmp;
            break;
        }
    }
}
#endif

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
// On PHP 8.0.0-8.0.16 and 8.1.0-8.1.2 call_attribute_constructor would stack allocate a dummy frame, which could have become inaccessible upon access.
// Thus, we implement the fix which was applied to PHP itself as well: we move the stack allocated data to the VM stack.
// See also https://github.com/php/php-src/commit/f7c3f6e7e25471da9cfb2ba082a77cc3c85bc6ed
static void dd_patched_zend_call_known_function(
    zend_function *fn, zend_object *object, zend_class_entry *called_scope, zval *retval_ptr,
    uint32_t param_count, zval *params, HashTable *named_params)
{
    zval retval;
    zend_fcall_info fci;
    zend_fcall_info_cache fcic;

    // If current_execute_data is on the stack, move it to the VM stack
    zend_execute_data *execute_data = EG(current_execute_data);
    if (execute_data && (uintptr_t)&retval > (uintptr_t)EX(func) && (uintptr_t)&retval - 0xfffff < (uintptr_t)EX(func)) {
        zend_execute_data *call = zend_vm_stack_push_call_frame_ex(
                ZEND_MM_ALIGNED_SIZE_EX(sizeof(zend_execute_data), sizeof(zval)) +
                ZEND_MM_ALIGNED_SIZE_EX(sizeof(zend_op), sizeof(zval)) +
                ZEND_MM_ALIGNED_SIZE_EX(sizeof(zend_function), sizeof(zval)),
                0, EX(func), 0, NULL);

        memcpy(call, execute_data, sizeof(zend_execute_data));
        zend_op *opline = (zend_op *)(call + 1);
        memcpy(opline, EX(opline), sizeof(zend_op));
        zend_function *func = (zend_function *)(opline + 1);
        func->common.fn_flags |= ZEND_ACC_CALL_VIA_TRAMPOLINE; // See https://github.com/php/php-src/commit/2f6a06ccb0ef78e6122bb9e67f9b8b1ad07776e1
        memcpy((zend_op *)(call + 1) + 1, EX(func), sizeof(zend_function));

        call->opline = opline;
        call->func = func;

        EG(current_execute_data) = call;
    }

    // here follows the original implementation of zend_call_known_function

    fci.size = sizeof(fci);
    fci.object = object;
    fci.retval = retval_ptr ? retval_ptr : &retval;
    fci.param_count = param_count;
    fci.params = params;
    fci.named_params = named_params;
    ZVAL_UNDEF(&fci.function_name); /* Unused */

    fcic.function_handler = fn;
    fcic.object = object;
    fcic.called_scope = called_scope;

    zend_result result = zend_call_function(&fci, &fcic);
    if (UNEXPECTED(result == FAILURE)) {
        if (!EG(exception)) {
            zend_error_noreturn(E_CORE_ERROR, "Couldn't execute method %s%s%s",
                fn->common.scope ? ZSTR_VAL(fn->common.scope->name) : "",
                fn->common.scope ? "::" : "", ZSTR_VAL(fn->common.function_name));
        }
    }

    if (!retval_ptr) {
        zval_ptr_dtor(&retval);
    }
}

// We need to hijack zend_call_known_function as that's what's being called by call_attribute_constructor, and call_attribute_constructor itself is not exported.
static void dd_patch_zend_call_known_function(void) {
#ifdef _WIN32
    SYSTEM_INFO si;
    GetSystemInfo(&si);
    size_t page_size = (size_t)si.dwPageSize;
#else
    size_t page_size = sysconf(_SC_PAGESIZE);
#endif
    void *page = (void *)(~(page_size - 1) & (uintptr_t)zend_call_known_function);
    // 20 is the largest size of a trampoline we have to inject
    if ((((uintptr_t)zend_call_known_function + 20) & page_size) < 20) {
        page_size <<= 1; // if overlapping pages, use two
    }

#ifdef _WIN32
    DWORD old_protection;
    if (VirtualProtect(page, page_size, PAGE_READWRITE, &old_protection))
#else
    if (mprotect(page, page_size, PROT_READ | PROT_WRITE) != 0)
#endif
    { // Some architectures enforce W^X (either write _or_ execute, but not both).
        LOG(ERROR, "Could not alter the memory protection for zend_call_known_function. Tracer execution continues, but may crash when encountering attributes.");
        return; // Make absolutely sure we can write
    }

#ifdef __aarch64__
    // x13 is a scratch register
    uint32_t absolute_jump_instrs[] = {
        0x1000006D, // adr x13, 12 (load address from memory after this)
        0xF94001AD, // ldr x13, [x13]
        0xD61F01A0, // br x13
    };
    // The magical 12 is sizeof(absolute_jump_instrs) and hardcoded in the assembly above.
    memcpy(zend_call_known_function, absolute_jump_instrs, 12);
    *(void **)(12 + (uintptr_t)zend_call_known_function) = dd_patched_zend_call_known_function;
#else
    // $r10 doesn't really have special meaning
    uint8_t absolute_jump_instrs[] = {
        0x49, 0xBA, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, // mov $r10, imm_addr
        0x41, 0xFF, 0xE2 // jmp $r10
    };
    *(void **)&absolute_jump_instrs[2] = dd_patched_zend_call_known_function;
    memcpy(zend_call_known_function, absolute_jump_instrs, sizeof(absolute_jump_instrs));
#endif

#ifdef _WIN32
    VirtualProtect(page, page_size, old_protection, NULL);
#else
    mprotect(page, page_size, PROT_READ | PROT_EXEC);
#endif
}
#endif

// put this into startup so that other extensions running code as part of rinit do not crash
static int ddtrace_startup(zend_extension *extension) {
    UNUSED(extension);

    ddtrace_fetch_profiling_symbols();

#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400
    // Turns out with zai config we have dynamically allocated INI entries. This does not play well with PHP 7.3
    // As of PHP 7.3 opcache stores INI entry values in SHM. However, only as of PHP 7.4 opcache delays detaching SHM.
    // In PHP 7.3 SHM is freed in MSHUTDOWN, which may be executed before our extension, if we do not force an order.
    // We have to sort this manually here, as opcache only registers itself as extension during zend_extension.startup.
    zend_hash_sort_ex(&module_registry, ddtrace_sort_modules, NULL, 0);
#endif

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
    dd_has_other_observers = ZEND_OBSERVER_ENABLED;
#endif

#if PHP_VERSION_ID < 80000
    zai_interceptor_startup(ddtrace_module);
#else
    zai_interceptor_startup();
#endif

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
#if PHP_VERSION_ID < 80100
#define BUG_STACK_ALLOCATED_CALL_PATCH_VERSION 16
#else
#define BUG_STACK_ALLOCATED_CALL_PATCH_VERSION 3
#endif
    zend_long patch_version = Z_LVAL_P(zend_get_constant_str(ZEND_STRL("PHP_RELEASE_VERSION")));
    if (patch_version < BUG_STACK_ALLOCATED_CALL_PATCH_VERSION) {
        dd_patch_zend_call_known_function();
    }
#endif

    ddtrace_excluded_modules_startup();
    // We deliberately leave handler replacement during startup, even though this uses some config
    // This touches global state, which, while unlikely, may play badly when interacting with other extensions, if done post-startup
    ddtrace_internal_handlers_startup();
    return SUCCESS;
}

static void ddtrace_shutdown(struct _zend_extension *extension) {
    UNUSED(extension);

    ddtrace_internal_handlers_shutdown();

    zai_interceptor_shutdown();
}

bool dd_save_sampling_rules_file_config(zend_string *path, int modify_type, int stage) {
    if (FG(default_context) == NULL) {
        FG(default_context) = php_stream_context_alloc();
    }
    php_stream_context *context = FG(default_context);
    php_stream *stream = php_stream_open_wrapper_ex(ZSTR_VAL(path), "rb", USE_PATH | REPORT_ERRORS, NULL, context);
    if (!stream) {
        return false;
    }

    zend_string *file = php_stream_copy_to_mem(stream, (ssize_t) PHP_STREAM_COPY_ALL, 0);
    php_stream_close(stream);

    bool altered = false;
    if (file) {
        altered = ZSTR_LEN(file) > 0 && SUCCESS == zend_alter_ini_entry_ex(
            zai_config_memoized_entries[DDTRACE_CONFIG_DD_SPAN_SAMPLING_RULES].ini_entries[0]->name,
            file, modify_type, stage, 1);
        zend_string_release(file);
    }
    return altered;
}

bool ddtrace_alter_sampling_rules_file_config(zval *old_value, zval *new_value) {
    (void) old_value;
    if (Z_STRLEN_P(new_value) == 0) {
        return true;
    }

    return dd_save_sampling_rules_file_config(Z_STR_P(new_value), PHP_INI_USER, PHP_INI_STAGE_RUNTIME);
}

static inline bool dd_alter_prop(size_t prop_offset, zval *old_value, zval *new_value) {
    UNUSED(old_value);

    ddtrace_span_properties *pspan = ddtrace_active_span_props();
    while (pspan) {
        zval *property = (zval*)(prop_offset + (char*)pspan), garbage = *property;
        ZVAL_COPY(property, new_value);
        zval_ptr_dtor(&garbage);
        pspan = pspan->parent;
    }

    return true;
}

bool ddtrace_alter_dd_service(zval *old_value, zval *new_value) {
    return dd_alter_prop(XtOffsetOf(ddtrace_span_properties, property_service), old_value, new_value);
}
bool ddtrace_alter_dd_env(zval *old_value, zval *new_value) {
    return dd_alter_prop(XtOffsetOf(ddtrace_span_properties, property_env), old_value, new_value);
}
bool ddtrace_alter_dd_version(zval *old_value, zval *new_value) {
    return dd_alter_prop(XtOffsetOf(ddtrace_span_properties, property_version), old_value, new_value);
}

static void dd_activate_once(void) {
    ddtrace_config_first_rinit();
    ddtrace_generate_runtime_id();

    // must run before the first zai_hook_activate as ddtrace_telemetry_setup installs a global hook
    if (!ddtrace_disable) {
#ifndef _WIN32
        // Only disable sidecar sender when explicitly disabled
        bool bgs_fallback = DD_SIDECAR_TRACE_SENDER_DEFAULT && get_global_DD_TRACE_SIDECAR_TRACE_SENDER() && zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_SIDECAR_TRACE_SENDER].name_index < 0 && !get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED();
        zend_string *bgs_service = NULL;
        if (bgs_fallback) {
            // We enabled sending traces through the sidecar by default
            // That said a few customers have explicitly disabled telemetry to disable the sidecar
            // So if telemetry is disabled, we will disable the sidecar and send a one shot telemetry call
            ddtrace_change_default_ini(DDTRACE_CONFIG_DD_TRACE_SIDECAR_TRACE_SENDER, (zai_str) ZAI_STR_FROM_CSTR("0"));
            if ((bgs_service = get_DD_SERVICE())) {
                zend_string_addref(bgs_service);
            } else {
                bgs_service = ddtrace_default_service_name();
            }
        }
        zend_module_entry *appsec_module = zend_hash_str_find_ptr(&module_registry, "ddappsec", sizeof("ddappsec") - 1);
        if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED() || get_global_DD_TRACE_SIDECAR_TRACE_SENDER() || appsec_module)
#endif
        {
            bool modules_activated = PG(modules_activated);
            PG(modules_activated) = false;
            ddtrace_sidecar_setup();
            PG(modules_activated) = modules_activated;
        }
#ifndef _WIN32
        if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
            if (get_global_DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS() == 0) {
                // Set the default to 10 so that BGS flushes faster. With sidecar it's not needed generally.
                ddtrace_change_default_ini(DDTRACE_CONFIG_DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS, (zai_str) ZAI_STR_FROM_CSTR("10"));
            }
            if (get_DD_TRACE_AGENT_FLUSH_INTERVAL() == DD_TRACE_AGENT_FLUSH_INTERVAL_VAL) {
                // Set the default to 5000 so that BGS does not flush too often. The sidecar can flush more often, but the BGS is per process. Keep it higher to avoid too much load on the agent.
                ddtrace_change_default_ini(DDTRACE_CONFIG_DD_TRACE_AGENT_FLUSH_INTERVAL, (zai_str) ZAI_STR_FROM_CSTR("5000"));
            }
            ddtrace_coms_minit(get_global_DD_TRACE_AGENT_STACK_INITIAL_SIZE(),
                               get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE(),
                               get_global_DD_TRACE_AGENT_STACK_BACKLOG(),
                               bgs_fallback ? ZSTR_VAL(bgs_service) : NULL);
            zend_string *testing_token = get_global_DD_TRACE_AGENT_TEST_SESSION_TOKEN();
            if (ZSTR_LEN(testing_token)) {
                ddtrace_coms_set_test_session_token(ZSTR_VAL(testing_token), ZSTR_LEN(testing_token));
            }
            if (bgs_fallback) {
                zend_string_release(bgs_service);
            }
        }
#endif
    }
}

static pthread_once_t dd_activate_once_control = PTHREAD_ONCE_INIT;

static void ddtrace_activate(void) {
    ddog_reset_logger();

    zai_hook_rinit();
    zai_interceptor_activate();
    zai_uhook_rinit();
    ddtrace_telemetry_rinit();
    zend_hash_init(&DDTRACE_G(traced_spans), 8, unused, NULL, 0);
    zend_hash_init(&DDTRACE_G(tracestate_unknown_dd_keys), 8, unused, NULL, 0);

    if (!ddtrace_disable && ddtrace_has_excluded_module == true) {
        ddtrace_disable = 2;
    }

    // ZAI config is always set up
    pthread_once(&dd_activate_once_control, dd_activate_once);
    zai_config_rinit();

    if (!ddtrace_disable && (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED() || get_global_DD_TRACE_SIDECAR_TRACE_SENDER())) {
        ddtrace_sidecar_ensure_active();
    }

    zend_string *sampling_rules_file = get_DD_SPAN_SAMPLING_RULES_FILE();
    if (ZSTR_LEN(sampling_rules_file) > 0 && !zend_string_equals(get_global_DD_SPAN_SAMPLING_RULES_FILE(), sampling_rules_file)) {
        dd_save_sampling_rules_file_config(sampling_rules_file, PHP_INI_USER, PHP_INI_STAGE_RUNTIME);
    }

    if (!ddtrace_disable && strcmp(sapi_module.name, "cli") == 0 && !get_DD_TRACE_CLI_ENABLED()) {
        ddtrace_disable = 2;
    }

    if (ddtrace_disable) {
        ddtrace_disable_tracing_in_current_request();
    }

#if PHP_VERSION_ID < 80000
    // This allows us to hook the ZEND_HANDLE_EXCEPTION pseudo opcode
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op));
    EG(exception_op)->opcode = ZEND_HANDLE_EXCEPTION;
#endif
}

static void ddtrace_deactivate(void) {
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
    if (dd_observer_extension_backup != -1) {
        zend_observer_fcall_op_array_extension = dd_observer_extension_backup;
        dd_observer_extension_backup = -1;
    }
#endif
}

static zend_extension _dd_zend_extension_entry = {"ddtrace",
                                                  PHP_DDTRACE_VERSION,
                                                  "Datadog",
                                                  "https://github.com/DataDog/dd-trace-php",
                                                  "Copyright Datadog",
                                                  ddtrace_startup,
                                                  ddtrace_shutdown,
                                                  ddtrace_activate,
                                                  ddtrace_deactivate,
                                                  NULL,
#if PHP_VERSION_ID < 80000
                                                  zai_interceptor_op_array_pass_two,
#else
                                                  NULL,
#endif
                                                  NULL,
                                                  NULL,
                                                  NULL,
#if PHP_VERSION_ID < 80000
                                                  zai_interceptor_op_array_ctor,
#else
                                                  NULL,
#endif
                                                  zai_hook_unresolve_op_array,

                                                  STANDARD_ZEND_EXTENSION_PROPERTIES};

static void php_ddtrace_init_globals(zend_ddtrace_globals *ng) { memset(ng, 0, sizeof(zend_ddtrace_globals)); }

static PHP_GINIT_FUNCTION(ddtrace) {
#if defined(COMPILE_DL_DDTRACE) && defined(ZTS)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    php_ddtrace_init_globals(ddtrace_globals);
    zai_hook_ginit();
    zend_hash_init(&ddtrace_globals->git_metadata, 8, unused, (dtor_func_t)ddtrace_git_metadata_dtor, 1);
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

static PHP_GSHUTDOWN_FUNCTION(ddtrace) {
    if (ddtrace_globals->remote_config_reader) {
        ddog_agent_remote_config_reader_drop(ddtrace_globals->remote_config_reader);
    }
    zai_hook_gshutdown();
    if (ddtrace_globals->telemetry_buffer) {
        ddog_sidecar_telemetry_buffer_drop(ddtrace_globals->telemetry_buffer);
    }

    zend_hash_destroy(&ddtrace_globals->git_metadata);

#ifdef CXA_THREAD_ATEXIT_WRAPPER
    // FrankenPHP calls `ts_free_thread()` in rshutdown
    if (!dd_is_main_thread && ddtrace_active_sapi != DATADOG_PHP_SAPI_FRANKENPHP) {
        dd_run_rust_thread_destructors(NULL);
    }
#endif
}

/* DDTrace\SpanLink */
zend_class_entry *ddtrace_ce_span_link;

PHP_METHOD(DDTrace_SpanLink, jsonSerialize) {
    ddtrace_span_link *link = (ddtrace_span_link *)Z_OBJ_P(ZEND_THIS);

    zend_array *array = zend_new_array(5);

    zend_string *trace_id = zend_string_init("trace_id", sizeof("trace_id") - 1, 0);
    zend_string *span_id = zend_string_init("span_id", sizeof("span_id") - 1, 0);
    zend_string *trace_state = zend_string_init("trace_state", sizeof("trace_state") - 1, 0);
    zend_string *attributes = zend_string_init("attributes", sizeof("attributes") - 1, 0);
    zend_string *dropped_attributes_count = zend_string_init("dropped_attributes_count", sizeof("dropped_attributes_count") - 1, 0);

    Z_TRY_ADDREF(link->property_trace_id);
    zend_hash_add(array, trace_id, &link->property_trace_id);
    Z_TRY_ADDREF(link->property_span_id);
    zend_hash_add(array, span_id, &link->property_span_id);
    Z_TRY_ADDREF(link->property_trace_state);
    zend_hash_add(array, trace_state, &link->property_trace_state);
    Z_TRY_ADDREF(link->property_attributes);
    zend_hash_add(array, attributes, &link->property_attributes);
    Z_TRY_ADDREF(link->property_dropped_attributes_count);
    zend_hash_add(array, dropped_attributes_count, &link->property_dropped_attributes_count);

    zend_string_release(trace_id);
    zend_string_release(span_id);
    zend_string_release(trace_state);
    zend_string_release(attributes);
    zend_string_release(dropped_attributes_count);

    RETURN_ARR(array);
}

static ddtrace_distributed_tracing_result dd_parse_distributed_tracing_headers_function(INTERNAL_FUNCTION_PARAMETERS, bool *success);
ZEND_METHOD(DDTrace_SpanLink, fromHeaders) {
    bool success;
    ddtrace_distributed_tracing_result result = dd_parse_distributed_tracing_headers_function(INTERNAL_FUNCTION_PARAM_PASSTHRU, &success);
    if (!success) {
        RETURN_NULL();
    }

    object_init_ex(return_value, ddtrace_ce_span_link);
    ddtrace_span_link *link = (ddtrace_span_link *)Z_OBJ_P(return_value);
    if (!get_DD_TRACE_ENABLED()) {
        return;
    }

    ZVAL_STR(&link->property_trace_id, ddtrace_trace_id_as_hex_string(result.trace_id));
    ZVAL_STR(&link->property_span_id, ddtrace_span_id_as_hex_string(result.parent_id));
    array_init(&link->property_attributes);
    zend_hash_copy(Z_ARR(link->property_attributes), &result.meta_tags, NULL);

    zend_string *propagated_tags = ddtrace_format_propagated_tags(&result.propagated_tags, &result.meta_tags);
    zend_string *full_tracestate = ddtrace_format_tracestate(result.tracestate, 0, result.origin, result.priority_sampling, propagated_tags, &result.tracestate_unknown_dd_keys);
    zend_string_release(propagated_tags);
    if (full_tracestate) {
        ZVAL_STR(&link->property_trace_state, full_tracestate);
    }

    result.meta_tags.pDestructor = NULL; // we moved values directly
    zend_hash_destroy(&result.meta_tags);
    zend_hash_destroy(&result.propagated_tags);
    zend_hash_destroy(&result.tracestate_unknown_dd_keys);
    if (result.origin) {
        zend_string_release(result.origin);
    }
    if (result.tracestate) {
        zend_string_release(result.tracestate);
    }
}

/* DDTrace\SpanData */
zend_class_entry *ddtrace_ce_span_data;
zend_class_entry *ddtrace_ce_root_span_data;
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100
HashTable dd_root_span_data_duplicated_properties_table;
#endif
zend_class_entry *ddtrace_ce_span_stack;
zend_object_handlers ddtrace_span_data_handlers;
zend_object_handlers ddtrace_root_span_data_handlers;
zend_object_handlers ddtrace_span_stack_handlers;

static zend_object *dd_init_span_data_object(zend_class_entry *class_type, ddtrace_span_data *span, zend_object_handlers *handlers) {
    zend_object_std_init(&span->std, class_type);
    span->std.handlers = handlers;
    object_properties_init(&span->std, class_type);
#if PHP_VERSION_ID < 80000
    // Not handled in arginfo on these old versions
    array_init(&span->property_meta);
    array_init(&span->property_metrics);
    array_init(&span->property_meta_struct);
    array_init(&span->property_links);
    array_init(&span->property_peer_service_sources);
#endif
    // Explicitly assign property-mapped NULLs
    span->stack = NULL;
    span->parent = NULL;
    return &span->std;
}

static zend_object *ddtrace_span_data_create(zend_class_entry *class_type) {
    ddtrace_span_data *span = ecalloc(1, sizeof(*span));
    return dd_init_span_data_object(class_type, span, &ddtrace_span_data_handlers);
}

static zend_object *ddtrace_root_span_data_create(zend_class_entry *class_type) {
    ddtrace_root_span_data *span = ecalloc(1, sizeof(*span));
    dd_init_span_data_object(class_type, &span->span, &ddtrace_root_span_data_handlers);
#if PHP_VERSION_ID < 80000
    // Not handled in arginfo on these old versions
    array_init(&span->property_propagated_tags);
    array_init(&span->property_tracestate_tags);
#endif
    return &span->std;
}

static zend_object *ddtrace_span_stack_create(zend_class_entry *class_type) {
    ddtrace_span_stack *stack = ecalloc(1, sizeof(*stack));
    zend_object_std_init(&stack->std, class_type);
    stack->root_stack = stack;
    stack->std.handlers = &ddtrace_span_stack_handlers;
    object_properties_init(&stack->std, class_type);
    // Explicitly assign property-mapped NULLs
    stack->active = NULL;
    stack->parent_stack = NULL;
    return &stack->std;
}

// Init with empty span stack if directly allocated via new()
static zend_function *ddtrace_span_data_get_constructor(zend_object *object) {
    object_init_ex(&OBJ_SPANDATA(object)->property_stack, ddtrace_ce_span_stack);
    return NULL;
}

static void ddtrace_span_stack_dtor_obj(zend_object *object) {
    // We must not invoke span stack destructors during zend_objects_store_call_destructors to avoid them not being present for appsec rshutdown
    if (EG(current_execute_data) == NULL && !DDTRACE_G(in_shutdown)) {
        GC_DEL_FLAGS(object, IS_OBJ_DESTRUCTOR_CALLED);
        return;
    }

    ddtrace_span_stack *stack = (ddtrace_span_stack *)object;
    ddtrace_span_data *top;
    while (stack->active && (top = SPANDATA(stack->active)) && top->stack == stack) {
        dd_trace_stop_span_time(top);
        // let's not stack swap to a) avoid side effects in destructors and b) avoid a crash on PHP 7.3 and older
        ddtrace_close_top_span_without_stack_swap(top);
    }
    if (stack->closed_ring || stack->closed_ring_flush) {
        // ensure dtor can be called again
        GC_DEL_FLAGS(object, IS_OBJ_DESTRUCTOR_CALLED);
    }
    zend_objects_destroy_object(object);
}

// span stacks have intrinsic properties (managing other spans, can be switched to), unlike trivial span data which are just value objects
// thus we need to cleanup a little what exactly we can copy
#if PHP_VERSION_ID < 80000
static zend_object *ddtrace_span_stack_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
#else
static zend_object *ddtrace_span_stack_clone_obj(zend_object *old_obj) {
#endif
    zend_object *new_obj = ddtrace_span_stack_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    ddtrace_span_stack *stack = (ddtrace_span_stack *)new_obj;
    ddtrace_span_stack *oldstack = (ddtrace_span_stack *)old_obj;
    if (oldstack->parent_stack) { // if this is false, we're copying an initial stack
        stack->root_stack = stack->parent_stack->root_stack;
        stack->root_span = stack->parent_stack->root_span;
    }
    if (oldstack->root_stack == oldstack) {
        stack->root_stack = stack;
    }

    ddtrace_span_properties *pspan = stack->active;
    zval_ptr_dtor(&stack->property_active);
    while (pspan && pspan->stack == oldstack) {
        pspan = pspan->parent;
    }
    if (pspan) {
        ZVAL_OBJ_COPY(&stack->property_active, &pspan->std);
    } else {
        if (oldstack->root_span && oldstack->root_span->stack == oldstack) {
            stack->root_span = NULL;
        }
        stack->active = NULL;
        ZVAL_NULL(&stack->property_active);
    }

    return new_obj;
}

static void ddtrace_span_data_free_storage(zend_object *object) {
    zend_object_std_dtor(object);
    // Prevent use after free after zend_objects_store_free_object_storage is called (e.g. preloading) [PHP < 8.1]
    memset(object->properties_table, 0, sizeof(ddtrace_span_data) - XtOffsetOf(ddtrace_span_data, std.properties_table));
}

#if PHP_VERSION_ID < 80000
static zend_object *ddtrace_span_data_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
#else
static zend_object *ddtrace_span_data_clone_obj(zend_object *old_obj) {
#endif
    zend_object *new_obj = ddtrace_span_data_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    return new_obj;
}

#if PHP_VERSION_ID < 80000
static zend_object *ddtrace_root_span_data_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
#else
static zend_object *ddtrace_root_span_data_clone_obj(zend_object *old_obj) {
#endif
    zend_object *new_obj = ddtrace_root_span_data_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    return new_obj;
}

#if PHP_VERSION_ID < 80000
#if PHP_VERSION_ID >= 70400
static zval *ddtrace_span_data_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#else
static void ddtrace_span_data_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#endif
    zend_object *obj = Z_OBJ_P(object);
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static zval *ddtrace_span_data_readonly(zend_object *object, zend_string *member, zval *value, void **cache_slot) {
    zend_object *obj = object;
    zend_string *prop_name = member;
#endif
    if (zend_string_equals_literal(prop_name, "parent")
     || zend_string_equals_literal(prop_name, "id")
     || zend_string_equals_literal(prop_name, "stack")) {
        zend_throw_error(zend_ce_error, "Cannot modify readonly property %s::$%s", ZSTR_VAL(obj->ce->name), ZSTR_VAL(prop_name));
#if PHP_VERSION_ID >= 70400
        return &EG(uninitialized_zval);
#else
        return;
#endif
    }

#if PHP_VERSION_ID >= 70400
    return zend_std_write_property(object, member, value, cache_slot);
#else
    zend_std_write_property(object, member, value, cache_slot);
#endif
}

#if PHP_VERSION_ID < 80000
#if PHP_VERSION_ID >= 70400
static zval *ddtrace_root_span_data_write(zval *object, zval *member, zval *value, void **cache_slot) {
#else
static void ddtrace_root_span_data_write(zval *object, zval *member, zval *value, void **cache_slot) {
#endif
    zend_object *obj = Z_OBJ_P(object);
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static zval *ddtrace_root_span_data_write(zend_object *object, zend_string *member, zval *value, void **cache_slot) {
    zend_object *obj = object;
    zend_string *prop_name = member;
#endif
    ddtrace_root_span_data *span = ROOTSPANDATA(obj);
    zval zv;
    if (zend_string_equals_literal(prop_name, "parentId")) {
        if (Z_TYPE_P(value) == IS_LONG && Z_LVAL_P(value)) {
            span->parent_id = (uint64_t) Z_LVAL_P(value);
            ZVAL_STR(&zv, zend_strpprintf(0, "%" PRIu64, span->parent_id));
            value = &zv;
        } else {
            span->parent_id = ddtrace_parse_userland_span_id(value);
            if (!span->parent_id) {
                ZVAL_EMPTY_STRING(&zv);
                value = &zv;
            }
        }
    } else if (zend_string_equals_literal(prop_name, "traceId")) {
        span->trace_id = Z_TYPE_P(value) == IS_STRING ? ddtrace_parse_hex_trace_id(Z_STRVAL_P(value), Z_STRLEN_P(value)) : (ddtrace_trace_id){ 0 };
        if (!span->trace_id.low && !span->trace_id.high) {
            span->trace_id = (ddtrace_trace_id) {
                .low = span->span_id,
                .time = get_DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED() ? span->start / ZEND_NANO_IN_SEC : 0,
            };
            value = &span->property_id;
        }
    } else if (zend_string_equals_literal(prop_name, "samplingPriority")) {
        span->explicit_sampling_priority = zval_get_long(value) != DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    }

#if PHP_VERSION_ID >= 70400
    return ddtrace_span_data_readonly(object, member, value, cache_slot);
#else
    ddtrace_span_data_readonly(object, member, value, cache_slot);
#endif
}

#if PHP_VERSION_ID < 80000
#if PHP_VERSION_ID >= 70400
static zval *ddtrace_span_stack_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#else
static void ddtrace_span_stack_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#endif
    zend_object *obj = Z_OBJ_P(object);
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static zval *ddtrace_span_stack_readonly(zend_object *object, zend_string *member, zval *value, void **cache_slot) {
    zend_object *obj = object;
    zend_string *prop_name = member;
#endif
    if (zend_string_equals_literal(prop_name, "parent")) {
        zend_throw_error(zend_ce_error, "Cannot modify readonly property %s::$%s", ZSTR_VAL(obj->ce->name), ZSTR_VAL(prop_name));
#if PHP_VERSION_ID >= 70400
        return &EG(uninitialized_zval);
#else
        return;
#endif
    }

#if PHP_VERSION_ID >= 70400
    return zend_std_write_property(object, member, value, cache_slot);
#else
    zend_std_write_property(object, member, value, cache_slot);
#endif
}

PHP_METHOD(DDTrace_SpanData, getDuration) {
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(ZEND_THIS));
    RETURN_LONG(span->duration);
}

PHP_METHOD(DDTrace_SpanData, getStartTime) {
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(ZEND_THIS));
    RETURN_LONG(span->start);
}

PHP_METHOD(DDTrace_SpanData, getLink) {
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(ZEND_THIS));

    zval fci_zv;
    object_init_ex(&fci_zv, ddtrace_ce_span_link);
    ddtrace_span_link *link = (ddtrace_span_link *)Z_OBJ_P(&fci_zv);

    ZVAL_STR(&link->property_trace_id, ddtrace_trace_id_as_hex_string(span->root ? span->root->trace_id : (ddtrace_trace_id){ .low = span->span_id, .high = 0 }));
    ZVAL_STR(&link->property_span_id, ddtrace_span_id_as_hex_string(span->span_id));

    RETURN_OBJ(Z_OBJ(fci_zv));
}

PHP_METHOD(DDTrace_SpanData, hexId) {
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(ZEND_THIS));
    RETURN_STR(ddtrace_span_id_as_hex_string(span->span_id));
}

static void dd_register_span_data_ce(void) {
    ddtrace_ce_span_data = register_class_DDTrace_SpanData();
    ddtrace_ce_span_data->create_object = ddtrace_span_data_create;

    memcpy(&ddtrace_span_data_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    ddtrace_span_data_handlers.offset = XtOffsetOf(ddtrace_span_data, std);
    ddtrace_span_data_handlers.clone_obj = ddtrace_span_data_clone_obj;
    ddtrace_span_data_handlers.free_obj = ddtrace_span_data_free_storage;
    ddtrace_span_data_handlers.write_property = ddtrace_span_data_readonly;
    ddtrace_span_data_handlers.get_constructor = ddtrace_span_data_get_constructor;

    ddtrace_ce_root_span_data = register_class_DDTrace_RootSpanData(ddtrace_ce_span_data);
    ddtrace_ce_root_span_data->create_object = ddtrace_root_span_data_create;

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100
    // Work around wrong reference source for typed internal properties by preventing duplication of them
    // php -d extension=zend_test -r '$c = new _ZendTestChildClass; $i = &$c->intProp;'
    // php: /usr/local/src/php/Zend/zend_execute.c:3390: zend_ref_del_type_source: Assertion `source_list->ptr == prop' failed.
    zend_hash_init(&dd_root_span_data_duplicated_properties_table, zend_hash_num_elements(&ddtrace_ce_span_data->properties_info), NULL, NULL, true);
    for (uint32_t i = 0; i < zend_hash_num_elements(&ddtrace_ce_span_data->properties_info); ++i) {
        Bucket *bucket = &ddtrace_ce_root_span_data->properties_info.arData[i];
        zend_hash_add_ptr(&dd_root_span_data_duplicated_properties_table, bucket->key, Z_PTR(bucket->val));
        Z_PTR(bucket->val) = ddtrace_ce_root_span_data->properties_info_table[i] = Z_PTR(ddtrace_ce_span_data->properties_info.arData[i].val);
    }
#endif

    memcpy(&ddtrace_root_span_data_handlers, &ddtrace_span_data_handlers, sizeof(zend_object_handlers));
    ddtrace_root_span_data_handlers.offset = XtOffsetOf(ddtrace_root_span_data, std);
    ddtrace_root_span_data_handlers.clone_obj = ddtrace_root_span_data_clone_obj;
    ddtrace_root_span_data_handlers.write_property = ddtrace_root_span_data_write;

    ddtrace_ce_span_stack = register_class_DDTrace_SpanStack();
    ddtrace_ce_span_stack->create_object = ddtrace_span_stack_create;

    memcpy(&ddtrace_span_stack_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    ddtrace_span_stack_handlers.clone_obj = ddtrace_span_stack_clone_obj;
    ddtrace_span_stack_handlers.dtor_obj = ddtrace_span_stack_dtor_obj;
    ddtrace_span_stack_handlers.write_property = ddtrace_span_stack_readonly;

}

/* DDTrace\FatalError */
zend_class_entry *ddtrace_ce_fatal_error;

static void dd_register_fatal_error_ce(void) {
    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "DDTrace", "FatalError", NULL);
    ddtrace_ce_fatal_error = zend_register_internal_class_ex(&ce, zend_ce_exception);
}

zend_class_entry *ddtrace_ce_integration;
zend_class_entry *ddtrace_ce_git_metadata;
zend_object_handlers ddtrace_git_metadata_handlers;

static zend_object *ddtrace_git_metadata_create(zend_class_entry *class_type) {
    zend_object *object = zend_objects_new(class_type);
    object_properties_init(object, class_type);
    object->handlers = &ddtrace_git_metadata_handlers;
    return object;
}

static void ddtrace_free_obj_wrapper(zend_object *object) {
    zend_object_std_dtor(object);
}

static bool dd_is_compatible_sapi() {
    switch (ddtrace_active_sapi) {
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
        ddtrace_disable = 1;
    }
}

static PHP_MINIT_FUNCTION(ddtrace) {
    UNUSED(type);

    ddtrace_active_sapi = datadog_php_sapi_from_name(datadog_php_string_view_from_cstr(sapi_module.name));

#ifdef CXA_THREAD_ATEXIT_WRAPPER
    // FrankenPHP calls `ts_free_thread()` in rshutdown
    if (ddtrace_active_sapi != DATADOG_PHP_SAPI_FRANKENPHP) {
        dd_is_main_thread = true;
        glibc__cxa_thread_atexit_impl = CXA_THREAD_ATEXIT_PHP;
        atexit(dd_clean_main_thread_locals);
    }
#endif

    // Reset on every minit for `apachectl graceful`.
    dd_activate_once_control = (pthread_once_t)PTHREAD_ONCE_INIT;

    zai_hook_minit();
    zai_uhook_minit(module_number);
#if PHP_VERSION_ID >= 80000
    zai_interceptor_minit();
#endif
#if ZAI_JIT_BLACKLIST_ACTIVE
    zai_jit_minit();
#endif

#if PHP_VERSION_ID < 70300 || (defined(_WIN32) && PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400)
    ddtrace_startup_hrtime();
#endif

    register_ddtrace_symbols(module_number);
    REGISTER_INI_ENTRIES();

    zval *ddtrace_module_zv = zend_hash_str_find(&module_registry, ZEND_STRL("ddtrace"));
    if (ddtrace_module_zv) {
        ddtrace_module = Z_PTR_P(ddtrace_module_zv);
    }

    // config initialization needs to be at the top
    // This also initialiyzed logging, so no logs may be emitted before this.
    ddtrace_log_init();
    if (!ddtrace_config_minit(module_number)) {
        return FAILURE;
    }
    if (ZSTR_LEN(get_global_DD_SPAN_SAMPLING_RULES_FILE()) > 0) {
        dd_save_sampling_rules_file_config(get_global_DD_SPAN_SAMPLING_RULES_FILE(), PHP_INI_SYSTEM, PHP_INI_STAGE_STARTUP);
    }

    dd_disable_if_incompatible_sapi_detected();
    atomic_init(&ddtrace_warn_legacy_api, 1);

    /* This allows an extension (e.g. extension=ddtrace.so) to have zend_engine
     * hooks too, but not loadable as zend_extension=ddtrace.so.
     * See http://www.phpinternalsbook.com/php7/extensions_design/zend_extensions.html#hybrid-extensions
     * {{{ */
    zend_register_extension(&_dd_zend_extension_entry, ddtrace_module_entry.handle);
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

    if (ddtrace_disable) {
        return SUCCESS;
    }

#if PHP_VERSION_ID >= 80100
    ddtrace_setup_fiber_observers();
#endif

#ifndef _WIN32
    ddtrace_set_coredumpfilter();
#endif

    ddtrace_initialize_span_sampling_limiter();
    ddtrace_limiter_create();

    ddtrace_log_minit();

#ifndef _WIN32
    ddtrace_dogstatsd_client_minit();
#endif
    ddshared_minit();
    ddtrace_autoload_minit();

    dd_register_span_data_ce();
    dd_register_fatal_error_ce();
    ddtrace_ce_integration = register_class_DDTrace_Integration();
    ddtrace_ce_span_link = register_class_DDTrace_SpanLink(php_json_serializable_ce);
    ddtrace_ce_git_metadata = register_class_DDTrace_GitMetadata();
    ddtrace_ce_git_metadata->create_object = ddtrace_git_metadata_create;
    memcpy(&ddtrace_git_metadata_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    // We need a free_obj wrapper as zend_objects_store_free_object_storage will skip freeing of classes with the default free_obj handler when fast_shutdown is active. This will mess with our refcount and leak cached git metadata.
    ddtrace_git_metadata_handlers.free_obj = ddtrace_free_obj_wrapper;

    ddtrace_engine_hooks_minit();

    ddtrace_integrations_minit();
    dd_ip_extraction_startup();
    ddtrace_serializer_startup();

    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    zai_uhook_mshutdown();
    zai_hook_mshutdown();

    UNREGISTER_INI_ENTRIES();

    if (ddtrace_disable == 1) {
        zai_config_mshutdown();
        zai_json_shutdown_bindings();
        return SUCCESS;
    }

    if (DDTRACE_G(agent_rate_by_service)) {
        zai_json_release_persistent_array(DDTRACE_G(agent_rate_by_service));
        DDTRACE_G(agent_rate_by_service) = NULL;
    }

    ddtrace_integrations_mshutdown();

#ifndef _WIN32
    ddtrace_signals_mshutdown();

    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_mshutdown();
        if (ddtrace_coms_flush_shutdown_writer_synchronous()) {
            ddtrace_coms_curl_shutdown();
        }
    }
#endif

    ddtrace_log_mshutdown();

    ddtrace_engine_hooks_mshutdown();

    ddtrace_shutdown_span_sampling_limiter();
    ddtrace_limiter_destroy();
    zai_config_mshutdown();
    zai_json_shutdown_bindings();

    ddtrace_user_req_shutdown();

    ddtrace_sidecar_shutdown();

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100
    // See dd_register_span_data_ce for explanation
    zend_string *key;
    void *prop_info;
    ZEND_HASH_FOREACH_STR_KEY_PTR(&dd_root_span_data_duplicated_properties_table, key, prop_info) {
        ZVAL_PTR(zend_hash_find(&ddtrace_ce_root_span_data->properties_info, key), prop_info); // no update to avoid dtor
    } ZEND_HASH_FOREACH_END();
#endif

    return SUCCESS;
}

static bool dd_rinit_once_done = false;

static void dd_rinit_once(void) {
    /* The env vars are memoized on MINIT before the SAPI env vars are available.
     * We use the first RINIT to bust the env var cache and use the SAPI env vars.
     * TODO Audit/remove config usages before RINIT and move config init to RINIT.
     */
    ddtrace_startup_logging_first_rinit();

    // Uses config, cannot run earlier
#ifndef _WIN32
    ddtrace_signals_first_rinit();
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_init_and_start_writer();
    }
#endif

    dd_rinit_once_done = true;
}

static pthread_once_t dd_rinit_once_control = PTHREAD_ONCE_INIT;

static void dd_initialize_request(void) {
    DDTRACE_G(distributed_trace_id) = (ddtrace_trace_id){0};
    DDTRACE_G(distributed_parent_trace_id) = 0;
    DDTRACE_G(additional_global_tags) = zend_new_array(0);
    DDTRACE_G(default_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    DDTRACE_G(propagated_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNSET;
    zend_hash_init(&DDTRACE_G(root_span_tags_preset), 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&DDTRACE_G(propagated_root_span_tags), 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&DDTRACE_G(tracestate_unknown_dd_keys), 8, unused, ZVAL_PTR_DTOR, 0);

    // Things that should only run on the first RINIT after each minit.
    pthread_once(&dd_rinit_once_control, dd_rinit_once);

    if (!DDTRACE_G(remote_config_reader)) {
        if (get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
            if (ddtrace_endpoint) {
                DDTRACE_G(remote_config_reader) = ddog_agent_remote_config_reader_for_endpoint(ddtrace_endpoint);
            }
#ifndef _WIN32
        } else if (ddtrace_coms_agent_config_handle) {
            ddog_agent_remote_config_reader_for_anon_shm(ddtrace_coms_agent_config_handle, &DDTRACE_G(remote_config_reader));
#endif
        }
    }

    ddtrace_internal_handlers_rinit();

    ddtrace_log_rinit(PG(error_log));

    ddtrace_seed_prng();
    ddtrace_init_span_stacks();

#ifndef _WIN32
    ddtrace_dogstatsd_client_rinit();
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_on_pid_change();
    }
#endif

    // Reset compile time after request init hook has compiled
    ddtrace_compile_time_reset();

    dd_prepare_for_new_trace();

    ddtrace_distributed_tracing_result distributed_result = ddtrace_read_distributed_tracing_ids(ddtrace_read_zai_header, NULL);
    ddtrace_apply_distributed_tracing_result(&distributed_result, NULL);

    if (!DDTRACE_G(telemetry_queue_id)) {
        DDTRACE_G(telemetry_queue_id) = ddog_sidecar_queueId_generate();
    }

    if (get_DD_TRACE_GENERATE_ROOT_SPAN()) {
        ddtrace_push_root_span();
    }
}

static PHP_RINIT_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

#if PHP_VERSION_ID < 80000
    zai_interceptor_rinit();
#endif

    if (!ddtrace_disable) {
        // With internal functions also being hookable, they must not be hooked before the CG(map_ptr_base) is zeroed
        zai_hook_activate();
        DDTRACE_G(active_stack) = NULL; // This should not be necessary, but somehow sometimes it may be a leftover from a previous request.
        DDTRACE_G(active_stack) = ddtrace_init_root_span_stack();
#if PHP_VERSION_ID < 80000
        ddtrace_autoload_rinit();
#endif
    }

    if (get_DD_TRACE_ENABLED()) {
        dd_initialize_request();
    }

    return SUCCESS;
}

static void dd_clean_globals(void) {
    zend_array_destroy(DDTRACE_G(additional_global_tags));
    zend_hash_destroy(&DDTRACE_G(root_span_tags_preset));
    zend_hash_destroy(&DDTRACE_G(tracestate_unknown_dd_keys));
    zend_hash_destroy(&DDTRACE_G(propagated_root_span_tags));

    if (DDTRACE_G(curl_multi_injecting_spans)) {
        if (GC_DELREF(DDTRACE_G(curl_multi_injecting_spans)) == 0) {
            rc_dtor_func((zend_refcounted *) DDTRACE_G(curl_multi_injecting_spans));
        }
        DDTRACE_G(curl_multi_injecting_spans) = NULL;
    }

    if (DDTRACE_G(dd_origin)) {
        zend_string_release(DDTRACE_G(dd_origin));
        DDTRACE_G(dd_origin) = NULL;
    }

    if (DDTRACE_G(tracestate)) {
        zend_string_release(DDTRACE_G(tracestate));
        DDTRACE_G(tracestate) = NULL;
    }

    ddtrace_internal_handlers_rshutdown();
#ifndef _WIN32
    ddtrace_dogstatsd_client_rshutdown();
#endif

    ddtrace_free_span_stacks(false);
#ifndef _WIN32
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_rshutdown();
    }
#endif
}

static void dd_shutdown_hooks_and_observer(void) {
    zai_hook_clean();

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

void dd_force_shutdown_tracing(void) {
    DDTRACE_G(in_shutdown) = true;

    zend_try {
        ddtrace_close_all_open_spans(true);  // All remaining userland spans (and root span)
    } zend_catch {
        LOG(WARN, "Failed to close remaining spans due to bailout");
    } zend_end_try();

    zend_try {
        if (ddtrace_flush_tracer(false, true) == FAILURE) {
            LOG(WARN, "Unable to flush the tracer");
        }
    } zend_catch {
        LOG(WARN, "Unable to flush the tracer due to bailout");
    } zend_end_try();

    // we here need to disable the tracer, so that further hooks do not trigger
    ddtrace_disable_tracing_in_current_request();  // implicitly calling dd_clean_globals

    // The hooks shall not be reset, just disabled at runtime.
    dd_shutdown_hooks_and_observer();

    DDTRACE_G(in_shutdown) = false;
}

static void dd_finalize_telemetry(void) {
    if (DDTRACE_G(telemetry_queue_id)) {
        ddtrace_telemetry_finalize();
        DDTRACE_G(telemetry_queue_id) = 0;
    }
}

static PHP_RSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    zend_hash_destroy(&DDTRACE_G(traced_spans));

    // this needs to be done before dropping the spans
    // run unconditionally because ddtrace may've been disabled mid-request
    ddtrace_exec_handlers_rshutdown();

    if (get_DD_TRACE_ENABLED()) {
        dd_force_shutdown_tracing();
    } else if (!ddtrace_disable) {
        dd_shutdown_hooks_and_observer();
    }

    if (!ddtrace_disable) {
        ddtrace_autoload_rshutdown();

        OBJ_RELEASE(&DDTRACE_G(active_stack)->std);
        DDTRACE_G(active_stack) = NULL;
    }

    dd_finalize_telemetry();
    ddtrace_telemetry_rshutdown();

    if (DDTRACE_G(last_flushed_root_service_name)) {
        zend_string_release(DDTRACE_G(last_flushed_root_service_name));
        DDTRACE_G(last_flushed_root_service_name) = NULL;
    }
    if (DDTRACE_G(last_flushed_root_env_name)) {
        zend_string_release(DDTRACE_G(last_flushed_root_env_name));
        DDTRACE_G(last_flushed_root_env_name) = NULL;
    }

    ddtrace_clean_git_object();

    return SUCCESS;
}

#if PHP_VERSION_ID < 80000
int ddtrace_post_deactivate(void) {
#else
zend_result ddtrace_post_deactivate(void) {
#endif
    zai_interceptor_deactivate();

    // we can only actually free our hooks hashtables in post_deactivate, as within RSHUTDOWN some user code may still run
    zai_hook_rshutdown();
    zai_uhook_rshutdown();

    // zai config may be accessed indirectly via other modules RSHUTDOWN, so delay this until the last possible time
    zai_config_rshutdown();
    return SUCCESS;
}

void ddtrace_disable_tracing_in_current_request(void) {
    // PHP 8 has ZSTR_CHAR('0') which is nicer...
    zend_string *zero = zend_string_init("0", 1, 0);
    zend_alter_ini_entry(zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_ENABLED].ini_entries[0]->name, zero,
                         ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
    zend_string_release(zero);
}

bool ddtrace_alter_dd_trace_disabled_config(zval *old_value, zval *new_value) {
    if (Z_TYPE_P(old_value) == Z_TYPE_P(new_value)) {
        return true;
    }

    if (ddtrace_disable) {
        return Z_TYPE_P(new_value) == IS_FALSE;  // no changing to enabled allowed if globally disabled
    }

    if (!DDTRACE_G(active_stack)) {
        return true; // We must not do anything early in RINIT before the necessary structures are initialized at all
    }

    if (Z_TYPE_P(old_value) == IS_FALSE) {
        dd_initialize_request();
    } else if (!ddtrace_disable) {  // if this is true, the request has not been initialized at all
        ddtrace_close_all_open_spans(false);  // All remaining userland spans (and root span)
        dd_clean_globals();
    }

    return true;
}

static size_t datadog_info_print(const char *str) { return php_output_write(str, strlen(str)); }

static void _dd_info_tracer_config(void) {
    smart_str buf = {0};
    ddtrace_startup_logging_json(&buf, PHP_JSON_PRETTY_PRINT);
    php_info_print_table_row(2, "DATADOG TRACER CONFIGURATION", ZSTR_VAL(buf.s));
    smart_str_free(&buf);
}

static void _dd_info_diagnostics_row(const char *key, const char *value) {
    if (sapi_module.phpinfo_as_text) {
        php_info_print_table_row(2, key, value);
        return;
    }
    datadog_info_print("<tr><td class='e'>");
    datadog_info_print(key);
    datadog_info_print("</td><td class='v' style='background-color:#f0e881;'>");
    datadog_info_print(value);
    datadog_info_print("</td></tr>");
}

static void _dd_info_diagnostics_table(void) {
    php_info_print_table_start();
    php_info_print_table_colspan_header(2, "Diagnostics");

    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, 8, NULL, ZVAL_PTR_DTOR, 0);

    ddtrace_startup_diagnostics(ht, false);

    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, key, val) {
        switch (Z_TYPE_P(val)) {
            case IS_STRING:
                _dd_info_diagnostics_row(ZSTR_VAL(key), Z_STRVAL_P(val));
                break;
            case IS_NULL:
                _dd_info_diagnostics_row(ZSTR_VAL(key), "NULL");
                break;
            case IS_TRUE:
            case IS_FALSE:
                _dd_info_diagnostics_row(ZSTR_VAL(key), Z_TYPE_P(val) == IS_TRUE ? "true" : "false");
                break;
            default:
                _dd_info_diagnostics_row(ZSTR_VAL(key), "{unknown type}");
                break;
        }
    }
    ZEND_HASH_FOREACH_END();

    php_info_print_table_row(2, "Diagnostic checks", zend_hash_num_elements(ht) == 0 ? "passed" : "failed");

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);

    php_info_print_table_end();
}

static PHP_MINFO_FUNCTION(ddtrace) {
    UNUSED(zend_module);

    php_info_print_box_start(0);
    datadog_info_print("Datadog PHP tracer extension");
    if (!sapi_module.phpinfo_as_text) {
        datadog_info_print("<br><strong>For help, check out ");
        datadog_info_print(
            "<a href=\"https://docs.datadoghq.com/tracing/languages/php/\" "
            "style=\"background:transparent;\">the documentation</a>.</strong>");
    } else {
        datadog_info_print(
            "\nFor help, check out the documentation at "
            "https://docs.datadoghq.com/tracing/languages/php/");
    }
    datadog_info_print(!sapi_module.phpinfo_as_text ? "<br><br>" : "\n");
    datadog_info_print("(c) Datadog 2020\n");
    php_info_print_box_end();

    php_info_print_table_start();
    php_info_print_table_row(2, "Datadog tracing support", ddtrace_disable ? "disabled" : "enabled");
    php_info_print_table_row(2, "Version", PHP_DDTRACE_VERSION);
    _dd_info_tracer_config();
    php_info_print_table_end();

    if (!ddtrace_disable) {
        _dd_info_diagnostics_table();
    }

    DISPLAY_INI_ENTRIES();
}

/* {{{ proto string DDTrace\add_global_tag(string $key, string $value) */
PHP_FUNCTION(DDTrace_add_global_tag) {
    UNUSED(execute_data);

    zend_string *key, *val;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SS", &key, &val) == FAILURE) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    zval value_zv;
    ZVAL_STR_COPY(&value_zv, val);
    zend_hash_update(DDTRACE_G(additional_global_tags), key, &value_zv);

    RETURN_NULL();
}

/* {{{ proto string DDTrace\add_distributed_tag(string $key, string $value) */
PHP_FUNCTION(DDTrace_add_distributed_tag) {
    UNUSED(execute_data);

    zend_string *key, *val;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SS", &key, &val) == FAILURE) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    zend_string *prefixed_key = zend_strpprintf(0, "_dd.p.%s", ZSTR_VAL(key));

    zend_array *target_table, *propagated;
    if (DDTRACE_G(active_stack)->root_span) {
        target_table = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_meta);
        propagated = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_propagated_tags);
    } else {
        target_table = &DDTRACE_G(root_span_tags_preset);
        propagated = &DDTRACE_G(propagated_root_span_tags);
    }

    zval value_zv;
    ZVAL_STR_COPY(&value_zv, val);
    zend_hash_update(target_table, prefixed_key, &value_zv);

    zend_hash_add_empty_element(propagated, prefixed_key);

    zend_string_release(prefixed_key);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_set_user) {
    UNUSED(execute_data);

    zend_string *user_id;
    HashTable *metadata = NULL;
    zend_bool propagate = get_DD_TRACE_PROPAGATE_USER_ID_DEFAULT();
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S|hb", &user_id, &metadata, &propagate) == FAILURE) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    if (user_id == NULL || ZSTR_LEN(user_id) == 0) {
        LOG_LINE(WARN, "Unexpected empty user id in DDTrace\\set_user");
        RETURN_NULL();
    }

    zend_array *target_table, *propagated;
    if (DDTRACE_G(active_stack)->root_span) {
        target_table = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_meta);
        propagated = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_propagated_tags);
    } else {
        target_table = &DDTRACE_G(root_span_tags_preset);
        propagated = &DDTRACE_G(propagated_root_span_tags);
    }

    zval user_id_zv;
    ZVAL_STR_COPY(&user_id_zv, user_id);
    zend_hash_str_update(target_table, ZEND_STRL("usr.id"), &user_id_zv);

    if (propagate) {
        zval value_zv;
        zend_string *encoded_user_id = php_base64_encode_str(user_id);
        ZVAL_STR(&value_zv, encoded_user_id);
        zend_hash_str_update(target_table, ZEND_STRL("_dd.p.usr.id"), &value_zv);

        zend_hash_str_add_empty_element(propagated, ZEND_STRL("_dd.p.usr.id"));
    }

    if (metadata != NULL) {
        zend_string *key;
        zval *value;
        ZEND_HASH_FOREACH_STR_KEY_VAL(metadata, key, value)
        {
            if (!key || Z_TYPE_P(value) != IS_STRING) {
                continue;
            }

            zend_string *prefixed_key = zend_strpprintf(0, "usr.%s", ZSTR_VAL(key));

            zval value_copy;
            ZVAL_COPY(&value_copy, value);
            zend_hash_update(target_table, prefixed_key, &value_copy);

            zend_string_release(prefixed_key);
        }
        ZEND_HASH_FOREACH_END();
    }

    RETURN_NULL();
}

PHP_FUNCTION(dd_trace_serialize_closed_spans) {
    UNUSED(execute_data);

    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        array_init(return_value);
        return;
    }

    ddtrace_mark_all_span_stacks_flushable();

    array_init(return_value);
    ddtrace_serialize_closed_spans_with_cycle(return_value);

    ddtrace_free_span_stacks(false);
    ddtrace_init_span_stacks();
}

PHP_FUNCTION(dd_trace_env_config) {
    UNUSED(execute_data);
    zend_string *env_name;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &env_name) == FAILURE) {
        RETURN_NULL();
    }

    zai_config_id id;
    if (zai_config_get_id_by_name((zai_str)ZAI_STR_FROM_ZSTR(env_name), &id)) {
        RETURN_COPY(zai_config_get_value(id));
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(dd_trace_disable_in_request) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    ddtrace_disable_tracing_in_current_request();

    RETURN_BOOL(1);
}

PHP_FUNCTION(dd_trace_reset) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (ddtrace_disable) {
        RETURN_BOOL(0);
    }

    // TODO ??
    RETURN_BOOL(1);
}

/* {{{ proto string dd_trace_serialize_msgpack(array trace_array) */
PHP_FUNCTION(dd_trace_serialize_msgpack) {
    zval *trace_array;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &trace_array) == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    if (ddtrace_serialize_simple_array(trace_array, return_value) != 1) {
        RETURN_BOOL(0);
    }
} /* }}} */

// method used to be able to easily breakpoint the execution at specific PHP line in GDB
PHP_FUNCTION(dd_trace_noop) {
    UNUSED(execute_data);

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    RETURN_BOOL(1);
}

/* {{{ proto int dd_trace_dd_get_memory_limit() */
PHP_FUNCTION(dd_trace_dd_get_memory_limit) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_LONG(ddtrace_get_memory_limit());
}

/* {{{ proto bool dd_trace_check_memory_under_limit() */
PHP_FUNCTION(dd_trace_check_memory_under_limit) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_BOOL(ddtrace_is_memory_under_limit());
}

typedef zend_long ddtrace_zpplong_t;

PHP_FUNCTION(ddtrace_config_app_name) {
    zend_string *default_app_name = NULL, *app_name = get_DD_SERVICE();
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|S", &default_app_name) != SUCCESS) {
        RETURN_NULL();
    }

    if (default_app_name == NULL && ZSTR_LEN(app_name) == 0) {
        RETURN_NULL();
    }

    RETURN_STR(php_trim(ZSTR_LEN(app_name) ? app_name : default_app_name, NULL, 0, 3));
}

PHP_FUNCTION(ddtrace_config_distributed_tracing_enabled) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_BOOL(get_DD_DISTRIBUTED_TRACING());
}

PHP_FUNCTION(ddtrace_config_trace_enabled) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_BOOL(get_DD_TRACE_ENABLED());
}

PHP_FUNCTION(ddtrace_config_integration_enabled) {
    ddtrace_string name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &name.ptr, &name.len) != SUCCESS) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_FALSE;
    }

    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_TRUE;
    }
    RETVAL_BOOL(ddtrace_config_integration_enabled(integration->name));
}

PHP_FUNCTION(DDTrace_Config_integration_analytics_enabled) {
    ddtrace_string name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &name.ptr, &name.len) != SUCCESS) {
        RETURN_NULL();
    }
    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_FALSE;
    }
    RETVAL_BOOL(integration->is_analytics_enabled());
}

PHP_FUNCTION(DDTrace_Config_integration_analytics_sample_rate) {
    ddtrace_string name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &name.ptr, &name.len) != SUCCESS) {
        RETURN_NULL();
    }
    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_DOUBLE(DD_INTEGRATION_ANALYTICS_SAMPLE_RATE_DEFAULT);
    }
    RETVAL_DOUBLE(integration->get_sample_rate());
}

/* This is only exposed to serialize the container ID into an HTTP Agent header for the userland transport
 * (`DDTrace\Transport\Http`). The background sender (extension-level transport) is decoupled from userland
 * code to create any HTTP Agent headers. Once the dependency on the userland transport has been removed,
 * this function can also be removed.
 */
PHP_FUNCTION(DDTrace_System_container_id) {
    UNUSED(execute_data);
    ddog_CharSlice id = ddtrace_get_container_id();
    if (id.len) {
        RETVAL_STRINGL(id.ptr, id.len);
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(DDTrace_Testing_trigger_error) {
    ddtrace_string message;
    ddtrace_zpplong_t error_type;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "sl", &message.ptr, &message.len, &error_type) != SUCCESS) {
        RETURN_NULL();
    }

    int level = (int)error_type;
    switch (level) {
        case E_ERROR:
        case E_WARNING:
        case E_PARSE:
        case E_NOTICE:
        case E_CORE_ERROR:
        case E_CORE_WARNING:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_USER_WARNING:
        case E_USER_NOTICE:
        case E_STRICT:
        case E_RECOVERABLE_ERROR:
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            zend_error(level, "%s", message.ptr);
            break;

        default:
            LOG_LINE(WARN, "Invalid error type specified: %i", level);
            break;
    }
}

PHP_FUNCTION(DDTrace_Internal_add_span_flag) {
    zend_object *span;
    zend_long flag;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJ_OF_CLASS_EX(span, ddtrace_ce_span_data, 0, 1)
        Z_PARAM_LONG(flag)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_span_data *span_data = OBJ_SPANDATA(span);
    span_data->flags |= (uint8_t)flag;

    RETURN_NULL();
}

void dd_internal_handle_fork(void) {
    // CHILD PROCESS
#ifndef _WIN32
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_curl_shutdown();
        ddtrace_coms_clean_background_sender_after_fork();
    }
#endif
    if (DDTRACE_G(remote_config_reader)) {
        ddog_agent_remote_config_reader_drop(DDTRACE_G(remote_config_reader));
        DDTRACE_G(remote_config_reader) = NULL;
    }
    ddtrace_seed_prng();
    ddtrace_generate_runtime_id();
    ddtrace_reset_sidecar_globals();
    if (!get_DD_TRACE_FORKED_PROCESS()) {
        ddtrace_disable_tracing_in_current_request();
    }
    if (get_DD_TRACE_ENABLED()) {
        if (get_DD_DISTRIBUTED_TRACING()) {
            DDTRACE_G(distributed_parent_trace_id) = ddtrace_peek_span_id();
            DDTRACE_G(distributed_trace_id) = ddtrace_peek_trace_id();
        } else {
            DDTRACE_G(distributed_parent_trace_id) = 0;
            DDTRACE_G(distributed_trace_id) = (ddtrace_trace_id){ 0 };
        }
        ddtrace_free_span_stacks(true);
        ddtrace_init_span_stacks();
        if (get_DD_TRACE_GENERATE_ROOT_SPAN()) {
            ddtrace_push_root_span();
        }
    }

#ifndef _WIN32
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_init_and_start_writer();

        if (ddtrace_coms_agent_config_handle) {
            ddog_agent_remote_config_reader_for_anon_shm(ddtrace_coms_agent_config_handle, &DDTRACE_G(remote_config_reader));
        }
    }
#endif
}

/* {{{ proto void DDTrace\handle_fork(): void */
PHP_FUNCTION(DDTrace_Internal_handle_fork) {
    UNUSED(execute_data);
    UNUSED(return_value);
    dd_internal_handle_fork();
}

PHP_FUNCTION(DDTrace_dogstatsd_count) {
    zend_string *metric;
    zend_long value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_LONG(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_sidecar_dogstatsd_count(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_dogstatsd_distribution) {
    zend_string *metric;
    double value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_DOUBLE(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_sidecar_dogstatsd_distribution(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_dogstatsd_gauge) {
    zend_string *metric;
    double value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_DOUBLE(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_sidecar_dogstatsd_gauge(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_dogstatsd_histogram) {
    zend_string *metric;
    double value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_DOUBLE(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_sidecar_dogstatsd_histogram(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_dogstatsd_set) {
    zend_string *metric;
    zend_long value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_LONG(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_sidecar_dogstatsd_set(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(dd_trace_send_traces_via_thread) {
    char *payload = NULL;
    ddtrace_zpplong_t num_traces = 0;
    ddtrace_zppstrlen_t payload_len = 0;
    zval *curl_headers = NULL;

    // Agent HTTP headers are now set at the extension level so 'curl_headers' from userland is ignored
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "las", &num_traces, &curl_headers, &payload,
                                 &payload_len) == FAILURE) {
        RETURN_THROWS();
    }
#ifndef _WIN32
    bool result = ddtrace_send_traces_via_thread(num_traces, payload, payload_len);
    dd_prepare_for_new_trace();
    RETURN_BOOL(result);
#else
    RETURN_FALSE;
#endif
}

PHP_FUNCTION(dd_trace_buffer_span) {
    zval *trace_array = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &trace_array) == FAILURE) {
        RETURN_THROWS();
    }

#ifndef _WIN32
    if (!get_DD_TRACE_ENABLED() || get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        RETURN_BOOL(0);
    }

    char *data;
    size_t size;
    if (ddtrace_serialize_simple_array_into_c_string(trace_array, &data, &size)) {
        RETVAL_BOOL(ddtrace_coms_buffer_data(DDTRACE_G(traces_group_id), data, size));

        free(data);
        return;
    } else {
        RETURN_FALSE;
    }
#else
    RETURN_BOOL(0);
#endif
}

PHP_FUNCTION(dd_trace_coms_trigger_writer_flush) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

#ifndef _WIN32
    if (!get_DD_TRACE_ENABLED() || get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        RETURN_LONG(0);
    }

    RETURN_LONG(ddtrace_coms_trigger_writer_flush());
#else
    RETURN_BOOL(0);
#endif
}

#define FUNCTION_NAME_MATCHES(function) zend_string_equals_literal(function_val, function)

PHP_FUNCTION(dd_trace_internal_fn) {
    UNUSED(execute_data);
    zval ***params = NULL;
    uint32_t params_count = 0;

    zend_string *function_val = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S*", &function_val, &params, &params_count) != SUCCESS) {
        RETURN_BOOL(0);
    }

    RETVAL_FALSE;
    if (ZSTR_LEN(function_val) > 0) {
        if (FUNCTION_NAME_MATCHES("finalize_telemetry")) {
            dd_finalize_telemetry();
            RETVAL_TRUE;
        } else if (params_count == 1 && FUNCTION_NAME_MATCHES("detect_composer_installed_json")) {
            ddog_CharSlice path = dd_zend_string_to_CharSlice(Z_STR_P(ZVAL_VARARG_PARAM(params, 0)));
            ddtrace_detect_composer_installed_json(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id), path);
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("dump_sidecar")) {
            if (!ddtrace_sidecar) {
                RETURN_FALSE;
            }
            ddog_CharSlice slice = ddog_sidecar_dump(&ddtrace_sidecar);
            RETVAL_STRINGL(slice.ptr, slice.len);
            free((void *) slice.ptr);
        } else if (FUNCTION_NAME_MATCHES("stats_sidecar")) {
            if (!ddtrace_sidecar) {
                RETURN_FALSE;
            }
            ddog_CharSlice slice = ddog_sidecar_stats(&ddtrace_sidecar);
            RETVAL_STRINGL(slice.ptr, slice.len);
            free((void *) slice.ptr);
        } else if (FUNCTION_NAME_MATCHES("synchronous_flush")) {
            uint32_t timeout = 100;
            if (params_count == 1) {
                timeout = Z_LVAL_P(ZVAL_VARARG_PARAM(params, 0));
            }
#ifndef _WIN32
            if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
                if (dd_rinit_once_done) {
                    ddtrace_coms_synchronous_flush(timeout);
                }
            } else
#endif
            if (ddtrace_sidecar) {
                ddog_sidecar_flush_traces(&ddtrace_sidecar);
            }
            RETVAL_TRUE;
#ifndef _WIN32
        } else if (FUNCTION_NAME_MATCHES("init_and_start_writer")) {
            RETVAL_BOOL(ddtrace_coms_init_and_start_writer());
        } else if (FUNCTION_NAME_MATCHES("ddtrace_coms_next_group_id")) {
            RETVAL_LONG(ddtrace_coms_next_group_id());
        } else if (params_count == 2 && FUNCTION_NAME_MATCHES("ddtrace_coms_buffer_span")) {
            zval *group_id = ZVAL_VARARG_PARAM(params, 0);
            zval *trace_array = ZVAL_VARARG_PARAM(params, 1);
            char *data = NULL;
            size_t size = 0;
            if (ddtrace_serialize_simple_array_into_c_string(trace_array, &data, &size)) {
                RETVAL_BOOL(ddtrace_coms_buffer_data(Z_LVAL_P(group_id), data, size));
                free(data);
            } else {
                RETVAL_FALSE;
            }
        } else if (params_count == 2 && FUNCTION_NAME_MATCHES("ddtrace_coms_buffer_data")) {
            zval *group_id = ZVAL_VARARG_PARAM(params, 0);
            zval *data = ZVAL_VARARG_PARAM(params, 1);
            RETVAL_BOOL(ddtrace_coms_buffer_data(Z_LVAL_P(group_id), Z_STRVAL_P(data), Z_STRLEN_P(data)));
        } else if (FUNCTION_NAME_MATCHES("shutdown_writer")) {
            RETVAL_BOOL(ddtrace_coms_flush_shutdown_writer_synchronous());
        } else if (params_count == 1 && FUNCTION_NAME_MATCHES("set_writer_send_on_flush")) {
            RETVAL_BOOL(ddtrace_coms_set_writer_send_on_flush(IS_TRUE_P(ZVAL_VARARG_PARAM(params, 0))));
        } else if (FUNCTION_NAME_MATCHES("test_consumer")) {
            ddtrace_coms_test_consumer();
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("test_writers")) {
            ddtrace_coms_test_writers();
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("test_msgpack_consumer")) {
            ddtrace_coms_test_msgpack_consumer();
            RETVAL_TRUE;
#endif
        } else if (FUNCTION_NAME_MATCHES("test_logs")) {
            ddog_logf(DDOG_LOG_WARN, false, "foo");
            ddog_logf(DDOG_LOG_WARN, false, "bar");
            ddog_logf(DDOG_LOG_ERROR, false, "Boum");
            RETVAL_TRUE;
        }
    }
}

/* {{{ proto int DDTrace\close_spans_until(DDTrace\SpanData) */
PHP_FUNCTION(DDTrace_close_spans_until) {
    zval *spanzv = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "O!", &spanzv, ddtrace_ce_span_data) == FAILURE) {
        RETURN_THROWS();
    }

    int closed_spans = ddtrace_close_userland_spans_until(spanzv ? OBJ_SPANDATA(Z_OBJ_P(spanzv)) : NULL);

    if (closed_spans == -1) {
        RETURN_FALSE;
    }
    RETURN_LONG(closed_spans);
}

/* {{{ proto string dd_trace_set_trace_id() */
PHP_FUNCTION(dd_trace_set_trace_id) {
    UNUSED(execute_data);

    zend_string *trace_id = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &trace_id) == FAILURE) {
        RETURN_THROWS();
    }

    ddtrace_trace_id new_trace_id = ddtrace_parse_userland_trace_id(trace_id);
    if (new_trace_id.low || new_trace_id.high || (ZSTR_LEN(trace_id) == 1 && ZSTR_VAL(trace_id)[0] == '0')) {
        DDTRACE_G(distributed_trace_id) = new_trace_id;
        RETURN_TRUE;
    }

    RETURN_FALSE;
}

/* {{{ proto string dd_trace_peek_span_id() */
PHP_FUNCTION(dd_trace_peek_span_id) {
    UNUSED(execute_data);
    RETURN_STR(ddtrace_span_id_as_string(ddtrace_peek_span_id()));
}

/* {{{ proto void dd_trace_close_all_spans_and_flush() */
PHP_FUNCTION(dd_trace_close_all_spans_and_flush) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }
    ddtrace_close_all_spans_and_flush();
    RETURN_NULL();
}

/* {{{ proto void dd_trace_synchronous_flush(int) */
PHP_FUNCTION(dd_trace_synchronous_flush) {
    zend_long timeout = 100;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &timeout) == FAILURE) {
        RETURN_THROWS();
    }

    // If zend_long is not a uint32_t, we can't pass it to ddtrace_coms_synchronous_flush
    if (timeout < 0 || timeout > UINT32_MAX) {
        LOG_LINE_ONCE(ERROR, "dd_trace_synchronous_flush() expects a timeout in milliseconds");
        RETURN_NULL();
    }

#ifndef _WIN32
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        if (dd_rinit_once_done) {
            ddtrace_coms_synchronous_flush(timeout);
        }
    } else
#endif
    if (ddtrace_sidecar) {
        ddog_sidecar_flush_traces(&ddtrace_sidecar);
    }
    RETURN_NULL();
}

static void dd_ensure_root_span(void) {
    if (!DDTRACE_G(active_stack)->root_span && DDTRACE_G(active_stack)->parent_stack == NULL && get_DD_TRACE_GENERATE_ROOT_SPAN()) {
        ddtrace_push_root_span();  // ensure root span always exists, especially after serialization for testing
    }
}

/* {{{ proto string DDTrace\active_span() */
PHP_FUNCTION(DDTrace_active_span) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    dd_ensure_root_span();
    ddtrace_span_data *span = ddtrace_active_span();
    if (span) {
        RETURN_OBJ_COPY(&span->std);
    }
    RETURN_NULL();
}

/* {{{ proto string DDTrace\root_span() */
PHP_FUNCTION(DDTrace_root_span) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    dd_ensure_root_span();
    ddtrace_root_span_data *span = DDTRACE_G(active_stack)->root_span;
    if (span) {
        RETURN_OBJ_COPY(&span->std);
    }
    RETURN_NULL();
}

static inline void dd_start_span(INTERNAL_FUNCTION_PARAMETERS) {
    double start_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &start_time_seconds) != SUCCESS) {
        LOG_LINE_ONCE(WARN, "unexpected parameter, expecting double for start time");
        RETURN_FALSE;
    }

    ddtrace_span_data *span;

    if (get_DD_TRACE_ENABLED()) {
        span = ddtrace_open_span(DDTRACE_USER_SPAN);
    } else {
        span = ddtrace_init_dummy_span();
    }

    if (start_time_seconds > 0) {
        span->start = (uint64_t)(start_time_seconds * ZEND_NANO_IN_SEC);
    }

    RETURN_OBJ(&span->std);
}

/* {{{ proto string DDTrace\start_span() */
PHP_FUNCTION(DDTrace_start_span) {
    dd_start_span(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* {{{ proto string DDTrace\start_trace_span() */
PHP_FUNCTION(DDTrace_start_trace_span) {
    if (get_DD_TRACE_ENABLED()) {
        ddtrace_span_stack *stack = ddtrace_init_root_span_stack();
        ddtrace_switch_span_stack(stack);
        GC_DELREF(&stack->std); // We don't retain a ref to it, it's now the active_stack
    }
    dd_start_span(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static void dd_set_span_finish_time(ddtrace_span_data *span, double finish_time_seconds) {
    // we do not expose the monotonic time here, so do not use it as reference time to calculate difference
    uint64_t start_time = span->start;
    uint64_t finish_time = (uint64_t)(finish_time_seconds * 1000000000);
    if (finish_time < start_time) {
        dd_trace_stop_span_time(span);
    } else {
        span->duration = finish_time - start_time;
    }
}

/* {{{ proto string DDTrace\close_span() */
PHP_FUNCTION(DDTrace_close_span) {
    double finish_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &finish_time_seconds) != SUCCESS) {
        LOG_LINE_ONCE(WARN, "unexpected parameter, expecting double for finish time");
        RETURN_FALSE;
    }

    ddtrace_span_data *top_span = ddtrace_active_span();

    if (!top_span || top_span->type != DDTRACE_USER_SPAN) {
        LOG(ERROR, "There is no user-span on the top of the stack. Cannot close.");
        RETURN_NULL();
    }

    dd_set_span_finish_time(top_span, finish_time_seconds);

    ddtrace_close_span(top_span);
    RETURN_NULL();
}

/* {{{ proto string DDTrace\update_span_duration() */
PHP_FUNCTION(DDTrace_update_span_duration) {
    double finish_time_seconds = 0;
    zval *spanzv = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "O|d", &spanzv, ddtrace_ce_span_data, &finish_time_seconds) != SUCCESS) {
        RETURN_FALSE;
    }

    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(spanzv));

    if (span->duration == 0) {
        LOG(ERROR, "Cannot update the span duration of an unfinished span.");
        RETURN_NULL();
    }

    if (span->duration == DDTRACE_DROPPED_SPAN || span->duration == DDTRACE_SILENTLY_DROPPED_SPAN) {
        RETURN_NULL();
    }

    dd_set_span_finish_time(span, finish_time_seconds);

    RETURN_NULL();
}

/* {{{ proto string DDTrace\active_stack() */
PHP_FUNCTION(DDTrace_active_stack) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!DDTRACE_G(active_stack)) {
        RETURN_NULL();
    }
    RETURN_OBJ_COPY(&DDTRACE_G(active_stack)->std);
}

/* {{{ proto string DDTrace\create_stack() */
PHP_FUNCTION(DDTrace_create_stack) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_OBJ(&ddtrace_init_root_span_stack()->std);
    }

    ddtrace_span_stack *stack = ddtrace_init_span_stack();
    ddtrace_switch_span_stack(stack);
    RETURN_OBJ(&stack->std);
}

/* {{{ proto string DDTrace\switch_stack(DDTrace\SpanData|DDTrace\SpanStack) */
PHP_FUNCTION(DDTrace_switch_stack) {
    ddtrace_span_stack *stack = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        DD_PARAM_PROLOGUE(0, 0);
        if (Z_TYPE_P(_arg) == IS_OBJECT && (instanceof_function(Z_OBJCE_P(_arg), ddtrace_ce_span_data) || Z_OBJCE_P(_arg) == ddtrace_ce_span_stack)) {
            stack = (ddtrace_span_stack *) Z_OBJ_P(_arg);
            if (instanceof_function(Z_OBJCE_P(_arg), ddtrace_ce_span_data)) {
                stack = OBJ_SPANDATA(Z_OBJ_P(_arg))->stack;
            }
        } else {
            zend_argument_type_error(1, "must be of type DDTrace\\SpanData|DDTrace\\SpanStack, %s given", zend_zval_value_name(_arg));
            _error_code = ZPP_ERROR_FAILURE;
            break;
        }
    ZEND_PARSE_PARAMETERS_END();

    if (!DDTRACE_G(active_stack)) {
        RETURN_NULL();
    }

    if (stack) {
        ddtrace_switch_span_stack(stack);
    } else if (DDTRACE_G(active_stack)->parent_stack) {
        ddtrace_switch_span_stack(DDTRACE_G(active_stack)->parent_stack);
    }

    RETURN_OBJ_COPY(&DDTRACE_G(active_stack)->std);
}

PHP_FUNCTION(DDTrace_flush) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (get_DD_AUTOFINISH_SPANS()) {
        ddtrace_close_userland_spans_until(NULL);
    }
    if (ddtrace_flush_tracer(false, get_DD_TRACE_FLUSH_COLLECT_CYCLES()) == FAILURE) {
        LOG_LINE(WARN, "Unable to flush the tracer");
    }
    RETURN_NULL();
}

/* {{{ proto string \DDTrace\trace_id() */
PHP_FUNCTION(DDTrace_trace_id) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_STR(ddtrace_trace_id_as_string(ddtrace_peek_trace_id()));
}

/* {{{ proto string \DDTrace\logs_correlation_trace_id() */
PHP_FUNCTION(DDTrace_logs_correlation_trace_id) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    ddtrace_trace_id trace_id = ddtrace_peek_trace_id();

    if (get_DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED()) {
        // The format of the injected trace id is conditional based on the higher-order 64 bits of the trace id
        uint64_t high = trace_id.high;
        if (high == 0) {
            // If zero, the injected trace id will be its decimal string encoding (preserving the current behavior of 64-bit TraceIds)
            RETURN_STR(ddtrace_trace_id_as_string(trace_id));
        } else {
            // The injected trace id will be encoded as 32 lower-case hexadecimal characters with zero-padding as necessary
            RETURN_STR(ddtrace_trace_id_as_hex_string(trace_id));
        }
    } else {
        // The injected trace id is the decimal encoding of the lower-order 64-bits of the trace id
        RETURN_STR(ddtrace_span_id_as_string(trace_id.low));
    }
}

/* {{{ proto array \DDTrace\current_context() */
PHP_FUNCTION(DDTrace_current_context) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    array_init(return_value);

    add_assoc_str_ex(return_value, ZEND_STRL("trace_id"), ddtrace_trace_id_as_string(ddtrace_peek_trace_id()));
    add_assoc_str_ex(return_value, ZEND_STRL("span_id"), ddtrace_span_id_as_string(ddtrace_peek_span_id()));

    zval zv;

    // Add Version
    ZVAL_STR_COPY(&zv, get_DD_VERSION());
    if (Z_STRLEN(zv) == 0) {
        zend_string_release(Z_STR(zv));
        ZVAL_NULL(&zv);
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("version"), &zv);

    // Add Env
    ZVAL_STR_COPY(&zv, get_DD_ENV());
    if (Z_STRLEN(zv) == 0) {
        zend_string_release(Z_STR(zv));
        ZVAL_NULL(&zv);
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("env"), &zv);

    if (DDTRACE_G(active_stack) && DDTRACE_G(active_stack)->active) {
        ddtrace_root_span_data *root = SPANDATA(DDTRACE_G(active_stack)->active)->root;
        zval *origin = &root->property_origin;
        if (Z_TYPE_P(origin) > IS_NULL && (Z_TYPE_P(origin) != IS_STRING || Z_STRLEN_P(origin))) {
            Z_TRY_ADDREF_P(origin);
            zend_hash_str_add_new(Z_ARR_P(return_value), ZEND_STRL("distributed_tracing_origin"), origin);
        }

        zval *parent_id = &root->property_parent_id;
        if (Z_TYPE_P(parent_id) == IS_STRING && Z_STRLEN_P(parent_id)) {
            Z_TRY_ADDREF_P(parent_id);
            zend_hash_str_add_new(Z_ARR_P(return_value), ZEND_STRL("distributed_tracing_parent_id"), parent_id);
        }
    } else {
        if (DDTRACE_G(dd_origin)) {
            add_assoc_str_ex(return_value, ZEND_STRL("distributed_tracing_origin"), zend_string_copy(DDTRACE_G(dd_origin)));
        }

        if (DDTRACE_G(distributed_parent_trace_id)) {
            add_assoc_str_ex(return_value, ZEND_STRL("distributed_tracing_parent_id"),
                             ddtrace_span_id_as_string(DDTRACE_G(distributed_parent_trace_id)));
        }
    }

    zval tags;
    array_init(&tags);
    if (get_DD_TRACE_ENABLED()) {
        ddtrace_get_propagated_tags(Z_ARR(tags));
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("distributed_tracing_propagated_tags"), &tags);
}

/* {{{ proto bool set_distributed_tracing_context(string $trace_id, string $parent_id, ?string $origin, array|string|null $tags) */
PHP_FUNCTION(DDTrace_set_distributed_tracing_context) {
    zend_string *trace_id_str, *parent_id_str, *origin = NULL;
    zval *tags = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SS|S!z!", &trace_id_str, &parent_id_str, &origin, &tags) != SUCCESS) {
        RETURN_THROWS();
    }

    if (tags && Z_TYPE_P(tags) > IS_FALSE && Z_TYPE_P(tags) != IS_ARRAY && Z_TYPE_P(tags) != IS_STRING) {
        zend_type_error("DDTrace\\set_distributed_tracing_context expects parameter 4 to be of type array, string or null, %s given", zend_zval_value_name(tags));
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_FALSE;
    }

    ddtrace_trace_id new_trace_id;
    if (ZSTR_LEN(trace_id_str) == 1 && ZSTR_VAL(trace_id_str)[0] == '0') {
        new_trace_id = (ddtrace_trace_id){ 0 };
    } else if (!(new_trace_id = ddtrace_parse_userland_trace_id(trace_id_str)).low && !new_trace_id.high) {
        RETURN_FALSE;
    }

    zval parent_zv;
    ZVAL_STR(&parent_zv, parent_id_str);
    uint64_t new_parent_id;
    if (ZSTR_LEN(parent_id_str) == 1 && ZSTR_VAL(parent_id_str)[0] == '0') {
        new_parent_id = 0;
    } else if (!(new_parent_id = ddtrace_parse_userland_span_id(&parent_zv))) {
        RETURN_FALSE;
    }

    ddtrace_root_span_data *root_span = DDTRACE_G(active_stack)->root_span;
    if (root_span) {
        root_span->parent_id = new_parent_id;
        if (!new_trace_id.low && !new_trace_id.high) {
            root_span->trace_id = (ddtrace_trace_id) {
                .low = root_span->span_id,
                .time = get_DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED() ? root_span->start / ZEND_NANO_IN_SEC : 0,
            };
        } else {
            root_span->trace_id = new_trace_id;
        }
        ddtrace_update_root_id_properties(root_span);
    } else {
        DDTRACE_G(distributed_trace_id) = new_trace_id;
        DDTRACE_G(distributed_parent_trace_id) = new_parent_id;
    }

    if (origin) {
        if (root_span) {
            zval zv;
            ZVAL_STR_COPY(&zv, origin);
            ddtrace_assign_variable(&root_span->property_origin, &zv);
        } else {
            if (DDTRACE_G(dd_origin)) {
                zend_string_release(DDTRACE_G(dd_origin));
            }
            DDTRACE_G(dd_origin) = ZSTR_LEN(origin) ? zend_string_copy(origin) : NULL;
        }
    }

    if (tags) {
        zend_array *root_meta = &DDTRACE_G(root_span_tags_preset);
        zend_array *propagated_tags = &DDTRACE_G(propagated_root_span_tags);
        if (root_span) {
            root_meta = ddtrace_property_array(&root_span->property_meta);
            propagated_tags = ddtrace_property_array(&root_span->property_propagated_tags);
        }

        if (Z_TYPE_P(tags) == IS_STRING) {
            ddtrace_add_tracer_tags_from_header(Z_STR_P(tags), root_meta, propagated_tags);
        } else if (Z_TYPE_P(tags) == IS_ARRAY) {
            ddtrace_add_tracer_tags_from_array(Z_ARR_P(tags), root_meta, propagated_tags);
        }
    }

    RETURN_TRUE;
}

typedef struct {
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
} dd_fci_fcc_pair;

static bool dd_read_userspace_header(zai_str zai_header, const char *lowercase_header, zend_string **header_value, void *data) {
    UNUSED(zai_header);
    dd_fci_fcc_pair *func = (dd_fci_fcc_pair *) data;
    zval retval, arg;
    func->fci.params = &arg;
    ZVAL_STRING(&arg, lowercase_header);

    if (zend_call_function_with_return_value(&func->fci, &func->fcc, &retval) != SUCCESS || Z_TYPE(retval) <= IS_NULL) {
        zval_ptr_dtor(&arg);
        return false;
    }

    *header_value = zval_get_string(&retval);

    zval_ptr_dtor(&arg);
    zval_ptr_dtor(&retval);

    return true;
}

static bool dd_read_array_header(zai_str zai_header, const char *lowercase_header, zend_string **header_value, void *data) {
    UNUSED(zai_header);
    zend_array *array = (zend_array *) data;
    zval *value = zend_hash_str_find(array, lowercase_header, strlen(lowercase_header));
    if (!value) {
        return false;
    }

    *header_value = zval_get_string(value);
    return true;
}

static ddtrace_distributed_tracing_result dd_parse_distributed_tracing_headers_function(INTERNAL_FUNCTION_PARAMETERS, bool *success) {
    UNUSED(return_value);

    dd_fci_fcc_pair func;
    bool use_server_headers = false;
    zend_array *array = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        DD_PARAM_PROLOGUE(0, 0);
        if (Z_TYPE_P(_arg) == IS_NULL) {
            use_server_headers = true;
        } else if (UNEXPECTED(!zend_parse_arg_func(_arg, &func.fci, &func.fcc, false, &_error, true))) {
            if (!_error) {
                zend_argument_type_error(1, "must be a valid callback or of type array, %s given", zend_zval_value_name(_arg));
                _error_code = ZPP_ERROR_FAILURE;
                break;
            } else if (Z_TYPE_P(_arg) == IS_ARRAY) {
                array = Z_ARR_P(_arg);
                efree(_error);
            } else {
                _error_code = ZPP_ERROR_WRONG_CALLBACK;
                break;
            }
#if PHP_VERSION_ID < 70300
        } else if (UNEXPECTED(_error != NULL)) {
#if PHP_VERSION_ID < 70200
            zend_wrong_callback_error(E_DEPRECATED, 1, _error);
#else
            zend_wrong_callback_error(_flags & ZEND_PARSE_PARAMS_THROW, E_DEPRECATED, 1, _error);
#endif
#endif
        }
    ZEND_PARSE_PARAMETERS_END_EX(*success = false; return (ddtrace_distributed_tracing_result){0});

    *success = true;
    if (!get_DD_TRACE_ENABLED()) {
        return (ddtrace_distributed_tracing_result){0};
    }

    func.fci.param_count = 1;

    if (array) {
        return ddtrace_read_distributed_tracing_ids(dd_read_array_header, array);
    } else if (use_server_headers) {
        return ddtrace_read_distributed_tracing_ids(ddtrace_read_zai_header, &func);
    } else {
        return ddtrace_read_distributed_tracing_ids(dd_read_userspace_header, &func);
    }
}

PHP_FUNCTION(DDTrace_consume_distributed_tracing_headers) {
    bool success;
    ddtrace_distributed_tracing_result result = dd_parse_distributed_tracing_headers_function(INTERNAL_FUNCTION_PARAM_PASSTHRU, &success);
    if (success && get_DD_TRACE_ENABLED()) {
        ddtrace_apply_distributed_tracing_result(&result, DDTRACE_G(active_stack)->root_span);
    }

    RETURN_NULL();
}

/* {{{ proto array generate_distributed_tracing_headers() */
PHP_FUNCTION(DDTrace_generate_distributed_tracing_headers) {
    zend_array *inject = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT_EX(inject, true, false)
    ZEND_PARSE_PARAMETERS_END();

    array_init(return_value);
    if (get_DD_TRACE_ENABLED()) {
        if (inject) {
            zend_array *inject_set = zend_new_array(zend_hash_num_elements(inject));
            zval *val;
            ZEND_HASH_FOREACH_VAL(inject, val) {
                if (Z_TYPE_P(val) == IS_STRING) {
                    zend_hash_add_empty_element(inject_set, Z_STR_P(val));
                }
            } ZEND_HASH_FOREACH_END();
            ddtrace_inject_distributed_headers_config(Z_ARR_P(return_value), true, inject_set);
            zend_array_destroy(inject_set);
        } else {
            ddtrace_inject_distributed_headers(Z_ARR_P(return_value), true);
        }
    }
}

/* {{{ proto string dd_trace_closed_spans_count() */
PHP_FUNCTION(dd_trace_closed_spans_count) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_LONG(DDTRACE_G(closed_spans_count));
}

bool ddtrace_tracer_is_limited(void) {
    int64_t limit = get_DD_TRACE_SPANS_LIMIT();
    if (limit >= 0) {
        int64_t open_spans = DDTRACE_G(open_spans_count);
        int64_t closed_spans = DDTRACE_G(closed_spans_count);
        if ((open_spans + closed_spans) >= limit) {
            return true;
        }
    }
    return !ddtrace_is_memory_under_limit();
}

/* {{{ proto string dd_trace_tracer_is_limited() */
PHP_FUNCTION(dd_trace_tracer_is_limited) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_BOOL(ddtrace_tracer_is_limited() == true ? 1 : 0);
}

/* {{{ proto string dd_trace_compile_time_microseconds() */
PHP_FUNCTION(dd_trace_compile_time_microseconds) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_LONG(ddtrace_compile_time_get());
}

PHP_FUNCTION(DDTrace_set_priority_sampling) {
    bool global = false;
    zend_long priority;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l|b", &priority, &global) == FAILURE) {
        RETURN_THROWS();
    }

    if (global || !DDTRACE_G(active_stack) || !DDTRACE_G(active_stack)->root_span) {
        DDTRACE_G(default_priority_sampling) = priority;
    } else {
        ddtrace_set_priority_sampling_on_root(priority, DD_MECHANISM_MANUAL);
    }
}

PHP_FUNCTION(DDTrace_get_priority_sampling) {
    zend_bool global = false;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|b", &global) == FAILURE) {
        RETURN_THROWS();
    }

    if (global || !DDTRACE_G(active_stack) || !DDTRACE_G(active_stack)->root_span) {
        RETURN_LONG(DDTRACE_G(default_priority_sampling));
    }

    RETURN_LONG(ddtrace_fetch_priority_sampling_from_root());
}

PHP_FUNCTION(DDTrace_get_sanitized_exception_trace) {
    zend_object *ex;
    zend_long skip = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJ_OF_CLASS(ex, zend_ce_throwable)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(skip)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_STR(zai_get_trace_without_args_from_exception_skip_frames(ex, skip));
}

PHP_FUNCTION(DDTrace_startup_logs) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    smart_str buf = {0};
    ddtrace_startup_logging_json(&buf, 0);
    ZVAL_NEW_STR(return_value, buf.s);
}

PHP_FUNCTION(DDTrace_find_active_exception) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    zend_object *ex = ddtrace_find_active_exception();
    if (ex) {
        RETURN_OBJ_COPY(ex);
    }
}

PHP_FUNCTION(DDTrace_extract_ip_from_headers) {
    zval *arr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &arr) == FAILURE) {
        return;
    }

    zval meta;
    array_init(&meta);
    ddtrace_extract_ip_from_headers(arr, Z_ARR(meta));

    RETURN_ARR(Z_ARR(meta));
}

PHP_FUNCTION(DDTrace_curl_multi_exec_get_request_spans) {
    zval *array;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(array)
    ZEND_PARSE_PARAMETERS_END();

    if (Z_TYPE_P(array) == IS_REFERENCE) {
        zend_reference *ref = Z_REF_P(array);

#if PHP_VERSION_ID < 70400
        array = &ref->val;
        zval_ptr_dtor(array);
        array_init(array);
#else
        array = zend_try_array_init(array);
        if (!array) {
            RETURN_THROWS();
        }
#endif

        if (get_DD_TRACE_ENABLED()) {
            if (DDTRACE_G(curl_multi_injecting_spans) && GC_DELREF(DDTRACE_G(curl_multi_injecting_spans)) == 0) {
                rc_dtor_func((zend_refcounted *) DDTRACE_G(curl_multi_injecting_spans));
            }

            GC_ADDREF(ref);
            DDTRACE_G(curl_multi_injecting_spans) = ref;
        }
    }

    RETURN_NULL();
}

static const zend_module_dep ddtrace_module_deps[] = {ZEND_MOD_REQUIRED("json") ZEND_MOD_REQUIRED("standard")
                                                          ZEND_MOD_END};

zend_module_entry ddtrace_module_entry = {STANDARD_MODULE_HEADER_EX, NULL,
                                          ddtrace_module_deps,       PHP_DDTRACE_EXTNAME,
                                          ext_functions,             PHP_MINIT(ddtrace),
                                          PHP_MSHUTDOWN(ddtrace),    PHP_RINIT(ddtrace),
                                          PHP_RSHUTDOWN(ddtrace),    PHP_MINFO(ddtrace),
                                          PHP_DDTRACE_VERSION,       PHP_MODULE_GLOBALS(ddtrace),
                                          PHP_GINIT(ddtrace),        PHP_GSHUTDOWN(ddtrace),
                                          ddtrace_post_deactivate,   STANDARD_MODULE_PROPERTIES_EX};

// the following operations are performed in order to put the tracer in a state when a new trace can be started:
//   - set a new trace (group) id
void dd_prepare_for_new_trace(void) {
#ifndef _WIN32
    DDTRACE_G(traces_group_id) = ddtrace_coms_next_group_id();
#endif
}
