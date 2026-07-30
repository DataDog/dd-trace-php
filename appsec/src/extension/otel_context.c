#include "otel_context.h"

#include <components-rs/datadog.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <string.h>

#ifdef __linux__
#    include <dlfcn.h>
#    include <unistd.h>
#endif

#include "php_helpers.h"

typedef struct {
    zend_string *service;
    zend_string *environment;
    zend_string *version;
    zend_string *runtime_id;
    uint64_t generation;
#ifdef __linux__
    pid_t pid;
    void *process_handle;
    datadog_OtelProcessContextView process;
    void **thread_slot;
#endif
} dd_appsec_otel_context_cache;

typedef struct {
    const char *ptr;
    size_t len;
} string_view;

static THREAD_LOCAL_ON_ZTS dd_appsec_otel_context_cache _cache;

#ifdef __linux__
typedef void *(*process_context_read_fn)(datadog_OtelProcessContextView *view);
typedef void (*process_context_drop_fn)(void *handle);

static process_context_read_fn _process_context_read;
static process_context_drop_fn _process_context_drop;

typedef struct {
    uint8_t trace_id[16];
    uint8_t span_id[8];
    uint8_t valid;
    uint8_t trace_flags;
    uint16_t attrs_data_size;
    uint8_t attrs_data[612];
} otel_thread_context_record;

_Static_assert(offsetof(otel_thread_context_record, valid) == 24,
    "OTel Thread Context valid byte must remain at offset 24");
_Static_assert(offsetof(otel_thread_context_record, trace_flags) == 25,
    "OTel Thread Context trace flags must remain at offset 25");
_Static_assert(offsetof(otel_thread_context_record, attrs_data_size) == 26,
    "OTel Thread Context attribute size must remain at offset 26");
_Static_assert(offsetof(otel_thread_context_record, attrs_data) == 28,
    "OTel Thread Context attributes must remain at offset 28");
_Static_assert(sizeof(otel_thread_context_record) == 640,
    "OTel Thread Context v1 record must remain 640 bytes");

typedef struct {
    string_view service;
    string_view environment;
    string_view version;
    bool unknown_index;
} thread_values;

static bool _valid_utf8(const uint8_t *value, size_t len)
{
    size_t offset = 0;
    while (offset < len) {
        uint8_t first = value[offset++];
        if (first < 0x80) {
            continue;
        }

        size_t continuation_count;
        uint32_t codepoint;
        if ((first & 0xe0) == 0xc0) {
            continuation_count = 1;
            codepoint = first & 0x1f;
        } else if ((first & 0xf0) == 0xe0) {
            continuation_count = 2;
            codepoint = first & 0x0f;
        } else if ((first & 0xf8) == 0xf0) {
            continuation_count = 3;
            codepoint = first & 0x07;
        } else {
            return false;
        }
        if (continuation_count > len - offset) {
            return false;
        }
        for (size_t index = 0; index < continuation_count; ++index) {
            uint8_t continuation = value[offset++];
            if ((continuation & 0xc0) != 0x80) {
                return false;
            }
            codepoint = (codepoint << 6) | (continuation & 0x3f);
        }

        static const uint32_t minimum[] = {0, 0x80, 0x800, 0x10000};
        if (codepoint < minimum[continuation_count] || codepoint > 0x10ffff ||
            (codepoint >= 0xd800 && codepoint <= 0xdfff)) {
            return false;
        }
    }
    return true;
}

static void _drop_process_context(void)
{
    if (_cache.process_handle && _process_context_drop) {
        _process_context_drop(_cache.process_handle);
    }
    _cache.process_handle = NULL;
    memset(&_cache.process, 0, sizeof(_cache.process));
    _cache.process.thread_service_name_index =
        DATADOG_OTEL_THREAD_ATTRIBUTE_INDEX_ABSENT;
    _cache.process.thread_service_version_index =
        DATADOG_OTEL_THREAD_ATTRIBUTE_INDEX_ABSENT;
    _cache.process.thread_deployment_environment_name_index =
        DATADOG_OTEL_THREAD_ATTRIBUTE_INDEX_ABSENT;
}

static bool _refresh_process_context(void)
{
    if (!_process_context_read) {
        return false;
    }

    datadog_OtelProcessContextView process = {0};
    void *handle = _process_context_read(&process);
    if (!handle) {
        return false;
    }

    _drop_process_context();
    _cache.process_handle = handle;
    _cache.process = process;
    return true;
}

static void _initialize_process_context(void)
{
    pid_t pid = getpid();
    if (_cache.pid == pid) {
        return;
    }

    _drop_process_context();
    _cache.pid = pid;
    _cache.thread_slot = NULL;
    (void)_refresh_process_context();
}

static string_view _char_slice(ddog_CharSlice value)
{
    return (string_view){.ptr = value.ptr, .len = value.len};
}

