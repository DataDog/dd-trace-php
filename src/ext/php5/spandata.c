#include "spandata.h"

#include <php.h>

zend_class_entry *ddtrace_spandata_ce;

void ddtrace_spandata_register_ce(TSRMLS_D) {
    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "DDTrace", "SpanData", NULL);
    ddtrace_spandata_ce = zend_register_internal_class(&ce TSRMLS_CC);

    /* trace_id, span_id, parent_id, start & duration are stored directly on
     * ddtrace_span_t so we don't need to make them properties on DDTrace\SpanData
     */
    zend_declare_property_null(ddtrace_spandata_ce, "name", sizeof("name") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "resource", sizeof("resource") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "service", sizeof("service") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "type", sizeof("type") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "meta", sizeof("meta") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "metrics", sizeof("metrics") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
}
