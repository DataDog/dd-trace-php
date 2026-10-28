// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.
#include "body_parser.h"
#include "compatibility.h"
#include "ddappsec.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_compat.h"
#include "php_objects.h"
#include <alloc.h>

struct _parser_alloc_context {
    bool bailed_out;
    bool limit_exceeded; // preflight failure; PHP has no deferred OOM queued
};

static void _log_result(const char *nonnull kind, ddog_AppsecBodyStatus status);
static uint8_t *nullable _parser_allocate(void *nonnull context, size_t size);
static void _parser_free(uint8_t *nonnull ptr);
static bool _emalloc_fits(size_t size);
#ifdef TESTING
static THREAD_LOCAL_ON_ZTS zend_long _parser_fail_after = -1;
static THREAD_LOCAL_ON_ZTS zend_long _parser_allocation_calls;
static THREAD_LOCAL_ON_ZTS bool _parser_fail_fatal;
static void _register_testing_objects(void);
#endif

void dd_body_parser_startup(void)
{
#ifdef TESTING
    if (get_global_DD_APPSEC_TESTING()) {
        _register_testing_objects();
    }
#endif
}

ddog_AppsecBodyMessagePack dd_parse_json_truncated(
    const char *nonnull json_data, size_t json_len, int max_depth)
{
    struct _parser_alloc_context context = {0};
    ddog_AppsecBodyAllocator allocator = {.context = &context,
        .allocate = _parser_allocate,
        .free = _parser_free};
    ddog_AppsecBodyMessagePack body =
        dd_trace_parse_json_body(json_data, json_len, max_depth, allocator);
    if (context.bailed_out) {
        // Propagate the fatal after Rust has released its parser state.
        zend_bailout();
    }
    if (context.limit_exceeded) {
        zend_error_noreturn(
            E_ERROR, "AppSec body parser exceeded the memory limit");
    }
    _log_result("JSON", body.status);
    return body;
}

ddog_AppsecBodyMessagePack dd_parse_xml_truncated(
    const char *nonnull xml_data, size_t xml_len, int max_depth)
{
    struct _parser_alloc_context context = {0};
    ddog_AppsecBodyAllocator allocator = {.context = &context,
        .allocate = _parser_allocate,
        .free = _parser_free};
    ddog_AppsecBodyMessagePack body =
        dd_trace_parse_xml_body(xml_data, xml_len, max_depth, allocator);
    if (context.bailed_out) {
        // Propagate the fatal after Rust has released its parser state.
        zend_bailout();
    }
    if (context.limit_exceeded) {
        zend_error_noreturn(
            E_ERROR, "AppSec body parser exceeded the memory limit");
    }
    _log_result("XML", body.status);
    return body;
}

#ifdef TESTING
void dd_body_msgpack_to_zval(
    ddog_AppsecBodyMessagePack body, zval *nonnull result)
{
    ZVAL_NULL(result);
    if (body.status != DDOG_APPSEC_BODY_STATUS_COMPLETE &&
        body.status != DDOG_APPSEC_BODY_STATUS_PARTIAL) {
        dd_trace_drop_body_msgpack(body);
        return;
    }

    // Only the PHP testing entrypoints decode to zvals.
    dd_mpack_to_zval((const char *)body.ptr, body.len, result);
    dd_trace_drop_body_msgpack(body);
}
#endif

bool dd_body_msgpack_write(mpack_writer_t *nonnull writer,
    const ddog_AppsecBodyMessagePack *nonnull body)
{
    if (!dd_body_msgpack_has_value(*body)) {
        return false;
    }

    mpack_write_object_bytes(writer, (const char *)body->ptr, body->len);
    return mpack_writer_error(writer) == mpack_ok;
}

static void _log_result(const char *nonnull kind, ddog_AppsecBodyStatus status)
{
    switch (status) {
    case DDOG_APPSEC_BODY_STATUS_EMPTY:
        mlog_g(dd_log_debug, "Error parsing %s (no data)", kind);
        break;
    case DDOG_APPSEC_BODY_STATUS_PARTIAL:
        mlog_g(dd_log_debug, "Error parsing %s (with partial data)", kind);
        break;
    case DDOG_APPSEC_BODY_STATUS_COMPLETE:
        mlog_g(dd_log_debug, "Successfully parsed %s", kind);
        break;
    case DDOG_APPSEC_BODY_STATUS_OUT_OF_MEMORY:
    case DDOG_APPSEC_BODY_STATUS_INTERNAL_ERROR:
        // Do not allocate a log message while returning a pending soft OOM.
        break;
    }
}

