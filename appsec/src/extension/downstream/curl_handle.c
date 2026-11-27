// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "curl_handle.h"

#include <errno.h>
#include <string.h>

#include <zend_exceptions.h>

#include "../configuration.h"
#include "../logging.h"
#include "../php_objects.h"
#include "curl_handle_validate.h"
#include "curl_linkage.h"

// ext/curl's handle type: zend_class_entry of CurlHandle (PHP 8), or the
// "curl" resource type id (PHP 7). Both set at startup, after ext/curl's
// MINIT. We look them up ourselves rather than reuse the tracer's: the
// tracer's curl_ce is only filled when the tracer is not disabled, and on PHP
// 7 it learns the resource type from curl_init()'s first return value.
#if PHP_VERSION_ID >= 80000
static zend_class_entry *_curl_ce;
#else
static int _le_curl;
#endif

// whether ext/curl's handle type was found at startup
static bool _type_found;

// The functions below write the reason of a skip to `reason`, and log it.
// (unless log is false)
static dd_curl_handle_status _get(zval *zv, dd_curl_easy **out,
    void **nullable php_curl_out, dd_curl_probe_fn probe, bool log,
    char *nonnull reason, size_t reason_size);
static dd_curl_handle_status _check_handlers_offset(
    long offset, bool log, char *nonnull reason, size_t reason_size);
static dd_curl_handle_status _check_cp(uintptr_t cp, dd_curl_probe_fn probe,
    bool log, dd_curl_easy **nonnull out, char *nonnull reason,
    size_t reason_size);
static void _log_skip(const char *nonnull reason);

#ifdef TESTING
static void _register_testing_objects(void);
static dd_curl_probe_fn _testing_probe(void);
#endif

void dd_curl_handle_minit(void)
{
#ifdef TESTING
    _register_testing_objects();
#endif
}

void dd_curl_handle_startup(void)
{
    if (!dd_curl_linkage_enabled()) {
        return;
    }
#if PHP_VERSION_ID >= 80000
    _curl_ce = zend_hash_str_find_ptr(CG(class_table), ZEND_STRL("curlhandle"));
    _type_found = _curl_ce != NULL;
#else
    // 0 if not found; never a valid type id (resource types start at 1)
    _le_curl = zend_fetch_list_dtor_id("curl");
    _type_found = _le_curl != 0;
#endif
    if (!_type_found) {
        mlog_no_php(dd_log_warning,
            "Downstream request analysis for ext/curl is off: the curl "
            "handle type was not found");
    }
}

bool dd_curl_downstream_enabled(void)
{
    return dd_curl_linkage_enabled() && _type_found;
}

bool dd_curl_handle_is_curl(zval *zv)
{
    if (!_type_found) {
        return false;
    }
    ZVAL_DEREF(zv);
#if PHP_VERSION_ID >= 80000
    return Z_TYPE_P(zv) == IS_OBJECT &&
           instanceof_function(Z_OBJCE_P(zv), _curl_ce);
#else
    return Z_TYPE_P(zv) == IS_RESOURCE && Z_RES_TYPE_P(zv) == _le_curl &&
           Z_RES_VAL_P(zv);
#endif
}

dd_curl_handle_status dd_curl_handle_get(
    zval *nonnull zv, dd_curl_easy * nullable * nonnull out)
{
    return dd_curl_handle_get_ex(zv, out, NULL, true);
}

