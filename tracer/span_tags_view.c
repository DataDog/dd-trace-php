#include "span_tags_view.h"

#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <ext/spl/spl_array.h>
#if PHP_VERSION_ID < 70200
#include <ext/spl/spl_iterators.h>
#define zend_ce_countable spl_ce_Countable
#endif

#include "ddtrace.h"
#include "serializer.h"
#include <ext/string_utils.h>

zend_class_entry *ddtrace_ce_span_tags_view;
static zend_object_handlers dd_span_tags_view_handlers;

typedef struct {
    ddtrace_span_data *span;  // weak; NULL once the span is freed
    bool metrics;
    zend_object std;
} dd_span_tags_view;

#if PHP_VERSION_ID < 80000
#define DD_OBJ_ARG zval *object
#define DD_OBJ Z_OBJ_P(object)
#define DD_MEMBER_ARG zval *member
#define DD_MEMBER (Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC())
#else
#define DD_OBJ_ARG zend_object *object
#define DD_OBJ object
#define DD_MEMBER_ARG zend_string *member
#define DD_MEMBER member
#endif

static inline dd_span_tags_view *dd_tags_view_from_obj(zend_object *obj) {
    return (dd_span_tags_view *)((char *)obj - XtOffsetOf(dd_span_tags_view, std));
}

// 0 for $meta, 1 for $metrics, -1 for any other property.
static inline int dd_tags_bucket_of(zend_string *name) {
    if (zend_string_equals_literal(name, "meta")) {
        return 0;
    }
    if (zend_string_equals_literal(name, "metrics")) {
        return 1;
    }
    return -1;
}

// Numbers are metrics; any other non-null value is meta.
static inline bool dd_tags_in_bucket(zval *val, bool metrics) {
    ZVAL_DEREF(val);
    if (Z_TYPE_P(val) == IS_LONG || Z_TYPE_P(val) == IS_DOUBLE) {
        return metrics;
    }
    return !metrics && Z_TYPE_P(val) > IS_NULL;
}

static inline zend_array *dd_tags_attrs_read(ddtrace_span_data *span) {
    if (!span) {
        return NULL;
    }
    zval *attrs = &span->property_attributes;
    ZVAL_DEREF(attrs);
    return Z_TYPE_P(attrs) == IS_ARRAY ? Z_ARR_P(attrs) : NULL;
}

// Legacy $meta keys aliasing a span property.
enum dd_tags_special {
    DD_TAGS_PLAIN,
    DD_TAGS_SERVICE,
    DD_TAGS_RESOURCE,
    DD_TAGS_NAME,
    DD_TAGS_TYPE,
    DD_TAGS_ENV,
    DD_TAGS_VERSION,
    DD_TAGS_COMPONENT,
    DD_TAGS_SPAN_KIND,
    DD_TAGS_IGNORE_ERROR,
};

static enum dd_tags_special dd_tags_special_of(zend_string *key) {
    switch (ZSTR_LEN(key)) {
        case 3: return zend_string_equals_literal(key, "env") ? DD_TAGS_ENV : DD_TAGS_PLAIN;
        case 7: return zend_string_equals_literal(key, "version") ? DD_TAGS_VERSION : DD_TAGS_PLAIN;
        case 9:
            if (zend_string_equals_literal(key, "component")) {
                return DD_TAGS_COMPONENT;
            }
            if (zend_string_equals_literal(key, "span.type")) {
                return DD_TAGS_TYPE;
            }
            return zend_string_equals_literal(key, "span.kind") ? DD_TAGS_SPAN_KIND : DD_TAGS_PLAIN;
        case 12: return zend_string_equals_literal(key, "service.name") ? DD_TAGS_SERVICE : DD_TAGS_PLAIN;
        case 13:
            if (zend_string_equals_literal(key, "resource.name")) {
                return DD_TAGS_RESOURCE;
            }
            return zend_string_equals_literal(key, "error.ignored") ? DD_TAGS_IGNORE_ERROR : DD_TAGS_PLAIN;
        case 14: return zend_string_equals_literal(key, "operation.name") ? DD_TAGS_NAME : DD_TAGS_PLAIN;
        default: return DD_TAGS_PLAIN;
    }
}