static uint8_t *nullable _parser_allocate(void *nonnull context, size_t size)
{
    struct _parser_alloc_context *ctx = context;
    if (ctx->bailed_out) {
        return NULL;
    }
#ifdef TESTING
    volatile bool fail = false;
    if (UNEXPECTED(get_global_DD_APPSEC_TESTING())) {
        zend_long call = _parser_allocation_calls++;
        fail = _parser_fail_after >= 0 && call >= _parser_fail_after;
        if (fail && !_parser_fail_fatal) {
            return NULL;
        }
    }
#endif
    if (!zai_resolve__femalloc() && !_emalloc_fits(size)) {
        ctx->limit_exceeded = true;
        return NULL;
    }
    uint8_t *volatile result = NULL;
    // Without femalloc, the check above prevents emalloc from exceeding the
    // memory limit. Still, as a precaution, catch any unexpected bailout so
    // Rust can clean up before we propagate it. Native femalloc returns NULL
    // and defers the OOM error.
    zend_try
    {
#ifdef TESTING
        if (fail) {
            zend_error_noreturn(E_ERROR, "Injected parser allocation failure");
        }
#endif
        result = zai_femalloc(size);
    }
    zend_catch { ctx->bailed_out = true; }
    zend_end_try();
    return result;
}

static void _parser_free(uint8_t *nonnull ptr) { efree(ptr); }

// Conservative memory-limit check for parser allocations on PHP without
// fallible allocation APIs. Does not predict underlying OS allocation failure.
static bool _emalloc_fits(size_t size)
{
    // Account for alignment and debug-build metadata before deciding which
    // Zend allocation path the request takes. Reject arithmetic overflow.
    if (size > SIZE_MAX - (ZEND_MM_ALIGNMENT - 1) - ZEND_MM_OVERHEAD) {
        return false;
    }
    size = ZEND_MM_ALIGNED_SIZE(MAX(size, 1)) + ZEND_MM_OVERHEAD;

    // Small/large runs may need a whole new chunk even for a tiny request.
    size_t growth = ZEND_MM_CHUNK_SIZE;
    if (size > ZEND_MM_MAX_LARGE_SIZE) {
        // Huge runs have a separate mapping and a small tracking allocation.
        // Round to chunks to cover page rounding (and Windows' chunk
        // rounding), then reserve a chunk for the tracking allocation.
        if (size > SIZE_MAX - (ZEND_MM_CHUNK_SIZE - 1) - ZEND_MM_CHUNK_SIZE) {
            return false;
        }
        growth = ZEND_MM_ALIGNED_SIZE_EX(size, ZEND_MM_CHUNK_SIZE) +
                 ZEND_MM_CHUNK_SIZE;
    }

    if (PG(memory_limit) < 0) {
        return true;
    }
    size_t limit = (size_t)PG(memory_limit);
    size_t used = zend_memory_usage(true);
    // No PHP callbacks or allocations may intervene between this check and
    // emalloc. The request heap is local to this thread. Refusing potentially
    // reusable space is deliberate: its fragmentation is not exposed here.
    return used <= limit && growth <= limit - used;
}

#ifdef TESTING
static PHP_FUNCTION(datadog_appsec_testing_body_parser_has_femalloc)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    RETURN_BOOL(zai_resolve__femalloc() != NULL);
}

// Configure persistent failure after N successful parser allocations. The
// fatal variant exercises unexpected bailout after a successful preflight.
// Return the previous call count to visit every allocation point.
static PHP_FUNCTION(datadog_appsec_testing_body_parser_fail_after)
{
    zend_long after = -1;
    zend_bool fatal = false;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|lb", &after, &fatal) !=
        SUCCESS) {
        RETURN_NULL();
    }
    zend_long calls = _parser_allocation_calls;
    _parser_allocation_calls = 0;
    _parser_fail_after = after;
    _parser_fail_fatal = fatal;
    RETURN_LONG(calls);
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_body_parser_fail_after, 0, 0, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, after, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, fatal, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "body_parser_has_femalloc", PHP_FN(datadog_appsec_testing_body_parser_has_femalloc), void_ret_bool_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "body_parser_fail_after", PHP_FN(datadog_appsec_testing_body_parser_fail_after), arginfo_body_parser_fail_after, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void) { dd_phpobj_reg_funcs(functions); }
#endif
