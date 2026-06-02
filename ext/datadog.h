#ifndef DATADOG_H
#define DATADOG_H

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include <php.h>
#include <stdbool.h>
#include <stdint.h>
#include <components-rs/common.h>
#include <components/sapi/sapi.h>

#include "version.h"
#include "compatibility.h"
#include "git.h"
#include "threads.h"

extern int datadog_disable;
extern zend_module_entry datadog_module_entry;
extern zend_module_entry *datadog_module; // pointer to actually used copy by PHP

extern datadog_php_sapi datadog_active_sapi;

extern ddog_CharSlice php_version_rt;

#if defined(COMPILE_DL_DDTRACE) && defined(__GLIBC__) && __GLIBC_MINOR__
#define CXA_THREAD_ATEXIT_WRAPPER 1
#endif

void datadog_internal_handle_fork(void);
#ifdef CXA_THREAD_ATEXIT_WRAPPER
void dd_run_rust_thread_destructors(void *unused);
#endif

typedef struct {
    uint64_t low;
    union {
        uint64_t high;
        struct {
            ZEND_ENDIAN_LOHI(
                uint32_t padding // zeroes
            ,
                uint32_t time
            )
        };
    };
} datadog_trace_id;

#include <tracer/ddtrace_globals.h>

// clang-format off
ZEND_BEGIN_MODULE_GLOBALS(datadog)
#if PHP_VERSION_ID < 70100
    bool zai_vm_interrupt;
#endif
    bool reread_remote_configuration;

    ddog_SidecarTransport *sidecar;
    ddog_QueueId sidecar_queue_id;
    MUTEX_T sidecar_universal_service_tags_mutex;
    bool remote_config_writing; // true while RC WRITE mode INI update is in progress
    ddog_RemoteConfigState *remote_config_state;
    ddog_AgentInfoReader *agent_info_reader;
    zend_string *last_service_name;
    zend_string *last_env_name;
    zend_string *last_version;
    ddog_Vec_Tag active_global_tags;

    bool request_initialized;
    ddog_SidecarActionsBuffer *telemetry_buffer;
    ddog_SidecarActionsBuffer *metrics_buffer;
    void *ffe_metric_buffer;
    size_t ffe_metric_buffer_len;
    size_t ffe_metric_buffer_cap;

    bool asm_event_emitted;

    HashTable git_metadata;
    zend_string *git_commit;
    zend_string *git_repository_url;
    bool git_resolved;

    ddog_ShmCacheMap *telemetry_cache;
    HashTable otel_config_telemetry;

    char *cgroup_file;
    zend_bool backtrace_handler_already_run;

#if DDTRACE
    ddtrace_globals ddtrace;
#endif
ZEND_END_MODULE_GLOBALS(datadog)
// clang-format on

#ifdef ZTS
#  define DATADOG_G(v) ZEND_TSRMG(datadog_globals_id, zend_datadog_globals *, v)
#  define DATADOG_GLOBALS_PTR() TSRMG_BULK_STATIC(datadog_globals_id, zend_datadog_globals *)
#else
#  define DATADOG_G(v) (datadog_globals.v)
#  define DATADOG_GLOBALS_PTR() (&datadog_globals)
#endif

#define PHP_DDTRACE_EXTNAME "ddtrace"
#ifndef PHP_DDTRACE_VERSION
#define PHP_DDTRACE_VERSION "0.0.0-unknown"
#endif

#endif // DATADOG_H
