#include "components-rs/common.h"
#include "components-rs/sidecar.h"
#include "zend_API.h"
#include "zend_hash.h"
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "ddtrace.h"
#include <Zend/zend.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_extensions.h>
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
#include <stdatomic.h>
#include <sys/mman.h>
#else
#include <components/atomic_win32_polyfill.h>
#endif

#include <components-rs/datadog.h>
#include <components/log/log.h>

#include "asm_event.h"
#include "trace_source.h"
#include "auto_flush.h"
#include <ext/compatibility.h>
#ifndef _WIN32
#include "coms.h"
#endif
#include "config/config.h"
#include "configuration.h"
#include "otel_context.h"
#ifndef _WIN32
#include "dogstatsd_client.h"
#endif
#include "distributed_tracing_headers.h"
#include "engine_hooks.h"
#include "ffe.h"
#include "handlers_internal.h"
#include "inferred_proxy_headers.h"
#include "integrations/exec_integration.h"
#include "integrations/integrations.h"
#include "ip_extraction.h"
#include <ext/logging.h>
#include "limiter/limiter.h"
#include "live_debugger.h"
#include "standalone_limiter.h"
#include "priority_sampling/priority_sampling.h"
#include "otel_context.h"
#include "random.h"
#include "autoload_php_files.h"
#include "serializer.h"
#include <ext/sidecar.h>
#include "span.h"
#include <ext/threads.h>
#include "user_request.h"
#include "weak_resources.h"
#include <ext/standard/file.h>

#include "hook/uhook.h"
#include "handlers_fiber.h"
#include "git_metadata.h"
#include "tracer_telemetry.h"
#include <ext/ffi_utils.h>

_Atomic(int64_t) ddtrace_warn_legacy_api;

// put this into startup so that other extensions running code as part of rinit do not crash
int ddtrace_startup() {
#if PHP_VERSION_ID < 80000
    zai_interceptor_startup(datadog_module);
#else
    zai_interceptor_startup();
#endif

    ddtrace_fetch_profiling_symbols();

    if (!datadog_disable) {
        // We deliberately leave handler replacement during startup, even though this uses some config
        // This touches global state, which, while unlikely, may play badly when interacting with other extensions, if done post-startup
        ddtrace_internal_handlers_startup();
    }
    return SUCCESS;
}

void ddtrace_shutdown() {
    if (datadog_disable != 1) {
        ddtrace_internal_handlers_shutdown();
    }

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
            zai_config_memoized_entries[DATADOG_CONFIG_DD_SPAN_SAMPLING_RULES].ini_entries[0]->name,
            file, modify_type, stage, 1);
        zend_string_release(file);
    }
    return altered;
}

bool ddtrace_alter_sampling_rules_file_config(zval *old_value, zval *new_value, zend_string *new_str) {
    (void) old_value;
    (void) new_str;
    if (Z_STRLEN_P(new_value) == 0) {
        return true;
    }

    return dd_save_sampling_rules_file_config(Z_STR_P(new_value), PHP_INI_USER, PHP_INI_STAGE_RUNTIME);
}

static inline void dd_alter_prop(size_t prop_offset, zval *old_value, zval *new_value) {
    UNUSED(old_value);

    ddtrace_span_properties *pspan = ddtrace_active_span_props();
    while (pspan) {
        zval *property = (zval *) (prop_offset + (char *) pspan), garbage = *property;
        ZVAL_COPY(property, new_value);
        zval_ptr_dtor(&garbage);
        pspan = pspan->parent;
    }

    ddtrace_span_stack *stack = DDTRACE_G(active_stack);
    if (stack && stack->root_span) {
        ddtrace_otel_update_attribute_values(stack->root_span);
    }
}