static zval *dd_tags_special_prop(ddtrace_span_data *span, enum dd_tags_special special) {
    zval *prop;
    switch (special) {
        case DD_TAGS_SERVICE: prop = &span->property_service; break;
        case DD_TAGS_RESOURCE: prop = &span->property_resource; break;
        case DD_TAGS_NAME: prop = &span->property_name; break;
        case DD_TAGS_TYPE: prop = &span->property_type; break;
        case DD_TAGS_ENV: prop = &span->property_env; break;
        case DD_TAGS_VERSION: prop = &span->property_version; break;
        case DD_TAGS_COMPONENT: prop = &span->property_component; break;
        case DD_TAGS_SPAN_KIND: prop = &span->property_span_kind; break;
        default: prop = &span->property_ignore_error; break;
    }
    ZVAL_DEREF(prop);
    return prop;
}

static zend_long dd_tags_span_kind_of(zval *value) {
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) == IS_STRING) {
        for (zend_long kind = DDTRACE_SPAN_KIND_INTERNAL; kind <= DDTRACE_SPAN_KIND_CONSUMER; ++kind) {
            if (zend_string_equals_cstr(Z_STR_P(value), ddtrace_span_kind_str(kind), strlen(ddtrace_span_kind_str(kind)))) {
                return kind;
            }
        }
    }
    return DDTRACE_SPAN_KIND_UNSPECIFIED;
}

// Sets the property aliased by `special`; false for a non-canonical span.kind, which is stored as an attribute.
static bool dd_tags_special_write(ddtrace_span_data *span, enum dd_tags_special special, zval *value) {
    zval *prop = dd_tags_special_prop(span, special);
    if (special == DD_TAGS_SPAN_KIND) {
        // The latest write wins: a canonical kind replaces a non-canonical span.kind attribute and vice versa.
        zend_long kind = dd_tags_span_kind_of(value);
        zval_ptr_dtor(prop);
        ZVAL_LONG(prop, kind);
        if (!kind) {
            return false;
        }
        zend_hash_str_del(ddtrace_property_array(&span->property_attributes), ZEND_STRL("span.kind"));
    } else if (special == DD_TAGS_IGNORE_ERROR) {
        // Deprecated: can only turn $ignoreError on.
        if (zend_is_true(value)) {
            zval_ptr_dtor(prop);
            ZVAL_TRUE(prop);
        }
    } else {
        ZVAL_DEREF(value);
        zend_string *str = special == DD_TAGS_NAME && Z_TYPE_P(value) == IS_STRING
            ? zend_string_tolower(Z_STR_P(value)) : datadog_convert_to_str(value);
        zval_ptr_dtor(prop);
        ZVAL_STR(prop, str);
    }
    return true;
}

// The $meta read of the property aliased by `special`; false while it holds its empty default.
static bool dd_tags_special_read(ddtrace_span_data *span, enum dd_tags_special special, zval *rv) {
    zval *prop = dd_tags_special_prop(span, special);
    if (special == DD_TAGS_SPAN_KIND) {
        const char *kind = ddtrace_span_kind_str(zval_get_long(prop));
        if (kind) {
            ZVAL_STRING(rv, kind);
        }
        return kind != NULL;
    }
    if (special == DD_TAGS_IGNORE_ERROR) {
        if (zend_is_true(prop)) {
            ZVAL_STRINGL(rv, "true", 4);
            return true;
        }
        return false;
    }
    if (Z_TYPE_P(prop) == IS_STRING && Z_STRLEN_P(prop)) {
        ZVAL_COPY(rv, prop);
        return true;
    }
    return false;
}