dd_curl_handle_status dd_curl_handle_get_ex(zval *nonnull zv,
    dd_curl_easy * nullable * nonnull out, void *nullable *nullable php_curl,
    bool log)
{
    *out = NULL;
    if (php_curl) {
        *php_curl = NULL;
    }
    if (!dd_curl_downstream_enabled()) {
        return DD_CURL_HANDLE_DISABLED;
    }
    char reason[DD_CURL_LINKAGE_REASON_SIZE];
#ifdef TESTING
    dd_curl_probe_fn probe = _testing_probe();
#else
    dd_curl_probe_fn probe = dd_curl_probe;
#endif
    return _get(zv, out, php_curl, probe, log, reason, sizeof(reason));
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static dd_curl_handle_status _get(zval *zv, dd_curl_easy **out,
    void **nullable php_curl_out, dd_curl_probe_fn probe, bool log,
    char *nonnull reason, size_t reason_size)
{
    ZVAL_DEREF(zv);

    const char *php_curl;
#if PHP_VERSION_ID >= 80000
    // CurlHandle is final, so the layout is php_curl's; std is php_curl's
    // last member, and ext/curl sets handlers->offset = XtOffsetOf(php_curl,
    // std), which is how ext/curl itself (curl_from_obj) finds the php_curl.
    if (!dd_curl_handle_is_curl(zv)) {
        return DD_CURL_HANDLE_NOT_CURL;
    }
    zend_object *obj = Z_OBJ_P(zv);
    long offset = (long)obj->handlers->offset;
    dd_curl_handle_status st =
        _check_handlers_offset(offset, log, reason, reason_size);
    if (st != DD_CURL_HANDLE_OK) {
        return st;
    }
    php_curl = (const char *)obj - offset;
#else
    // closed resources have type -1 and a NULL ptr
    if (!dd_curl_handle_is_curl(zv)) {
        return DD_CURL_HANDLE_NOT_CURL;
    }
    php_curl = (const char *)Z_RES_VAL_P(zv);
#endif

    // php_curl's first member (in every PHP release 7.0.0 to 8.5.x) is
    // `CURL *cp`. This reads ext/curl's memory, not libcurl's.
    uintptr_t cp;
    memcpy(&cp, php_curl, sizeof(cp));
    dd_curl_handle_status cp_st =
        _check_cp(cp, probe, log, out, reason, reason_size);
    if (cp_st == DD_CURL_HANDLE_OK && php_curl_out) {
        *php_curl_out = (void *)php_curl;
    }
    return cp_st;
}

static dd_curl_handle_status _check_handlers_offset(
    long offset, bool log, char *nonnull reason, size_t reason_size)
{
    const char *why = dd_curl_validate_handlers_offset(offset);
    if (why) {
        (void)snprintf(reason, reason_size, "%s", why);
        if (log) {
            _log_skip(reason);
        }
        return DD_CURL_HANDLE_INVALID;
    }
    return DD_CURL_HANDLE_OK;
}

static dd_curl_handle_status _check_cp(uintptr_t cp, dd_curl_probe_fn probe,
    bool log, dd_curl_easy **nonnull out, char *nonnull reason,
    size_t reason_size)
{
    if (cp == 0) {
        return DD_CURL_HANDLE_NULL_CP;
    }
    int probe_errno = 0;
    switch (dd_curl_validate_easy_ptr(cp, probe, dd_curl_linkage_version_num(),
        reason, reason_size, &probe_errno)) {
    case DD_CURL_EASY_VALID:
        *out = (dd_curl_easy *)cp;
        return DD_CURL_HANDLE_OK;
    case DD_CURL_EASY_UNVERIFIED: {
        // nothing is known to be wrong: this transfer is not observed, the
        // next ones are checked again
        enum { ERRBUF_SIZE = 64 };
        char errbuf[ERRBUF_SIZE];
        size_t len = strlen(reason);
        (void)snprintf(reason + len, reason_size - len, " %s",
            _strerror_r(probe_errno, errbuf, sizeof(errbuf)));
        if (log) {
            _log_skip(reason);
        }
        return DD_CURL_HANDLE_UNVERIFIED;
    }
    case DD_CURL_EASY_INVALID:
    default:
        if (log) {
            _log_skip(reason);
        }
        return DD_CURL_HANDLE_INVALID;
    }
}

// not through PHP: it would be a warning in the app (error handlers,
// error_get_last(), display_errors), possibly from inside libcurl
static void _log_skip(const char *nonnull reason)
{
    mlog_no_php(dd_log_debug, "Not observing a curl transfer: %s", reason);
}

#ifdef TESTING
static const char *_status_name(dd_curl_handle_status st)
{
    switch (st) {
    case DD_CURL_HANDLE_OK:
        return "ok";
    case DD_CURL_HANDLE_NOT_CURL:
        return "not_curl";
    case DD_CURL_HANDLE_NULL_CP:
        return "null_cp";
    case DD_CURL_HANDLE_DISABLED:
        return "disabled";
    case DD_CURL_HANDLE_INVALID:
        return "invalid";
    case DD_CURL_HANDLE_UNVERIFIED:
        return "unverified";
    }
    return "unknown";
}

static void _status_array(zval *nonnull return_value, dd_curl_handle_status st,
    const char *nonnull reason)
{
    array_init(return_value);
    add_assoc_string(return_value, "status", (char *)_status_name(st));
    add_assoc_string(return_value, "reason", (char *)reason);
    add_assoc_bool(return_value, "validated", st == DD_CURL_HANDLE_OK);
}

// curl_handle_info(mixed $handle): array{status, reason, validated}
static PHP_FUNCTION(datadog_appsec_testing_curl_handle_info)
{
    zval *zv;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &zv) == FAILURE) {
        RETURN_FALSE;
    }
    dd_curl_easy *cp;
    char reason[DD_CURL_LINKAGE_REASON_SIZE] = "";
    dd_curl_handle_status st = DD_CURL_HANDLE_DISABLED;
    if (dd_curl_downstream_enabled()) {
        st = _get(zv, &cp, NULL, dd_curl_probe, true, reason, sizeof(reason));
    }
    _status_array(return_value, st, reason);
}

// a probe that is refused (as on old kernels or under seccomp)
static int _probe_refused(uintptr_t addr, void *buf, size_t len)
{
    (void)addr, (void)buf, (void)len;
    return EPERM;
}

// a probe that reads garbage: the memory is readable but is no libcurl easy
// handle
static int _probe_garbage(uintptr_t addr, void *buf, size_t len)
{
    (void)addr;
    enum { GARBAGE_BYTE = 0xA5 };
    memset(buf, GARBAGE_BYTE, len);
    return 0;
}

// what the next dd_curl_handle_get() does (one call only)
static THREAD_LOCAL_ON_ZTS int
    _force_next; // 0: nothing, 1: refused, 2: garbage