bool datadog_alter_dd_service(zval *old_value, zval *new_value, zend_string *new_str) {
    dd_alter_prop(XtOffsetOf(ddtrace_span_properties, property_service), old_value, new_value);
    if (DATADOG_G(request_initialized)) {
        ddtrace_sidecar_submit_span_data_direct(&DATADOG_G(sidecar), NULL, new_str, get_DD_ENV(), get_DD_VERSION());
    }
    return true;
}
bool datadog_alter_dd_env(zval *old_value, zval *new_value, zend_string *new_str) {
    dd_alter_prop(XtOffsetOf(ddtrace_span_properties, property_env), old_value, new_value);
    if (DATADOG_G(request_initialized)) {
        ddtrace_sidecar_submit_span_data_direct(&DATADOG_G(sidecar), NULL, get_DD_SERVICE(), new_str, get_DD_VERSION());
    }
    return true;
}
bool datadog_alter_dd_version(zval *old_value, zval *new_value, zend_string *new_str) {
    dd_alter_prop(XtOffsetOf(ddtrace_span_properties, property_version), old_value, new_value);
    if (DATADOG_G(request_initialized)) {
        ddtrace_sidecar_submit_span_data_direct(&DATADOG_G(sidecar), NULL, get_DD_SERVICE(), get_DD_ENV(), new_str);
    }
    return true;
}

void ddtrace_activate_once(void) {
    // must run before the first zai_hook_activate as ddtrace_telemetry_setup installs a global hook
    if (!datadog_disable) {
#ifndef _WIN32
        if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
            if (get_global_DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS() == 0) {
                // Set the default to 10 so that BGS flushes faster. With sidecar it's not needed generally.
                zai_config_change_default_ini(DATADOG_CONFIG_DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS, (zai_str) ZAI_STR_FROM_CSTR("10"));
            }
            if (get_DD_TRACE_AGENT_FLUSH_INTERVAL() == DD_TRACE_AGENT_FLUSH_INTERVAL_VAL) {
                // Set the default to 5000 so that BGS does not flush too often. The sidecar can flush more often, but the BGS is per process. Keep it higher to avoid too much load on the agent.
                zai_config_change_default_ini(DATADOG_CONFIG_DD_TRACE_AGENT_FLUSH_INTERVAL, (zai_str) ZAI_STR_FROM_CSTR("5000"));
            }
            ddtrace_coms_minit(get_global_DD_TRACE_AGENT_STACK_INITIAL_SIZE(),
                               get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE(),
                               get_global_DD_TRACE_AGENT_STACK_BACKLOG());
            zend_string *testing_token = get_global_DD_TRACE_AGENT_TEST_SESSION_TOKEN();
            if (ZSTR_LEN(testing_token)) {
                ddtrace_coms_set_test_session_token(ZSTR_VAL(testing_token), ZSTR_LEN(testing_token));
            }
        }

#endif
        if (DATADOG_G(sidecar) && get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
            bool request_startup = PG(during_request_startup);
            PG(during_request_startup) = false;
            ddtrace_telemetry_first_init();
            PG(during_request_startup) = request_startup;
        }
    }
}

void ddtrace_activate_early(void) {
    zai_hook_rinit();
    zai_interceptor_activate();
    zai_uhook_rinit();
    ddtrace_telemetry_rinit();
    zend_hash_init(&DDTRACE_G(traced_spans), 8, unused, NULL, 0);
    zend_hash_init(&DDTRACE_G(tracestate_unknown_dd_keys), 8, unused, NULL, 0);
}

void ddtrace_activate_late(void) {
    zend_string *sampling_rules_file = get_DD_SPAN_SAMPLING_RULES_FILE();
    if (ZSTR_LEN(sampling_rules_file) > 0 && !zend_string_equals(get_global_DD_SPAN_SAMPLING_RULES_FILE(), sampling_rules_file)) {
        dd_save_sampling_rules_file_config(sampling_rules_file, PHP_INI_USER, PHP_INI_STAGE_RUNTIME);
    }

    if (datadog_disable) {
        datadog_disable_tracing_in_current_request();
    }

#if PHP_VERSION_ID < 80000
    // This allows us to hook the ZEND_HANDLE_EXCEPTION pseudo opcode
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op));
    EG(exception_op)->opcode = ZEND_HANDLE_EXCEPTION;