static void dd_tags_special_reset(ddtrace_span_data *span, enum dd_tags_special special) {
    zval *prop = dd_tags_special_prop(span, special);
    zval_ptr_dtor(prop);
    if (special == DD_TAGS_SPAN_KIND) {
        ZVAL_LONG(prop, DDTRACE_SPAN_KIND_UNSPECIFIED);
    } else if (special == DD_TAGS_IGNORE_ERROR) {
        ZVAL_FALSE(prop);
    } else {
        ZVAL_EMPTY_STRING(prop);
    }
}

void ddtrace_span_write_meta(ddtrace_span_data *span, zend_string *key, zval *value, bool metric) {
    enum dd_tags_special special = dd_tags_special_of(key);
    if (special != DD_TAGS_PLAIN && dd_tags_special_write(span, special, value)) {
        return;
    }
    zval normalized;
    ddtrace_normalize_tag_value(&normalized, value, metric);
    zend_symtable_update(ddtrace_property_array(&span->property_attributes), key, &normalized);
}

static const char *const dd_tags_special_keys[] = {
    [DD_TAGS_SERVICE] = "service.name",
    [DD_TAGS_RESOURCE] = "resource.name",
    [DD_TAGS_NAME] = "operation.name",
    [DD_TAGS_TYPE] = "span.type",
    [DD_TAGS_ENV] = "env",
    [DD_TAGS_VERSION] = "version",
    [DD_TAGS_COMPONENT] = "component",
    [DD_TAGS_SPAN_KIND] = "span.kind",
    [DD_TAGS_IGNORE_ERROR] = "error.ignored",
};

static void dd_tags_snapshot(ddtrace_span_data *span, bool metrics, zval *rv) {
    array_init(rv);
    zend_array *attrs = dd_tags_attrs_read(span);
    if (!attrs) {
        return;
    }
    zend_ulong h;
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_KEY_VAL_IND(attrs, h, key, val) {
        if (dd_tags_in_bucket(val, metrics)) {
            ZVAL_DEREF(val);
            Z_TRY_ADDREF_P(val);
            if (key) {
                zend_hash_add_new(Z_ARR_P(rv), key, val);
            } else {
                zend_hash_index_add_new(Z_ARR_P(rv), h, val);
            }
        }
    } ZEND_HASH_FOREACH_END();
    if (!metrics) {
        // $meta also lists the aliased properties, which win over same-named attributes.
        for (enum dd_tags_special special = DD_TAGS_SERVICE; special <= DD_TAGS_IGNORE_ERROR; ++special) {
            zval prop;
            if (dd_tags_special_read(span, special, &prop)) {
                zend_hash_str_update(Z_ARR_P(rv), dd_tags_special_keys[special], strlen(dd_tags_special_keys[special]), &prop);
            }
        }
    }
}

static zend_long dd_tags_count(ddtrace_span_data *span, bool metrics) {
    zend_long count = 0;
    zend_array *attrs = dd_tags_attrs_read(span);
    if (!attrs) {
        return 0;
    }
    zval *val;
    ZEND_HASH_FOREACH_VAL_IND(attrs, val) {
        count += dd_tags_in_bucket(val, metrics);
    } ZEND_HASH_FOREACH_END();
    if (!metrics) {
        for (enum dd_tags_special special = DD_TAGS_SERVICE; special <= DD_TAGS_IGNORE_ERROR; ++special) {
            zval prop;
            if (dd_tags_special_read(span, special, &prop)) {
                zval *dup = zend_hash_str_find(attrs, dd_tags_special_keys[special], strlen(dd_tags_special_keys[special]));
                count += !dup || !dd_tags_in_bucket(dup, false);
                zval_ptr_dtor(&prop);
            }
        }
    }
    return count;
}

// Whether `value` is what a $meta read of the legacy `key` returns: the property, else a same-named attribute.
static bool dd_tags_special_unchanged(ddtrace_span_data *span, enum dd_tags_special special, zend_string *key, zval *value) {
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) != IS_STRING) {
        return false;
    }
    zval current;
    if (dd_tags_special_read(span, special, &current)) {
        bool unchanged = zend_string_equals(Z_STR(current), Z_STR_P(value));
        zval_ptr_dtor(&current);
        return unchanged;
    }
    zval *attr = zend_hash_find(ddtrace_property_array(&span->property_attributes), key);
    if (!attr) {
        return false;
    }
    ZVAL_DEREF(attr);
    return Z_TYPE_P(attr) == IS_STRING && zend_string_equals(Z_STR_P(attr), Z_STR_P(value));
}