static dd_curl_probe_fn _testing_probe(void)
{
    int mode = _force_next;
    _force_next = 0;
    switch (mode) {
    case 1:
        return _probe_refused;
    case 2:
        return _probe_garbage;
    default:
        return dd_curl_probe;
    }
}

// curl_handle_force_next(string $kind): void
// The next dd_curl_handle_get() (that is, the next observed transfer) sees a
// refused probe ("unverified") or a handle that is not a libcurl easy handle
// ("invalid"), as if its CURL * were bad. Only that one call.
static PHP_FUNCTION(datadog_appsec_testing_curl_handle_force_next)
{
    char *kind;
    size_t kind_len;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &kind, &kind_len) ==
        FAILURE) {
        RETURN_FALSE;
    }
    if (strcmp(kind, "unverified") == 0) {
        _force_next = 1;
    } else if (strcmp(kind, "invalid") == 0) {
        _force_next = 2;
    } else {
        _force_next = 0;
    }
}

// curl_handle_inject(int $cp, int $offset = 8, bool $probe_available = true):
// array{status, reason, validated}. Goes through the same path as
// curl_handle_info(), as if a PHP 8 handle had these values.
static PHP_FUNCTION(datadog_appsec_testing_curl_handle_inject)
{
    zend_long cp;
    zend_long offset = (zend_long)sizeof(void *);
    zend_bool probe_available = 1;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l|lb", &cp, &offset,
            &probe_available) == FAILURE) {
        RETURN_FALSE;
    }
    dd_curl_handle_status st;
    dd_curl_easy *out = NULL;
    char reason[DD_CURL_LINKAGE_REASON_SIZE] = "";
    if (!dd_curl_downstream_enabled()) {
        st = DD_CURL_HANDLE_DISABLED;
    } else {
        st = _check_handlers_offset((long)offset, true, reason, sizeof(reason));
        if (st == DD_CURL_HANDLE_OK) {
            st = _check_cp((uintptr_t)cp,
                probe_available ? dd_curl_probe : _probe_refused, true, &out,
                reason, sizeof(reason));
        }
    }
    _status_array(return_value, st, reason);
}

// curl_handle_validate(int $cp, int $offset = 8, int $libcurl_version_num =
//     -1): ?string
// The validators alone: null if valid, else the reason. $libcurl_version_num
// = -1 means the version of the detected libcurl.
static PHP_FUNCTION(datadog_appsec_testing_curl_handle_validate)
{
    zend_long cp;
    zend_long offset = (zend_long)sizeof(void *);
    zend_long version_num = -1;
    if (zend_parse_parameters(
            ZEND_NUM_ARGS(), "l|ll", &cp, &offset, &version_num) == FAILURE) {
        RETURN_FALSE;
    }
    const char *offset_reason = dd_curl_validate_handlers_offset((long)offset);
    if (offset_reason) {
        RETURN_STRING(offset_reason);
    }
    if (cp == 0) {
        RETURN_STRING("NULL CURL handle pointer");
    }
    if (version_num < 0) {
        version_num = (zend_long)dd_curl_linkage_version_num();
    }
    char reason[DD_CURL_LINKAGE_REASON_SIZE];
    int probe_errno = 0;
    if (dd_curl_validate_easy_ptr((uintptr_t)cp, dd_curl_probe,
            (unsigned int)version_num, reason, sizeof(reason),
            &probe_errno) != DD_CURL_EASY_VALID) {
        RETURN_STRING(reason);
    }
    RETURN_NULL();
}

// curl_string_buffer_address(string $s): int
// Address of the string's buffer: readable, 8-byte aligned memory that is not
// a libcurl handle (unless the string starts with the magic number).
static PHP_FUNCTION(datadog_appsec_testing_curl_string_buffer_address)
{
    zend_string *s;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &s) == FAILURE) {
        RETURN_FALSE;
    }
    RETURN_LONG((zend_long)(uintptr_t)ZSTR_VAL(s));
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    handle_force_next_arginfo, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, kind, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(handle_info_arginfo, 0, 1, IS_ARRAY, 0)
ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    handle_inject_arginfo, 0, 1, IS_ARRAY, 0)
ZEND_ARG_TYPE_INFO(0, cp, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, offset, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, probe_available, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    handle_validate_arginfo, 0, 1, IS_STRING, 1)
ZEND_ARG_TYPE_INFO(0, cp, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, offset, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, libcurl_version_num, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    string_address_arginfo, 0, 1, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, s, IS_STRING, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_handle_info", PHP_FN(datadog_appsec_testing_curl_handle_info), handle_info_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_handle_force_next", PHP_FN(datadog_appsec_testing_curl_handle_force_next), handle_force_next_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_handle_inject", PHP_FN(datadog_appsec_testing_curl_handle_inject), handle_inject_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_handle_validate", PHP_FN(datadog_appsec_testing_curl_handle_validate), handle_validate_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_string_buffer_address", PHP_FN(datadog_appsec_testing_curl_string_buffer_address), string_address_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void)
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }
    dd_phpobj_reg_funcs(testing_functions);
}
#endif
