
#include "duration_acc.h"

#include "ddappsec.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "request_lifecycle.h"
#include "string_helpers.h"

#define DD_METRIC_RASP_DURATION "_dd.appsec.rasp.duration_ext"
#define DD_METRIC_WAF_DURATION_EXT "_dd.appsec.waf.duration_ext"

static zend_string *_rasp_key;
static zend_string *_waf_ext_key;
static THREAD_LOCAL_ON_ZTS double _rasp_us;
static THREAD_LOCAL_ON_ZTS double _waf_ext_us;

static double _elapsed_us(const struct timespec *start)
{
    struct timespec end;
    (void)clock_gettime(CLOCK_MONOTONIC_RAW, &end);
    return (double)(end.tv_sec - start->tv_sec) * 1e6 +
           (double)(end.tv_nsec - start->tv_nsec) / 1e3;
}

static void _flush(double *nonnull acc, zend_string *nonnull key, double scale)
{
    if (*acc <= 0.0) {
        return;
    }
    zend_object *span = dd_req_lifecycle_get_cur_span();
    if (!span) {
        return;
    }

    zval *metrics_zv = dd_trace_span_get_metrics(span);
    if (!metrics_zv) {
        return;
    }

    zval zv;
    ZVAL_DOUBLE(&zv, *acc * scale);
    if (zend_hash_add(Z_ARRVAL_P(metrics_zv), key, &zv) == NULL) {
        mlog(dd_log_warning, "Failed to add metric %.*s",
            (int)ZSTR_LEN(key), ZSTR_VAL(key));
    }

    *acc = 0.0;
}

void dd_duration_startup(void)
{
    _rasp_key = zend_string_init_interned(LSTRARG(DD_METRIC_RASP_DURATION), 1);
    _waf_ext_key =
        zend_string_init_interned(LSTRARG(DD_METRIC_WAF_DURATION_EXT), 1);
}

void dd_duration_shutdown(void)
{
    zend_string_release(_rasp_key);
    _rasp_key = NULL;
    zend_string_release(_waf_ext_key);
    _waf_ext_key = NULL;
}

void dd_duration_reset_globals(void)
{
    _rasp_us = 0.0;
    _waf_ext_us = 0.0;
}

void dd_duration_req_finish(void)
{
    _flush(&_rasp_us, _rasp_key, 1.0);
    _flush(&_waf_ext_us, _waf_ext_key, 1.0 / 1000.0);
}

void dd_duration_rasp_ext_account(const struct timespec *start)
{
    _rasp_us += _elapsed_us(start);
}

void dd_duration_waf_ext_account(const struct timespec *start)
{
    _waf_ext_us += _elapsed_us(start);
}

double dd_duration_waf_ext_get_us(void) { return _waf_ext_us; }

double dd_duration_rasp_ext_get_us(void) { return _rasp_us; }