static thread_values _read_thread_values(void)
{
    thread_values values = {0};
    if (!_cache.thread_slot) {
        _cache.thread_slot = dlsym(RTLD_DEFAULT, "otel_thread_ctx_v1");
    }
    if (!_cache.thread_slot || !*_cache.thread_slot) {
        return values;
    }

    const otel_thread_context_record *record = *_cache.thread_slot;
    if (record->valid != 1 ||
        record->attrs_data_size > sizeof(record->attrs_data)) {
        return values;
    }

    size_t offset = 0;
    while (offset + 2 <= record->attrs_data_size) {
        uint8_t key = record->attrs_data[offset++];
        uint8_t len = record->attrs_data[offset++];
        if (len > record->attrs_data_size - offset) {
            break;
        }

        if (key >= _cache.process.thread_attribute_key_count) {
            values.unknown_index = true;
            offset += len;
            continue;
        }

        uint16_t key16 = key;
        string_view *destination = NULL;
        if (key16 == _cache.process.thread_service_name_index) {
            destination = &values.service;
        } else if (key16 == _cache.process.thread_service_version_index) {
            destination = &values.version;
        } else if (key16 ==
                   _cache.process.thread_deployment_environment_name_index) {
            destination = &values.environment;
        }

        const uint8_t *value = &record->attrs_data[offset];
        if (destination && len > 0 && _valid_utf8(value, len)) {
            *destination =
                (string_view){.ptr = (const char *)value, .len = len};
        }
        offset += len;
    }
    return values;
}
#endif

static string_view _zend_string_view(zend_string *value)
{
    return value ? (string_view){.ptr = ZSTR_VAL(value), .len = ZSTR_LEN(value)}
                 : (string_view){0};
}

static bool _same_zend_string(zend_string *current, string_view next)
{
    if (!current) {
        return next.ptr == NULL;
    }
    if (!next.ptr || ZSTR_LEN(current) != next.len) {
        return false;
    }
    return next.len == 0 || memcmp(ZSTR_VAL(current), next.ptr, next.len) == 0;
}

static bool _replace_zend_string(zend_string **current, string_view next)
{
    if (_same_zend_string(*current, next)) {
        return false;
    }
    if (*current) {
        zend_string_release_ex(*current, true);
    }
    *current = next.ptr ? zend_string_init(next.ptr, next.len, true) : NULL;
    return true;
}

void dd_appsec_otel_context_startup(void)
{
#ifdef __linux__
    process_context_read_fn read = (process_context_read_fn)(uintptr_t)dlsym(
        RTLD_DEFAULT, "datadog_otel_process_context_read");
    process_context_drop_fn drop = (process_context_drop_fn)(uintptr_t)dlsym(
        RTLD_DEFAULT, "datadog_otel_process_context_drop");
    if (read && drop) {
        _process_context_read = read;
        _process_context_drop = drop;
    }
#endif
}

void dd_appsec_otel_context_gshutdown(void)
{
#ifdef __linux__
    _drop_process_context();
#endif
    zend_string **values[] = {
        &_cache.service,
        &_cache.environment,
        &_cache.version,
        &_cache.runtime_id,
    };
    for (size_t index = 0; index < sizeof(values) / sizeof(values[0]);
         ++index) {
        if (*values[index]) {
            zend_string_release_ex(*values[index], true);
            *values[index] = NULL;
        }
    }
    memset(&_cache, 0, sizeof(_cache));
}

void dd_appsec_otel_context_refresh(zend_string *legacy_service,
    zend_string *legacy_environment, zend_string *legacy_version,
    zend_string *legacy_runtime_id)
{
    string_view service = _zend_string_view(legacy_service);
    string_view environment = _zend_string_view(legacy_environment);
    string_view version = _zend_string_view(legacy_version);
    string_view runtime_id = _zend_string_view(legacy_runtime_id);

#ifdef __linux__
    _initialize_process_context();

    string_view process_service = _char_slice(_cache.process.service_name);
    string_view process_environment =
        _char_slice(_cache.process.deployment_environment_name);
    string_view process_version = _char_slice(_cache.process.service_version);
    string_view process_runtime_id =
        _char_slice(_cache.process.service_instance_id);
    if (process_service.len > 0) {
        service = process_service;
    }
    if (process_environment.len > 0) {
        environment = process_environment;
    }
    if (process_version.len > 0) {
        version = process_version;
    }
    if (process_runtime_id.len > 0) {
        runtime_id = process_runtime_id;
    }

    thread_values thread = _read_thread_values();
    if (thread.unknown_index) {
        if (_refresh_process_context()) {
            thread = _read_thread_values();
        }
    }
    if (thread.service.len > 0) {
        service = thread.service;
    }
    if (thread.environment.len > 0) {
        environment = thread.environment;
    }
    if (thread.version.len > 0) {
        version = thread.version;
    }
#endif

    bool connection_identity_changed = false;
    connection_identity_changed |=
        _replace_zend_string(&_cache.service, service);
    connection_identity_changed |=
        _replace_zend_string(&_cache.environment, environment);
    // The helper protocol does not carry service.version, so changing only
    // version must not force a connection restart.
    (void)_replace_zend_string(&_cache.version, version);
    connection_identity_changed |=
        _replace_zend_string(&_cache.runtime_id, runtime_id);
    if (connection_identity_changed || _cache.generation == 0) {
        ++_cache.generation;
    }
}

zend_string *dd_appsec_otel_context_service(void) { return _cache.service; }

zend_string *dd_appsec_otel_context_environment(void)
{
    return _cache.environment;
}

zend_string *dd_appsec_otel_context_version(void) { return _cache.version; }

zend_string *dd_appsec_otel_context_runtime_id(void)
{
    return _cache.runtime_id;
}

uint64_t dd_appsec_otel_context_generation(void) { return _cache.generation; }