#endif
}

void ddtrace_ginit(zend_datadog_globals *ddtrace_globals) {
#if PHP_VERSION_ID < 70100
    zai_vm_interrupt = &ddtrace_globals->zai_vm_interrupt;
#else
    UNUSED(ddtrace_globals);
#endif
    zai_hook_ginit();
}

void ddtrace_gshutdown(zend_datadog_globals *datadog_globals) {
    zai_hook_gshutdown();

    if (datadog_globals->ddtrace.agent_config_reader) {
        ddog_agent_remote_config_reader_drop(datadog_globals->ddtrace.agent_config_reader);
    }
    if (datadog_globals->sidecar) {
        // Drain any accumulated background-sender metrics before the transport goes away.
        ddtrace_telemetry_flush_bgs_metrics_final(datadog_globals);
    }
}


#if PHP_VERSION_ID < 80500
zend_string *datadog_known_strings[ZEND_STR__LAST];
void ddtrace_init_known_strings(void) {
#if PHP_VERSION_ID < 80500
#undef ZEND_STR_PARENT
    datadog_known_strings[ZEND_STR_PARENT] = zend_string_init_interned(ZEND_STRL("parent"), 1);
#endif
#if PHP_VERSION_ID < 70300
#undef ZEND_STR_NAME
    datadog_known_strings[ZEND_STR_NAME] = zend_string_init_interned(ZEND_STRL("name"), 1);
#endif
#if PHP_VERSION_ID < 70200
#undef ZEND_STR_RESOURCE
    datadog_known_strings[ZEND_STR_RESOURCE] = zend_string_init_interned(ZEND_STRL("resource"), 1);
#endif
#if PHP_VERSION_ID < 70100
    datadog_known_strings[ZEND_STR_TRACE] = zend_string_init_interned(ZEND_STRL("trace"), 1);
    datadog_known_strings[ZEND_STR_LINE] = zend_string_init_interned(ZEND_STRL("line"), 1);
    datadog_known_strings[ZEND_STR_FILE] = zend_string_init_interned(ZEND_STRL("file"), 1);
    datadog_known_strings[ZEND_STR_MESSAGE] = zend_string_init_interned(ZEND_STRL("message"), 1);
    datadog_known_strings[ZEND_STR_CODE] = zend_string_init_interned(ZEND_STRL("code"), 1);
    datadog_known_strings[ZEND_STR_TYPE] = zend_string_init_interned(ZEND_STRL("type"), 1);
    datadog_known_strings[ZEND_STR_FUNCTION] = zend_string_init_interned(ZEND_STRL("function"), 1);
    datadog_known_strings[ZEND_STR_OBJECT] = zend_string_init_interned(ZEND_STRL("object"), 1);
    datadog_known_strings[ZEND_STR_CLASS] = zend_string_init_interned(ZEND_STRL("class"), 1);
    datadog_known_strings[ZEND_STR_OBJECT_OPERATOR] = zend_string_init_interned(ZEND_STRL("->"), 1);
    datadog_known_strings[ZEND_STR_PAAMAYIM_NEKUDOTAYIM] = zend_string_init_interned(ZEND_STRL("::"), 1);
    datadog_known_strings[ZEND_STR_ARGS] = zend_string_init_interned(ZEND_STRL("args"), 1);
    datadog_known_strings[ZEND_STR_UNKNOWN] = zend_string_init_interned(ZEND_STRL("unknown"), 1);
    datadog_known_strings[ZEND_STR_EVAL] = zend_string_init_interned(ZEND_STRL("eval"), 1);
    datadog_known_strings[ZEND_STR_INCLUDE] = zend_string_init_interned(ZEND_STRL("include"), 1);
    datadog_known_strings[ZEND_STR_REQUIRE] = zend_string_init_interned(ZEND_STRL("require"), 1);
    datadog_known_strings[ZEND_STR_INCLUDE_ONCE] = zend_string_init_interned(ZEND_STRL("include_once"), 1);
    datadog_known_strings[ZEND_STR_REQUIRE_ONCE] = zend_string_init_interned(ZEND_STRL("require_once"), 1);
    datadog_known_strings[ZEND_STR_PREVIOUS] = zend_string_init_interned(ZEND_STRL("previous"), 1);
#endif
}
#endif