// Replaces the bucket's entries of $attributes with `values` (normalized), or just clears it.
// Keys kept by `values` are updated in place so that they keep their position. Unchanged legacy keys are
// skipped, so that `$span->meta += [...]` neither rewrites (e.g. lowercases) nor promotes the aliased values.
static void dd_tags_replace_bucket(ddtrace_span_data *span, bool metrics, zend_array *values) {
    zend_array *attrs = ddtrace_property_array(&span->property_attributes);
    zend_ulong h;
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_KEY_VAL_IND(attrs, h, key, val) {
        if (dd_tags_in_bucket(val, metrics)
            && !(values && (key ? zend_hash_exists(values, key) : zend_hash_index_exists(values, h)))) {
            if (key) {
                zend_hash_del(attrs, key);
            } else {
                zend_hash_index_del(attrs, h);
            }
        }
    } ZEND_HASH_FOREACH_END();

    if (values) {
        ZEND_HASH_FOREACH_KEY_VAL_IND(values, h, key, val) {
            if (key) {
                enum dd_tags_special special = metrics ? DD_TAGS_PLAIN : dd_tags_special_of(key);
                if (special == DD_TAGS_PLAIN || !dd_tags_special_unchanged(span, special, key, val)) {
                    ddtrace_span_write_meta(span, key, val, metrics);
                }
            } else {
                zval normalized;
                ddtrace_normalize_tag_value(&normalized, val, metrics);
                zend_hash_index_update(attrs, h, &normalized);
            }
        } ZEND_HASH_FOREACH_END();
    }
}

static zval *dd_tags_find(dd_span_tags_view *view, zval *offset, zend_string **key_out) {
    ZVAL_DEREF(offset);
    zend_string *key = zval_get_string(offset);
    zend_array *attrs = dd_tags_attrs_read(view->span);
    zval *val = attrs && !EG(exception) ? zend_symtable_find(attrs, key) : NULL;
    if (val && !dd_tags_in_bucket(val, view->metrics)) {
        val = NULL;
    }
    if (key_out) {
        *key_out = key;
    } else {
        zend_string_release(key);
    }
    return val;
}

// Only $meta aliases span properties: their values are strings.
static bool dd_tags_view_special_read(dd_span_tags_view *view, zend_string *key, zval *rv) {
    enum dd_tags_special special = dd_tags_special_of(key);
    return view->span && !view->metrics && special != DD_TAGS_PLAIN && dd_tags_special_read(view->span, special, rv);
}

static zval *dd_tags_view_read(dd_span_tags_view *view, zval *offset, int type, zval *rv) {
    if (!offset) {
        zend_throw_error(NULL, "Cannot read an appended element of SpanData::$%s", view->metrics ? "metrics" : "meta");
        return &EG(uninitialized_zval);
    }
    zend_string *key;
    zval *val = dd_tags_find(view, offset, &key);
    if (!EG(exception) && dd_tags_view_special_read(view, key, rv)) {
        // a legacy key aliasing a span property
    } else if (val) {
        ZVAL_COPY_DEREF(rv, val);
    } else {
        if (type == BP_VAR_R && !EG(exception)) {
#if PHP_VERSION_ID >= 80000
            zend_error(E_WARNING, "Undefined array key \"%s\"", ZSTR_VAL(key));
#else
            zend_error(E_NOTICE, "Undefined index: %s", ZSTR_VAL(key));
#endif
        }
        ZVAL_NULL(rv);
    }
    zend_string_release(key);
    return rv;
}

static void dd_tags_view_write(dd_span_tags_view *view, zval *offset, zval *value) {
    if (!offset) {
        zend_throw_error(NULL, "Cannot append to SpanData::$%s", view->metrics ? "metrics" : "meta");
        return;
    }
    if (!view->span) {
        return;
    }
    ZVAL_DEREF(offset);
    zend_string *key = zval_get_string(offset);
    if (!EG(exception)) {
        ddtrace_span_write_meta(view->span, key, value, view->metrics);
    }
    zend_string_release(key);
}

