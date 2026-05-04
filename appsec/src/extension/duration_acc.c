
#include "duration_acc.h"

#include "ddappsec.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "string_helpers.h"
#include "telemetry.h"

#define DD_METRIC_RASP_DURATION_EXT "_dd.appsec.rasp.duration_ext"
#define DD_METRIC_WAF_DURATION_EXT "_dd.appsec.waf.duration_ext"

static zend_string *_rasp_ext_key;
static zend_string *_waf_ext_key;
static THREAD_LOCAL_ON_ZTS double _rasp_ext_ns;
static THREAD_LOCAL_ON_ZTS double _waf_ext_ns;

static double _elapsed_ns(const struct timespec *start)
{
    struct timespec end;
    (void)clock_gettime(CLOCK_MONOTONIC_RAW, &end);
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    double sec_ns = (double)(end.tv_sec - start->tv_sec) * 1e9;
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    double nsec_ns = (double)(end.tv_nsec - start->tv_nsec);
    return sec_ns + nsec_ns;
}

void dd_duration_flush_metrics(zend_object *nonnull span)
{
    zval *metrics_zv = dd_trace_span_get_metrics(span);
    if (!metrics_zv) {
        return;
    }

    zval zv;

    dd_telemetry_submit_duration_ext(
        // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
        _waf_ext_ns / 1e3, _rasp_ext_ns / 1e3);

    if (_waf_ext_ns > 0.0) {
        // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
        ZVAL_DOUBLE(&zv, _waf_ext_ns / 1e3);
        if (zend_hash_add(Z_ARRVAL_P(metrics_zv), _waf_ext_key, &zv) == NULL) {
            mlog(dd_log_warning, "Failed to add metric %.*s",
                (int)ZSTR_LEN(_waf_ext_key), ZSTR_VAL(_waf_ext_key));
        }
        _waf_ext_ns = 0.0;
    }

    if (_rasp_ext_ns > 0.0) {
        // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
        ZVAL_DOUBLE(&zv, _rasp_ext_ns / 1e3);
        if (zend_hash_add(Z_ARRVAL_P(metrics_zv), _rasp_ext_key, &zv) == NULL) {
            mlog(dd_log_warning, "Failed to add metric %.*s",
                (int)ZSTR_LEN(_rasp_ext_key), ZSTR_VAL(_rasp_ext_key));
        }
        _rasp_ext_ns = 0.0;
    }
}

void dd_duration_startup(void)
{
    _rasp_ext_key =
        zend_string_init_interned(LSTRARG(DD_METRIC_RASP_DURATION_EXT), 1);
    _waf_ext_key =
        zend_string_init_interned(LSTRARG(DD_METRIC_WAF_DURATION_EXT), 1);
}

void dd_duration_shutdown(void)
{
    zend_string_release(_rasp_ext_key);
    _rasp_ext_key = NULL;
    zend_string_release(_waf_ext_key);
    _waf_ext_key = NULL;
}

void dd_duration_reset_globals(void)
{
    _rasp_ext_ns = 0.0;
    _waf_ext_ns = 0.0;
}

void dd_duration_rasp_ext_account(const struct timespec *start)
{
    _rasp_ext_ns += _elapsed_ns(start);
}

void dd_duration_waf_ext_account(const struct timespec *start)
{
    _waf_ext_ns += _elapsed_ns(start);
}