void ddtrace_register_functions_and_classes(int module_number);
void ddtrace_unregister_functions_and_classes(void);

void ddtrace_pre_config_minit() {
    if (datadog_active_sapi == DATADOG_PHP_SAPI_CLI) {
        datadog_config_entries[DATADOG_CONFIG_DD_TRACE_AUTO_FLUSH_ENABLED].default_encoded_value = (zai_str) ZAI_STR_FROM_CSTR("true");
    }

#ifndef _WIN32
    // PID 1 *obviously* needs to ensure the sidecar gets flushed. (As PID 1 terminating will just SIGKILL it.)
    // As to PPID 1, common scenarios where PHP is a direct child of PID 1:
    // - apache / fpm running in a container as PID 1 each
    // - PHP CLI processes running in a container as part of a bash script
    // - root processes of supervisord/systemd services - if these terminate, it's likely because the service or container shuts down.
    //   -> If the sidecar is part of a cgroup, it will terminate the sidecar as well.
    if (getpid() == 1 || getppid() == 1) {
        datadog_config_entries[DATADOG_CONFIG_DD_TRACE_FORCE_FLUSH_ON_SHUTDOWN].default_encoded_value = (zai_str) ZAI_STR_FROM_CSTR("true");
        datadog_config_entries[DATADOG_CONFIG_DD_TRACE_FORCE_FLUSH_ON_SIGTERM].default_encoded_value = (zai_str) ZAI_STR_FROM_CSTR("true");
        datadog_config_entries[DATADOG_CONFIG_DD_TRACE_FORCE_FLUSH_ON_SIGINT].default_encoded_value = (zai_str) ZAI_STR_FROM_CSTR("true");
    }

    // Background sender does not send a Content-Length header, but sidecar does. Force-enable it thus, as the background sender does not work at all.
    if (getenv("AWS_LAMBDA_FUNCTION_NAME")) {
        datadog_config_entries[DATADOG_CONFIG_DD_TRACE_SIDECAR_TRACE_SENDER].default_encoded_value = (zai_str) ZAI_STR_FROM_CSTR("true");
    }
#endif

    // Make sure it's available for appsec, before any early returns
    ddtrace_ip_extraction_startup();
}

void ddtrace_minit_early(int module_number) {
    ddog_init_span_func((void *)zend_string_release, (void *)zend_string_addref, dd_CharSlice_to_zend_string);


#if PHP_VERSION_ID < 80500
    ddtrace_init_known_strings();
#endif

    zai_hook_minit();
    zai_uhook_minit(module_number);
#if PHP_VERSION_ID >= 80000
    zai_interceptor_minit();
#endif
#if ZAI_JIT_BLACKLIST_ACTIVE
    zai_jit_minit();
#endif

    ddtrace_register_functions_and_classes(module_number);
}

void ddtrace_minit_late() {
#if PHP_VERSION_ID >= 80100
    ddtrace_setup_fiber_observers();
#endif

    atomic_init(&ddtrace_warn_legacy_api, 1);

    ddtrace_initialize_span_sampling_limiter();
    ddtrace_limiter_create();
    ddtrace_standalone_limiter_create();

#ifndef _WIN32
    /* Snapshot proxy-related env vars once at startup to avoid getenv()
     * from the background writer thread inside libcurl. */
    ddtrace_coms_minit_proxy_env();
    ddtrace_dogstatsd_client_minit();
#endif

    ddtrace_autoload_minit();

    ddtrace_engine_hooks_minit();
    ddtrace_init_proxy_info_map();

    ddtrace_integrations_minit();
    ddtrace_serializer_startup();

    ddtrace_live_debugger_minit();
    ddtrace_trace_source_minit();
}