static bool dd_tags_view_has(dd_span_tags_view *view, zval *offset, int check_empty) {
    zend_string *key;
    zval *val = dd_tags_find(view, offset, &key), special;
    bool has = val && (check_empty ? zend_is_true(val) : 1);
    if (!EG(exception) && dd_tags_view_special_read(view, key, &special)) {
        has = check_empty ? zend_is_true(&special) : 1;
        zval_ptr_dtor(&special);
    }
    zend_string_release(key);
    return has;
}

static void dd_tags_view_unset(dd_span_tags_view *view, zval *offset) {
    zend_string *key;
    if (dd_tags_find(view, offset, &key)) {
        zend_symtable_del(ddtrace_property_array(&view->span->property_attributes), key);
    }
    if (view->span && !view->metrics && !EG(exception)) {
        enum dd_tags_special special = dd_tags_special_of(key);
        if (special != DD_TAGS_PLAIN) {
            dd_tags_special_reset(view->span, special);
        }
    }
    zend_string_release(key);
}

static zend_object *dd_tags_view_create(zend_class_entry *ce) {
    dd_span_tags_view *view = ecalloc(1, sizeof(*view) + zend_object_properties_size(ce));
    zend_object_std_init(&view->std, ce);
    object_properties_init(&view->std, ce);
    view->std.handlers = &dd_span_tags_view_handlers;
    return &view->std;
}

// The span keeps the cached view alive; the view only points back weakly.
static zend_object *dd_span_tags_view_get(ddtrace_span_data *span, int bucket) {
    zend_object *obj = span->tags_views[bucket];
    if (!obj) {
        obj = dd_tags_view_create(ddtrace_ce_span_tags_view);
        dd_span_tags_view *view = dd_tags_view_from_obj(obj);
        view->span = span;
        view->metrics = bucket == 1;
        span->tags_views[bucket] = obj;
    }
    GC_ADDREF(obj);
    return obj;
}

void ddtrace_span_tags_views_release(ddtrace_span_data *span) {
    for (int i = 0; i < 2; ++i) {
        zend_object *obj = span->tags_views[i];
        if (obj) {
            dd_tags_view_from_obj(obj)->span = NULL;
            span->tags_views[i] = NULL;
            OBJ_RELEASE(obj);
        }
    }
}

/* View object handlers */

static zval *dd_tags_view_read_dimension(DD_OBJ_ARG, zval *offset, int type, zval *rv) {
    return dd_tags_view_read(dd_tags_view_from_obj(DD_OBJ), offset, type, rv);
}

static void dd_tags_view_write_dimension(DD_OBJ_ARG, zval *offset, zval *value) {
    dd_tags_view_write(dd_tags_view_from_obj(DD_OBJ), offset, value);
}

static int dd_tags_view_has_dimension(DD_OBJ_ARG, zval *offset, int check_empty) {
    return dd_tags_view_has(dd_tags_view_from_obj(DD_OBJ), offset, check_empty);
}

static void dd_tags_view_unset_dimension(DD_OBJ_ARG, zval *offset) {
    dd_tags_view_unset(dd_tags_view_from_obj(DD_OBJ), offset);
}

