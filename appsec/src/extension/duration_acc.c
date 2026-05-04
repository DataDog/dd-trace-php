
#include "duration_acc.h"

#include "ddappsec.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "request_lifecycle.h"
#include "string_helpers.h"

#define DD_METRIC_RASP_DURATION "_dd.appsec.rasp.duration_ext"

static zend_string *_rasp_key;
static THREAD_LOCAL_ON_ZTS double _rasp_us;
static THREAD_LOCAL_ON_ZTS double _waf_ext_us;

static double _elapsed_us(const struct timespec *start)
{
    struct timespec end;
    (void)clock_gettime(CLOCK_MONOTONIC_RAW, &end);
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    double sec_us = (double)(end.tv_sec - start->tv_sec) * 1e6;
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    double nsec_us = (double)(end.tv_nsec - start->tv_nsec) / 1e3;
    return sec_us + nsec_us;
}

static void _flush_rasp(void)
{
    if (_rasp_us <= 0.0) {
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
    ZVAL_DOUBLE(&zv, _rasp_us);
    if (zend_hash_add(Z_ARRVAL_P(metrics_zv), _rasp_key, &zv) == NULL) {
        mlog(dd_log_warning, "Failed to add metric %.*s",
            (int)ZSTR_LEN(_rasp_key), ZSTR_VAL(_rasp_key));
    }

    _rasp_us = 0.0;
}

void dd_duration_startup(void)
{
    _rasp_key = zend_string_init_interned(LSTRARG(DD_METRIC_RASP_DURATION), 1);
}

void dd_duration_shutdown(void)
{
    zend_string_release(_rasp_key);
    _rasp_key = NULL;
}

void dd_duration_reset_globals(void)
{
    _rasp_us = 0.0;
    _waf_ext_us = 0.0;
}

void dd_duration_req_finish(void)
{
    _flush_rasp();
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