void ddtrace_mshutdown() {
    zai_uhook_mshutdown();
    zai_hook_mshutdown();

    ddtrace_unregister_functions_and_classes();

    if (datadog_disable == 1) {
        return;
    }

    if (DDTRACE_G(agent_rate_by_service)) {
        zai_json_release_persistent_array(DDTRACE_G(agent_rate_by_service));
        DDTRACE_G(agent_rate_by_service) = NULL;
    }

    ddtrace_integrations_mshutdown();

#ifndef _WIN32
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_mshutdown();
        if (ddtrace_coms_flush_shutdown_writer_synchronous()) {
            ddtrace_coms_curl_shutdown();
        }
        /* All writer threads and curl handles are gone at this point, so
         * it is safe to free the cached proxy env strings for ASan. */
        ddtrace_coms_mshutdown_proxy_env();
    } else /* ! part of the if outside the ifdef */
#endif
    if (get_global_DD_TRACE_FORCE_FLUSH_ON_SHUTDOWN() && DATADOG_G(sidecar)) {
        ddog_sidecar_flush(&DATADOG_G(sidecar), (ddog_SidecarFlushOptions){.traces_and_stats = true, .telemetry = true});
    }

    ddtrace_engine_hooks_mshutdown();
    ddtrace_shutdown_proxy_info_map();

    ddtrace_shutdown_span_sampling_limiter();
    ddtrace_limiter_destroy();
    ddtrace_standalone_limiter_destroy();

    ddtrace_user_req_shutdown();
}

bool dd_rinit_once_done = false;

void ddtrace_first_rinit(void) {
    /* The env vars are memoized on MINIT before the SAPI env vars are available.
     * We use the first RINIT to bust the env var cache and use the SAPI env vars.
     * TODO Audit/remove config usages before RINIT and move config init to RINIT.
     */


    // Uses config, cannot run earlier
#ifndef _WIN32
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_init_and_start_writer();
    }
#endif

    dd_rinit_once_done = true;
}