#if PHP_VERSION_ID >= 80100
static zend_result dd_tags_view_count_elements(DD_OBJ_ARG, zend_long *count) {
#else
static int dd_tags_view_count_elements(DD_OBJ_ARG, zend_long *count) {
#endif
    dd_span_tags_view *view = dd_tags_view_from_obj(DD_OBJ);
    *count = dd_tags_count(view->span, view->metrics);
    return SUCCESS;
}

static zend_array *dd_tags_view_snapshot_ht(zend_object *obj) {
    dd_span_tags_view *view = dd_tags_view_from_obj(obj);
    zval snapshot;
    dd_tags_snapshot(view->span, view->metrics, &snapshot);
    return Z_ARR(snapshot);
}

#if PHP_VERSION_ID >= 70400
static zend_array *dd_tags_view_get_properties_for(DD_OBJ_ARG, zend_prop_purpose purpose) {
    (void)purpose;
    return dd_tags_view_snapshot_ht(DD_OBJ);
}
#else
static HashTable *dd_tags_view_get_debug_info(zval *object, int *is_temp) {
    *is_temp = 1;
    return dd_tags_view_snapshot_ht(Z_OBJ_P(object));
}
#endif

/* View methods (ArrayAccess, Countable, IteratorAggregate) */

#define DD_THIS_VIEW dd_tags_view_from_obj(Z_OBJ_P(ZEND_THIS))

PHP_METHOD(DDTrace_SpanTagsView, offsetGet) {
    zval *offset;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &offset) == FAILURE) {
        return;
    }
    dd_tags_view_read(DD_THIS_VIEW, offset, BP_VAR_R, return_value);
}

PHP_METHOD(DDTrace_SpanTagsView, offsetSet) {
    zval *offset, *value;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "zz", &offset, &value) == FAILURE) {
        return;
    }
    dd_tags_view_write(DD_THIS_VIEW, Z_TYPE_P(offset) == IS_NULL ? NULL : offset, value);
}

PHP_METHOD(DDTrace_SpanTagsView, offsetExists) {
    zval *offset;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &offset) == FAILURE) {
        return;
    }
    RETURN_BOOL(dd_tags_view_has(DD_THIS_VIEW, offset, 0));
}

PHP_METHOD(DDTrace_SpanTagsView, offsetUnset) {
    zval *offset;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &offset) == FAILURE) {
        return;
    }
    dd_tags_view_unset(DD_THIS_VIEW, offset);
}

PHP_METHOD(DDTrace_SpanTagsView, count) {
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    dd_span_tags_view *view = DD_THIS_VIEW;
    RETURN_LONG(dd_tags_count(view->span, view->metrics));
}

PHP_METHOD(DDTrace_SpanTagsView, getIterator) {
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    zval snapshot;
    ZVAL_ARR(&snapshot, dd_tags_view_snapshot_ht(Z_OBJ_P(ZEND_THIS)));
    object_init_ex(return_value, spl_ce_ArrayIterator);
#if PHP_VERSION_ID >= 80000
    zend_call_method_with_1_params(Z_OBJ_P(return_value), spl_ce_ArrayIterator, &spl_ce_ArrayIterator->constructor, "__construct", NULL, &snapshot);
#else
    zend_call_method_with_1_params(return_value, spl_ce_ArrayIterator, &spl_ce_ArrayIterator->constructor, "__construct", NULL, &snapshot);
#endif
    zval_ptr_dtor(&snapshot);
}

