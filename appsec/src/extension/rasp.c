
#include "rasp.h"

#include "ddappsec.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "request_lifecycle.h"
#include "string_helpers.h"

#define DD_EVENTS_RASP_DURATION_EXT "_dd.appsec.rasp.duration_ext"

static zend_string *_dd_rasp_duration_ext;
static THREAD_LOCAL_ON_ZTS double _duration_ext_us;

static void _add_rasp_duration_ext_to_metrics(
    zend_object *nonnull span, double duration);

void dd_rasp_startup(void)
{
    _dd_rasp_duration_ext =
        zend_string_init_interned(LSTRARG(DD_EVENTS_RASP_DURATION_EXT), 1);
}
void dd_rasp_shutdown(void)
{
    zend_string_release(_dd_rasp_duration_ext);
    _dd_rasp_duration_ext = NULL;
}
void dd_rasp_reset_globals(void) { _duration_ext_us = 0.0; }
void dd_rasp_req_finish(void)
{
    if (_duration_ext_us <= 0.0) {
        return;
    }
    zend_object *span = dd_req_lifecycle_get_cur_span();
    if (!span) {
        return;
    }
    _add_rasp_duration_ext_to_metrics(span, _duration_ext_us);
    _duration_ext_us = 0.0;
}

void dd_rasp_account_duration_us(double duration_us)
{
    _duration_ext_us += duration_us;
}

static void _add_rasp_duration_ext_to_metrics(
    zend_object *nonnull span, double duration)
{
    zval *metrics_zv = dd_trace_span_get_metrics(span);
    if (!metrics_zv) {
        return;
    }

    zval zv;
    ZVAL_DOUBLE(&zv, duration);
    zval *nullable res =
        zend_hash_add(Z_ARRVAL_P(metrics_zv), _dd_rasp_duration_ext, &zv);
    if (res == NULL) {
        mlog(dd_log_warning, "Failed to add metric %.*s",
            (int)ZSTR_LEN(_dd_rasp_duration_ext),
            ZSTR_VAL(_dd_rasp_duration_ext));
    }
}
