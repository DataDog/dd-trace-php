#include "spandata.h"

#include "ddtrace.h"
#include "span.h"

zend_class_entry *ddtrace_spandata_ce;

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

extern inline ddtrace_spandata *ddtrace_spandata_from_obj(zend_object *object);
extern inline ddtrace_spandata *ddtrace_spandata_from_zval(zval *zv);

bool ddtrace_spandata_is_top(zval *obj) {
    ddtrace_spandata *span = ddtrace_spandata_from_zval(obj);
    return DDTRACE_G(open_spans_top) == span->backptr;
}

/* {{{ PHP objects have a two-part destruction: dtor and free.
 * You can run PHP code in dtor but not free.
 * The dtor is not always called.
 */
static void _ddtrace_spandata_dtor_obj(zend_object *object) { zend_objects_destroy_object(object); }
static void _ddtrace_spandata_free_obj(zend_object *object) { zend_object_std_dtor(object); }
/* }}} */

/* PHP does not expose the individual std_object_handlers until PHP 7.3, so we
 * cannot make this a const object; instead initialize it at registration.
 */
static zend_object_handlers ddtrace_spandata_handlers;

/* Note that creating a spandata object through this API is incomplete; the
 * backptr will not be assigned.
 */
zend_object *ddtrace_spandata_create_obj(zend_class_entry *ce) {
    ddtrace_spandata *spandata = ecalloc(1, sizeof(ddtrace_spandata) + zend_object_properties_size(ce));
    zend_object *object = &spandata->std;
    zend_object_std_init(object, ce);
    object_properties_init(object, ce);
    object->handlers = &ddtrace_spandata_handlers;
    return object;
}

void ddtrace_spandata_register_ce(void) {
    /* Initialize the handlers at registration time since PHP does not expose
     * the individual handlers by name until 7.3, and accessing
     * std_object_handlers members is not a compile-time constant: {{{ */
    memcpy(&ddtrace_spandata_handlers, &std_object_handlers, sizeof ddtrace_spandata_handlers);
    ddtrace_spandata_handlers.offset = XtOffsetOf(ddtrace_spandata, std);
    ddtrace_spandata_handlers.free_obj = _ddtrace_spandata_free_obj;
    ddtrace_spandata_handlers.dtor_obj = _ddtrace_spandata_dtor_obj;

    // Delete the clone handler which in effect forbids cloning the obj
    ddtrace_spandata_handlers.clone_obj = NULL;
    /* }}} */

    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "DDTrace", "SpanData", NULL);
    ddtrace_spandata_ce = zend_register_internal_class(&ce);

    /* trace_id, span_id, parent_id, start & duration are stored directly on
     * ddtrace_span_t so we don't need to make them properties on DDTrace\SpanData
     */
    zend_declare_property_null(ddtrace_spandata_ce, "name", sizeof("name") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "resource", sizeof("resource") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "service", sizeof("service") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "type", sizeof("type") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "meta", sizeof("meta") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_spandata_ce, "metrics", sizeof("metrics") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);

    ddtrace_spandata_ce->create_object = ddtrace_spandata_create_obj;
}