#if PHP_VERSION_ID >= 80100
ZEND_BEGIN_ARG_WITH_TENTATIVE_RETURN_TYPE_INFO_EX(arginfo_dd_tags_view_offsetGet, 0, 1, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_TENTATIVE_RETURN_TYPE_INFO_EX(arginfo_dd_tags_view_offsetSet, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_TENTATIVE_RETURN_TYPE_INFO_EX(arginfo_dd_tags_view_offsetExists, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_TENTATIVE_RETURN_TYPE_INFO_EX(arginfo_dd_tags_view_offsetUnset, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_TENTATIVE_RETURN_TYPE_INFO_EX(arginfo_dd_tags_view_count, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_TENTATIVE_RETURN_OBJ_INFO_EX(arginfo_dd_tags_view_getIterator, 0, 0, Iterator, 0)
ZEND_END_ARG_INFO()
#else
ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_tags_view_offsetGet, 0, 0, 1)
    ZEND_ARG_INFO(0, offset)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_tags_view_offsetSet, 0, 0, 2)
    ZEND_ARG_INFO(0, offset)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()
#define arginfo_dd_tags_view_offsetExists arginfo_dd_tags_view_offsetGet
#define arginfo_dd_tags_view_offsetUnset arginfo_dd_tags_view_offsetGet
ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_tags_view_count, 0, 0, 0)
ZEND_END_ARG_INFO()
#define arginfo_dd_tags_view_getIterator arginfo_dd_tags_view_count
#endif

static const zend_function_entry dd_span_tags_view_methods[] = {
    PHP_ME(DDTrace_SpanTagsView, offsetGet, arginfo_dd_tags_view_offsetGet, ZEND_ACC_PUBLIC)
    PHP_ME(DDTrace_SpanTagsView, offsetSet, arginfo_dd_tags_view_offsetSet, ZEND_ACC_PUBLIC)
    PHP_ME(DDTrace_SpanTagsView, offsetExists, arginfo_dd_tags_view_offsetExists, ZEND_ACC_PUBLIC)
    PHP_ME(DDTrace_SpanTagsView, offsetUnset, arginfo_dd_tags_view_offsetUnset, ZEND_ACC_PUBLIC)
    PHP_ME(DDTrace_SpanTagsView, count, arginfo_dd_tags_view_count, ZEND_ACC_PUBLIC)
    PHP_ME(DDTrace_SpanTagsView, getIterator, arginfo_dd_tags_view_getIterator, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

void ddtrace_register_span_tags_view(void) {
    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "DDTrace", "SpanTagsView", dd_span_tags_view_methods);
    ddtrace_ce_span_tags_view = zend_register_internal_class(&ce);
    ddtrace_ce_span_tags_view->ce_flags |= ZEND_ACC_FINAL;
    ddtrace_ce_span_tags_view->create_object = dd_tags_view_create;
    zend_class_implements(ddtrace_ce_span_tags_view, 3, zend_ce_arrayaccess, zend_ce_countable, zend_ce_aggregate);

    memcpy(&dd_span_tags_view_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    dd_span_tags_view_handlers.offset = XtOffsetOf(dd_span_tags_view, std);
    dd_span_tags_view_handlers.clone_obj = NULL;
    dd_span_tags_view_handlers.read_dimension = dd_tags_view_read_dimension;
    dd_span_tags_view_handlers.write_dimension = dd_tags_view_write_dimension;
    dd_span_tags_view_handlers.has_dimension = dd_tags_view_has_dimension;
    dd_span_tags_view_handlers.unset_dimension = dd_tags_view_unset_dimension;
    dd_span_tags_view_handlers.count_elements = dd_tags_view_count_elements;
#if PHP_VERSION_ID >= 70400
    dd_span_tags_view_handlers.get_properties_for = dd_tags_view_get_properties_for;
#else
    dd_span_tags_view_handlers.get_debug_info = dd_tags_view_get_debug_info;
#endif
}

/* SpanData property handlers for $meta / $metrics */

// Read contexts get an array snapshot (so array functions keep working); write contexts the view.
static zval *dd_span_read_property(DD_OBJ_ARG, DD_MEMBER_ARG, int type, void **cache_slot, zval *rv) {
    int bucket = dd_tags_bucket_of(DD_MEMBER);
    if (bucket < 0) {
        return std_object_handlers.read_property(object, member, type, cache_slot, rv);
    }
    ddtrace_span_data *span = OBJ_SPANDATA(DD_OBJ);
    if (type == BP_VAR_R || type == BP_VAR_IS) {
        dd_tags_snapshot(span, bucket, rv);
    } else {
        ZVAL_OBJ(rv, dd_span_tags_view_get(span, bucket));
    }
    return rv;
}

// NULL keeps the engine off the property slot (and its runtime cache) for $meta/$metrics.
static zval *dd_span_get_property_ptr_ptr(DD_OBJ_ARG, DD_MEMBER_ARG, int type, void **cache_slot) {
    if (dd_tags_bucket_of(DD_MEMBER) >= 0) {
        return NULL;
    }
    return std_object_handlers.get_property_ptr_ptr(object, member, type, cache_slot);
}

static int dd_span_has_property(DD_OBJ_ARG, DD_MEMBER_ARG, int has_set_exists, void **cache_slot) {
    int bucket = dd_tags_bucket_of(DD_MEMBER);
    if (bucket < 0) {
        return std_object_handlers.has_property(object, member, has_set_exists, cache_slot);
    }
    if (has_set_exists == 1) {  // empty()
        return dd_tags_count(OBJ_SPANDATA(DD_OBJ), bucket) > 0;
    }
    return 1;
}

static void dd_span_unset_property(DD_OBJ_ARG, DD_MEMBER_ARG, void **cache_slot) {
    int bucket = dd_tags_bucket_of(DD_MEMBER);
    if (bucket < 0) {
        std_object_handlers.unset_property(object, member, cache_slot);
        return;
    }
    dd_tags_replace_bucket(OBJ_SPANDATA(DD_OBJ), bucket, NULL);
}

bool ddtrace_span_tags_view_write_property(zend_object *obj, zend_string *name, zval *value) {
    int bucket = dd_tags_bucket_of(name);
    if (bucket < 0) {
        return false;
    }
    ddtrace_span_data *span = OBJ_SPANDATA(obj);
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) == IS_ARRAY) {
        dd_tags_replace_bucket(span, bucket, Z_ARR_P(value));
    } else if (Z_TYPE_P(value) == IS_NULL) {
        dd_tags_replace_bucket(span, bucket, NULL);
    } else if (Z_TYPE_P(value) == IS_OBJECT && Z_OBJCE_P(value) == ddtrace_ce_span_tags_view) {
        zval snapshot;
        ZVAL_ARR(&snapshot, dd_tags_view_snapshot_ht(Z_OBJ_P(value)));
        dd_tags_replace_bucket(span, bucket, Z_ARR(snapshot));
        zval_ptr_dtor(&snapshot);
    } else {
        zend_type_error("Cannot assign %s to property %s::$%s of type array", zend_zval_type_name(value), ZSTR_VAL(obj->ce->name), ZSTR_VAL(name));
    }
    return true;
}

// Properties table with $meta/$metrics replaced by their snapshots.
static zend_array *dd_span_properties_with_tags(zend_object *obj, zend_array *props) {
    zend_array *ht = zend_new_array(zend_hash_num_elements(props));
    zend_string *key;
    zend_ulong h;
    zval *val;
    ZEND_HASH_FOREACH_KEY_VAL_IND(props, h, key, val) {
        int bucket = key ? dd_tags_bucket_of(key) : -1;
        zval copy;
        if (bucket >= 0) {
            dd_tags_snapshot(OBJ_SPANDATA(obj), bucket, &copy);
        } else {
            ZVAL_COPY(&copy, val);
        }
        if (key) {
            zend_hash_add_new(ht, key, &copy);
        } else {
            zend_hash_index_add_new(ht, h, &copy);
        }
    } ZEND_HASH_FOREACH_END();
    return ht;
}

#if PHP_VERSION_ID >= 70400
static zend_array *dd_span_get_properties_for(DD_OBJ_ARG, zend_prop_purpose purpose) {
    if (purpose == ZEND_PROP_PURPOSE_SERIALIZE) {
        return zend_std_get_properties_for(object, purpose);
    }
    return dd_span_properties_with_tags(DD_OBJ, std_object_handlers.get_properties(object));
}
#else
static HashTable *dd_span_get_debug_info(zval *object, int *is_temp) {
    *is_temp = 1;
    return dd_span_properties_with_tags(Z_OBJ_P(object), std_object_handlers.get_properties(object));
}
#endif

void ddtrace_span_tags_view_install(zend_object_handlers *handlers) {
    handlers->read_property = dd_span_read_property;
    handlers->get_property_ptr_ptr = dd_span_get_property_ptr_ptr;
    handlers->has_property = dd_span_has_property;
    handlers->unset_property = dd_span_unset_property;
#if PHP_VERSION_ID >= 70400
    handlers->get_properties_for = dd_span_get_properties_for;
#else
    handlers->get_debug_info = dd_span_get_debug_info;
#endif
}