static void dd_initialize_request(void) {
    DDTRACE_G(distributed_trace_id) = (datadog_trace_id){0};
    DDTRACE_G(distributed_parent_trace_id) = 0;
    DDTRACE_G(additional_global_tags) = zend_new_array(0);
    DDTRACE_G(default_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    DDTRACE_G(propagated_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNSET;
    DDTRACE_G(inferred_span_created) = false;
    zend_hash_init(&DDTRACE_G(root_span_tags_preset), 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&DDTRACE_G(propagated_root_span_tags), 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&DDTRACE_G(tracestate_unknown_dd_keys), 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&DDTRACE_G(baggage), 8, unused, ZVAL_PTR_DTOR, 0);

    ddtrace_asm_event_rinit();

    if (!DDTRACE_G(agent_config_reader) && !get_global_DD_TRACE_IGNORE_AGENT_SAMPLING_RATES()) {
        if (get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
            if (datadog_endpoint) {
                DDTRACE_G(agent_config_reader) = ddog_agent_remote_config_reader_for_endpoint(datadog_endpoint);
            }
#ifndef _WIN32
        } else if (ddtrace_coms_agent_config_handle) {
            ddog_agent_remote_config_reader_for_anon_shm(ddtrace_coms_agent_config_handle, &DDTRACE_G(agent_config_reader));
#endif
        }
    }

    ddtrace_internal_handlers_rinit();

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

    if (get_DD_TRACE_GENERATE_ROOT_SPAN()) {
        ddtrace_push_root_span();
    }
}

void ddtrace_rinit_early(void) {
#if PHP_VERSION_ID < 80000 || (PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500)
    zai_interceptor_rinit();
#endif

    ddtrace_weak_resources_rinit();
    ddtrace_live_debugger_rinit();

    if (!datadog_disable) {
        DDTRACE_G(active_stack) = NULL; // This should not be necessary, but somehow sometimes it may be a leftover from a previous request.

        // With internal functions also being hookable, they must not be hooked before the CG(map_ptr_base) is zeroed
        zai_hook_activate();
#if PHP_VERSION_ID < 80000
        ddtrace_autoload_rinit();
#endif
    }
}

void ddtrace_rinit(void) {
    if (!DDTRACE_G(agent_config_reader) && !get_global_DD_TRACE_IGNORE_AGENT_SAMPLING_RATES()) {
        if (get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
            if (datadog_endpoint) {
                DDTRACE_G(agent_config_reader) = ddog_agent_remote_config_reader_for_endpoint(datadog_endpoint);
            }
#if !defined(_WIN32) && defined(DDTRACE)
        } else if (ddtrace_coms_agent_config_handle) {
            ddog_agent_remote_config_reader_for_anon_shm(ddtrace_coms_agent_config_handle, &DDTRACE_G(agent_config_reader));
#endif
        }
    }

    if (!datadog_disable) {
        DDTRACE_G(active_stack) = ddtrace_init_root_span_stack();
    }

    if (get_DD_TRACE_ENABLED()) {
        dd_initialize_request();
    }
}

static void dd_clean_globals(void) {
    zend_array_destroy(DDTRACE_G(additional_global_tags));
    zend_hash_destroy(&DDTRACE_G(root_span_tags_preset));
    zend_hash_destroy(&DDTRACE_G(tracestate_unknown_dd_keys));
    zend_hash_destroy(&DDTRACE_G(propagated_root_span_tags));
    zend_hash_destroy(&DDTRACE_G(baggage));

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

void dd_force_shutdown_tracing(bool fast_shutdown) {
    DDTRACE_G(in_shutdown) = true;

    zend_try {
        ddtrace_close_all_open_spans(true);  // All remaining userland spans (and root span)
    } zend_catch {
        LOG(WARN, "Failed to close remaining spans due to bailout");
    } zend_end_try();

    zend_try {
        if (ddtrace_flush_tracer(false, true, fast_shutdown) == FAILURE) {
            LOG(WARN, "Unable to flush the tracer");
        }
    } zend_catch {
        LOG(WARN, "Unable to flush the tracer due to bailout");
    } zend_end_try();

    // we here need to disable the tracer, so that further hooks do not trigger
    datadog_disable_tracing_in_current_request();  // implicitly calling dd_clean_globals

    // The hooks shall not be reset, just disabled at runtime.
    zai_hook_clean(fast_shutdown);

    DDTRACE_G(in_shutdown) = false;
}

static void dd_shutdown_hooks(bool fast_shutdown) {
    zai_hook_clean(fast_shutdown);
}

void ddtrace_rshutdown(bool fast_shutdown) {
    zend_hash_destroy(&DDTRACE_G(traced_spans));

    // this needs to be done before dropping the spans
    // run unconditionally because ddtrace may've been disabled mid-request
    ddtrace_exec_handlers_rshutdown();

    if (get_DD_TRACE_ENABLED()) {
        dd_force_shutdown_tracing(fast_shutdown);
    }

    if (!datadog_disable) {
        dd_shutdown_hooks(fast_shutdown);

        ddtrace_autoload_rshutdown();

        if (!fast_shutdown) {
            OBJ_RELEASE(&DDTRACE_G(active_stack)->std);
        }
        DDTRACE_G(active_stack) = NULL;
    }

    ddtrace_ffe_flush_exposures();
    ddtrace_ffe_flush_evaluation_metrics();

    ddtrace_clean_git_object();
    ddtrace_weak_resources_rshutdown();
    ddtrace_live_debugger_rshutdown();
}

void ddtrace_post_deactivate(void) {
    ddtrace_telemetry_rshutdown();

    zai_interceptor_deactivate();

    // we can only actually free our hooks hashtables in post_deactivate, as within RSHUTDOWN some user code may still run
    zai_hook_rshutdown();
    zai_uhook_rshutdown();
}

void datadog_disable_tracing_in_current_request(void) {
    // PHP 8 has ZSTR_CHAR('0') which is nicer...
    zend_string *zero = zend_string_init("0", 1, 0);
    zend_alter_ini_entry(zai_config_memoized_entries[DATADOG_CONFIG_DD_TRACE_ENABLED].ini_entries[0]->name, zero,
                         ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
    zend_string_release(zero);
}

bool datadog_alter_dd_trace_disabled_config(zval *old_value, zval *new_value, zend_string *new_str) {
    (void)new_str;

    if (Z_TYPE_P(old_value) == Z_TYPE_P(new_value)) {
        return true;
    }

    if (datadog_disable) {
        return Z_TYPE_P(new_value) == IS_FALSE;  // no changing to enabled allowed if globally disabled
    }

    if (!DDTRACE_G(active_stack)) {
        return true; // We must not do anything early in RINIT before the necessary structures are initialized at all
    }

    if (Z_TYPE_P(old_value) == IS_FALSE) {
        dd_initialize_request();
    } else if (!datadog_disable) {  // if this is true, the request has not been initialized at all
        ddtrace_close_all_open_spans(false);  // All remaining userland spans (and root span)
        dd_clean_globals();
    }

    return true;
}

bool ddtrace_update_remote_config_flags(ddog_RemoteConfigFlags *flags) {
    flags->ffe_enabled = get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED();
    flags->live_debugging_enabled = get_global_DD_DYNAMIC_INSTRUMENTATION_ENABLED();
    return get_global_DD_TRACE_SIDECAR_TRACE_SENDER()
        || get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED()
        || get_global_DD_METRICS_OTEL_ENABLED();
}

#if defined(__SANITIZE_ADDRESS__) && !defined(_WIN32)
#define JOIN_BGS_BEFORE_FORK 1
#endif

void ddtrace_internal_handle_prefork() {
#if JOIN_BGS_BEFORE_FORK
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_flush_shutdown_writer_synchronous();
    }
#endif
}

void ddtrace_internal_handle_postfork() {
#if JOIN_BGS_BEFORE_FORK
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_restart_writer();
    }
#endif
}

void ddtrace_internal_handle_fork() {
    if (DATADOG_G(sidecar)) {
        // Unconditionally send, even if root span is NULL
        ddtrace_span_data *root = DDTRACE_G(active_stack) && DDTRACE_G(active_stack)->root_span ? &DDTRACE_G(active_stack)->root_span->span : NULL;
        ddtrace_sidecar_submit_span_data_direct_defaults(&DATADOG_G(sidecar), root);
    }

#ifndef _WIN32
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        if (DDTRACE_G(agent_config_reader)) {
            ddog_agent_remote_config_reader_drop(DDTRACE_G(agent_config_reader));
            DDTRACE_G(agent_config_reader) = NULL;
        }

        ddtrace_coms_curl_shutdown();
        ddtrace_coms_clean_background_sender_after_fork();
    }
#endif

    ddtrace_seed_prng();
    if (!get_DD_TRACE_FORKED_PROCESS()) {
        datadog_disable_tracing_in_current_request();
    }
    if (get_DD_TRACE_ENABLED()) {
        if (get_DD_DISTRIBUTED_TRACING()) {
            DDTRACE_G(distributed_parent_trace_id) = ddtrace_peek_span_id();
            DDTRACE_G(distributed_trace_id) = ddtrace_peek_trace_id();
        } else {
            DDTRACE_G(distributed_parent_trace_id) = 0;
            DDTRACE_G(distributed_trace_id) = (datadog_trace_id){ 0 };
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
            ddog_agent_remote_config_reader_for_anon_shm(ddtrace_coms_agent_config_handle, &DDTRACE_G(agent_config_reader));
        }
    }
#endif
}

// the following operations are performed in order to put the tracer in a state when a new trace can be started:
//   - set a new trace (group) id
void dd_prepare_for_new_trace(void) {
#ifndef _WIN32
    DDTRACE_G(traces_group_id) = ddtrace_coms_next_group_id();
#endif
}
