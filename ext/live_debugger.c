#include "live_debugger.h"
#include "ddtrace.h"
#include "zai_string/string.h"
#include "span.h"
#include "hook/uhook.h"
#include "sidecar.h"
#include "hook/hook.h"
#include "serializer.h"
#include "compat_string.h"
#include "zend_interfaces.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

struct eval_ctx {
    zend_execute_data *frame;
    zend_arena *arena;
    zval *retval;
};

static void clean_ctx(struct eval_ctx *ctx) {
    if (ctx->arena) {
        zend_arena *arena = ctx->arena;
        do {
            zend_arena *prev = arena->prev;
            for (zval *cur = (zval *)((char *)arena + ZEND_MM_ALIGNED_SIZE(sizeof(zend_arena))); cur < (zval *)arena->ptr; ++cur) {
                zval_ptr_dtor(cur);
            }
            arena = prev;
        } while (arena);
        zend_arena_destroy(ctx->arena);
    }
}

static bool dd_eval_condition(const ddog_ProbeCondition *condition, zval *retval) {
    struct eval_ctx ctx = {
        .frame = EG(current_execute_data),
        .arena = NULL,
        .retval = retval,
    };
    bool result = evaluate_condition(condition, &ctx);
    clean_ctx(&ctx);
    return result;
}

static zend_string *dd_eval_string(const ddog_DslString *string, zval *retval) {
    struct eval_ctx ctx = {
            .frame = EG(current_execute_data),
            .arena = NULL,
            .retval = retval,
    };
    ddog_VoidCollection bytes = evaluate_unmanaged_string(string, &ctx);
    zend_string *str = zend_string_init(bytes.elements, bytes.count, 0);
    bytes.free(bytes);
    clean_ctx(&ctx);
    return str;
}

static zval *dd_persist_eval_arena(struct eval_ctx *eval_ctx, zval *zv) {
    if (!eval_ctx->arena) {
        eval_ctx->arena = zend_arena_create(4096);
    }
    zval *zvp = zend_arena_alloc(&eval_ctx->arena, sizeof(zval));
    ZVAL_COPY_VALUE(zvp, zv);
    return zvp;
}

typedef struct {
    ddtrace_span_data *span;
} dd_span_probe_dynamic;

typedef struct {
    zend_string *function;
    zend_string *scope;
    zend_string *file;
    zend_string *probe_id;
} dd_span_probe_def;

static bool dd_probe_file_mismatch(dd_span_probe_def *def, zend_execute_data *execute_data) {
    return def->file && (!execute_data->func->op_array.filename || !ddtrace_uhook_match_filepath(execute_data->func->op_array.filename, def->file));
}

static bool dd_span_probe_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_span_probe_def *def = auxiliary;
    dd_span_probe_dynamic *dyn = dynamic;

    if (dd_probe_file_mismatch(def, execute_data)) {
        dyn->span = NULL;
        return true;
    }

    dyn->span = ddtrace_alloc_execute_data_span(invocation, execute_data);

    zval garbage;
    ZVAL_COPY_VALUE(&garbage, &dyn->span->property_name);
    ZVAL_STRING(&dyn->span->property_name, "dd.dynamic.span");
    zval_ptr_dtor(&garbage);

    return true;
}

static void dd_span_probe_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_span_probe_dynamic *dyn = dynamic;

    UNUSED(execute_data, retval, auxiliary);

    if (dyn->span) {
        ddtrace_clear_execute_data_span(invocation, true);
    }
}

static void dd_span_probe_dtor(void *data) {
    dd_span_probe_def *def = data;
    if (def->file) {
        zend_string_release(def->file);
    }
    if (def->scope) {
        zend_string_release(def->scope);
    }
    if (def->function) {
        zend_string_release(def->function);
    }
    zend_string_release(def->probe_id);
    efree(def);
}

static bool dd_init_live_debugger_probe(ddog_CharSlice id, const ddog_ProbeTarget *target, dd_span_probe_def *def) {
    def->probe_id = dd_CharSlice_to_zend_string(id);
    def->file = NULL;
    def->function = NULL;
    def->scope = NULL;

    if (target->type_name.tag == DDOG_OPTION_CHAR_SLICE_SOME_CHAR_SLICE) {
        def->scope = dd_CharSlice_to_zend_string(target->type_name.some);
    }
    if (target->method_name.tag == DDOG_OPTION_CHAR_SLICE_SOME_CHAR_SLICE) {
        def->function = dd_CharSlice_to_zend_string(target->method_name.some);
    } else if (target->source_file.tag == DDOG_OPTION_CHAR_SLICE_SOME_CHAR_SLICE) {
        def->file = dd_CharSlice_to_zend_string(target->source_file.some);
    } else {
        return false;
    }

    return true;
}

static int64_t dd_set_span_probe(ddog_CharSlice probe_id, const ddog_ProbeTarget *target) {
    dd_span_probe_def *def = emalloc(sizeof(*def));
    zend_long id;
    if (!dd_init_live_debugger_probe(probe_id, target, def)
       || 0 > (id = zai_hook_install(
                def->scope ? (zai_str) ZAI_STR_FROM_ZSTR(def->scope) : (zai_str) ZAI_STR_EMPTY,
                def->function ? (zai_str) ZAI_STR_FROM_ZSTR(def->function) : (zai_str) ZAI_STR_EMPTY,
                dd_span_probe_begin,
                dd_span_probe_end,
                ZAI_HOOK_AUX(def, dd_span_probe_dtor),
                sizeof(dd_span_probe_dynamic)))) {
        dd_span_probe_dtor(def);
        return -1;
    }

    return id;
}

typedef struct {
    dd_span_probe_def span;
    ddog_EvaluateAt evaluate_at;
    ddog_SpanDecorationProbe decoration;
} dd_span_decoration_def;

static void dd_span_decoration_dtor(void *data) {
    dd_span_decoration_def *def = data;
    drop_span_decoration_probe(def->decoration);
    dd_span_probe_dtor(&def->span);
}

static void dd_span_decoration_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_span_decoration_def *def = auxiliary;
    ddtrace_span_data *span = ddtrace_active_span();
    if (!span) {
        return;
    }
    UNUSED(invocation, dynamic);
    if (dd_probe_file_mismatch(&def->span, execute_data)) {
        return;
    }

    if (def->evaluate_at == DDOG_SPAN_PROBE_TARGET_ROOT) {
        span = &span->stack->root_span->span;
    }
    zend_array *meta = ddtrace_property_array(&span->property_meta);

    bool condition_result = true;
    const ddog_ProbeCondition *const *condition = def->decoration.conditions;
    for (int i = 0; i < def->decoration.span_tags_num; ++i) {
        const ddog_SpanProbeTag *spanTag = def->decoration.span_tags + i;
        if (spanTag->next_condition) {
            condition_result = dd_eval_condition(*condition++, retval);
        }
        if (condition_result) {
            zval zv;
            ZVAL_STR(&zv, dd_eval_string(spanTag->tag.value, retval));
            zend_hash_str_update(meta, spanTag->tag.name.ptr, spanTag->tag.name.len, &zv);

            zend_string *tag = zend_strpprintf(0, "_dd.di.%.*s.probe_id", (int)spanTag->tag.name.len, spanTag->tag.name.ptr);
            ZVAL_STR_COPY(&zv, def->span.probe_id);
            zend_hash_update(meta, tag, &zv);
            zend_string_release(tag);
        }
    }
}

static bool dd_span_decoration_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    zval retval;
    ZVAL_NULL(&retval);
    dd_span_decoration_end(invocation, execute_data, &retval, auxiliary, dynamic);
    return true;
}

static int64_t dd_set_span_decoration(ddog_CharSlice probe_id, const ddog_ProbeTarget *target, ddog_EvaluateAt evaluate_at, ddog_SpanDecorationProbe decoration) {
    dd_span_decoration_def *def = emalloc(sizeof(*def));
    def->decoration = decoration;
    def->evaluate_at = evaluate_at;

    zai_hook_begin begin = NULL;
    zai_hook_end end = NULL;
    if (target->in_body_location == DDOG_EVALUATE_AT_ENTRY) {
        begin = dd_span_decoration_begin;
    } else {
        end = dd_span_decoration_end;
    }
    zend_long id;
    if (!dd_init_live_debugger_probe(probe_id, target, &def->span)
        || 0 > (id = zai_hook_install(
            def->span.scope ? (zai_str) ZAI_STR_FROM_ZSTR(def->span.scope) : (zai_str) ZAI_STR_EMPTY,
            def->span.function ? (zai_str) ZAI_STR_FROM_ZSTR(def->span.function) : (zai_str) ZAI_STR_EMPTY,
            begin,
            end,
            ZAI_HOOK_AUX(def, dd_span_decoration_dtor),
            0))) {
        dd_span_decoration_dtor(def);
        return -1;
    }

    return id;
}

typedef struct {
    dd_span_probe_def span;
    ddog_EvaluateAt evaluate_at;
    ddog_LogProbe log;
} dd_log_probe_def;

static void dd_log_probe_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_log_probe_def *def = auxiliary;
    UNUSED(invocation, dynamic);
    if (dd_probe_file_mismatch(&def->span, execute_data)) {
        return;
    }

    if (!dd_eval_condition(def->log.when, retval)) {
        return;
    }

    ;

}

static bool dd_log_probe_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    zval retval;
    ZVAL_NULL(&retval);
    dd_span_decoration_end(invocation, execute_data, &retval, auxiliary, dynamic);
    return true;
}

static int64_t dd_set_log_probe(ddog_CharSlice probe_id, const ddog_ProbeTarget *target, ddog_EvaluateAt evaluate_at, ddog_LogProbe log) {
    dd_log_probe_def *def = emalloc(sizeof(*def));
    def->log = log;
    def->evaluate_at = evaluate_at;

    zai_hook_begin begin = NULL;
    zai_hook_end end = NULL;
    if (target->in_body_location == DDOG_EVALUATE_AT_ENTRY) {
        begin = dd_log_probe_begin;
    } else {
        end = dd_log_probe_end;
    }
    zend_long id;
    if (!dd_init_live_debugger_probe(probe_id, target, &def->span)
        || 0 > (id = zai_hook_install(
            def->span.scope ? (zai_str) ZAI_STR_FROM_ZSTR(def->span.scope) : (zai_str) ZAI_STR_EMPTY,
            def->span.function ? (zai_str) ZAI_STR_FROM_ZSTR(def->span.function) : (zai_str) ZAI_STR_EMPTY,
            begin,
            end,
            ZAI_HOOK_AUX(def, dd_span_probe_dtor),
            0))) {
        dd_span_probe_dtor(&def->span);
        return -1;
    }

    return id;
}

static int64_t dd_set_metric_probe(ddog_CharSlice probe_id, const ddog_ProbeTarget *target, ddog_EvaluateAt evaluate_at, ddog_MetricProbe metric) {

}

static void dd_remove_live_debugger_probe(int64_t id) {
    dd_span_probe_def *def;
    if ((def = zend_hash_index_find_ptr(&DDTRACE_G(active_rc_hooks), (zend_ulong)id))) {
        zai_hook_remove(
                def->scope ? (zai_str)ZAI_STR_FROM_ZSTR(def->scope) : (zai_str)ZAI_STR_EMPTY,
                def->function ? (zai_str)ZAI_STR_FROM_ZSTR(def->function) : (zai_str)ZAI_STR_EMPTY,
                id);
    }
}

static void dd_free_void_collection_none(struct ddog_VoidCollection collection) {
    UNUSED(collection);
}

static ddog_VoidCollection dd_empty_collection = (ddog_VoidCollection){
    .free = dd_free_void_collection_none,
    .count = 0,
    .elements = NULL,
};

static void dd_free_void_collection(struct ddog_VoidCollection collection) {
    efree((void *)collection.elements);
}

static ddog_VoidCollection dd_alloc_void_collection(uint32_t elements) {
    return (ddog_VoidCollection){
        .free = dd_free_void_collection,
        .count = elements,
        .elements = emalloc(sizeof(void *)),
    };
}

static void dd_intermediate_to_zval(struct ddog_IntermediateValue val, zval *zv) {
    switch (val.tag) {
        case DDOG_INTERMEDIATE_VALUE_STRING:
            ZVAL_STRINGL(zv, val.string.ptr, val.string.len);
            break;
        case DDOG_INTERMEDIATE_VALUE_NUMBER:
            ZVAL_DOUBLE(zv, val.number);
            break;
        case DDOG_INTERMEDIATE_VALUE_BOOL:
            ZVAL_BOOL(zv, val.bool_);
            break;
        case DDOG_INTERMEDIATE_VALUE_NULL:
            ZVAL_NULL(zv);
            break;
        case DDOG_INTERMEDIATE_VALUE_REFERENCED:
            ZVAL_COPY(zv, val.referenced);
            break;
    }
}

static zend_long dd_zval_convert_index(zval *zvp, bool *success) {
    zval *dim = (zval *) zvp;

    ZVAL_DEREF(dim);
    switch (Z_TYPE_P(dim)) {
        case IS_LONG:
            *success = true;
            return Z_LVAL_P(dim);
        case IS_STRING:
            zend_long off;
            *success = IS_LONG == is_numeric_string_ex(Z_STRVAL_P(dim), Z_STRLEN_P(dim), &off, NULL, true, NULL, NULL);
            return off;
        default:
            *success = false;
            return 0;
    }
}

static inline int dd_eval_cmp(struct ddog_IntermediateValue a, struct ddog_IntermediateValue b) {
    zval zva, zvb;
    dd_intermediate_to_zval(a, &zva);
    dd_intermediate_to_zval(b, &zvb);

    int ret = zend_compare(&zva, &zvb);

    zval_ptr_dtor(&zva);
    zval_ptr_dtor(&zvb);

    return ret;
}

static bool dd_eval_equals(void *ctx, struct ddog_IntermediateValue a, struct ddog_IntermediateValue b) {
    UNUSED(ctx);
#define TAGCASE(a, b) MIN(a, b) + (MAX(a, b) << 4)
    switch (TAGCASE(a.tag, b.tag)) {
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_STRING, DDOG_INTERMEDIATE_VALUE_STRING):
            return a.string.len == b.string.len && memcmp(a.string.ptr, b.string.ptr, a.string.len) == 0;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_NUMBER, DDOG_INTERMEDIATE_VALUE_NUMBER):
            return a.number == b.number;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_BOOL, DDOG_INTERMEDIATE_VALUE_BOOL):
            return a.bool_ == b.bool_;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_BOOL, DDOG_INTERMEDIATE_VALUE_NUMBER):
            return a.tag == DDOG_INTERMEDIATE_VALUE_BOOL ? a.number == b.bool_ : a.bool_ == b.number;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_NULL, DDOG_INTERMEDIATE_VALUE_NULL):
            return true;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_NULL, DDOG_INTERMEDIATE_VALUE_BOOL):
            return (b.tag == DDOG_INTERMEDIATE_VALUE_NULL ? a.bool_ : b.bool_) == false;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_NUMBER, DDOG_INTERMEDIATE_VALUE_NULL):
            return (b.tag == DDOG_INTERMEDIATE_VALUE_NULL ? a.number : b.number) == 0;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_STRING, DDOG_INTERMEDIATE_VALUE_NULL):
            return (b.tag == DDOG_INTERMEDIATE_VALUE_NULL ? a.string : b.string).len == 0;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_STRING, DDOG_INTERMEDIATE_VALUE_BOOL):
            return ((b.tag == DDOG_INTERMEDIATE_VALUE_BOOL ? a.string : b.string).len == 0) != (b.tag != DDOG_INTERMEDIATE_VALUE_BOOL ? a.bool_ : b.bool_);
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_STRING, DDOG_INTERMEDIATE_VALUE_REFERENCED):
            // avoid copies for ref == str
            const zval *zv = a.tag == DDOG_INTERMEDIATE_VALUE_REFERENCED ? a.referenced : b.referenced;
            if (Z_TYPE_P(zv) == IS_STRING) {
                ddog_CharSlice *str = a.tag == DDOG_INTERMEDIATE_VALUE_REFERENCED ? &b.string : &a.string;
                return zend_string_equals_cstr(Z_STR_P(zv), str->ptr, str->len);
            }
    }

    return dd_eval_cmp(a, b) == 0;
}

static bool dd_eval_greater_than(void *ctx, struct ddog_IntermediateValue a, struct ddog_IntermediateValue b) {
    UNUSED(ctx);
    return dd_eval_cmp(a, b) > 0;
}

static bool dd_eval_greater_or_equals(void *ctx, struct ddog_IntermediateValue a, struct ddog_IntermediateValue b) {
    UNUSED(ctx);
    return dd_eval_cmp(a, b) >= 0;
}

static const void *dd_eval_fetch_identifier(void *ctx, const ddog_CharSlice *name) {
    struct eval_ctx *eval_ctx = ctx;
    zend_execute_data *execute_data = eval_ctx->frame;

    if (EX(func) && ZEND_USER_CODE(EX(func)->type)) {
#if PHP_VERSION_ID < 70100
        if (!EX(symbol_table)) {
#else
        if (!(EX_CALL_INFO() & ZEND_CALL_HAS_SYMBOL_TABLE)) {
#endif
            zend_rebuild_symbol_table();
        }
        zval *zvp = zend_hash_str_find(EX(symbol_table), name->ptr, name->len);
        if (zvp) {
            return zvp;
        }
    }

    if (name->len == sizeof("duration") && memcmp(name->ptr, ZEND_STRL("@duration")) == 0) {
        ddtrace_span_data *span = ddtrace_active_span();
        if (span) {
            zval zv;
            ZVAL_LONG(&zv, zend_hrtime() - span->duration_start);
            return dd_persist_eval_arena(eval_ctx, &zv);
        } else {
            return NULL;
        }
    }

    if (name->len == sizeof("return") && memcmp(name->ptr, ZEND_STRL("@return")) == 0) {
        return eval_ctx->retval;
    }

    if (name->len == sizeof("exception") && memcmp(name->ptr, ZEND_STRL("@exception")) == 0) {
        if (EG(exception)) {
            zval zv;
            ZVAL_OBJ_COPY(&zv, EG(exception));
            return dd_persist_eval_arena(eval_ctx, &zv);
        }
        return NULL;
    }
    
    return NULL;
}

static const void *dd_eval_fetch_index(void *ctx, const void *container_ptr, struct ddog_IntermediateValue index) {
    zval *container = (zval *)container_ptr, *dim = (zval *)index.referenced;
    ZVAL_DEREF(container);
    switch (Z_TYPE_P(container)) {
        case IS_OBJECT:
            zend_property_info *prop;
            zend_string *prop_name;
            if (index.tag == DDOG_INTERMEDIATE_VALUE_STRING) {
                prop = zend_hash_str_find_ptr(&Z_OBJCE_P(container)->properties_info, index.string.ptr, index.string.len);
                if (!prop) {
                    prop_name = zend_string_init(index.string.ptr, index.string.len, 0);
                } else {
                    prop_name = zend_string_copy(prop->name);
                }
            } else if (index.tag == DDOG_INTERMEDIATE_VALUE_REFERENCED) {
                ZVAL_DEREF(dim);
                if (Z_TYPE_P(dim) != IS_STRING) {
                    return NULL;
                }
                prop = zend_hash_find_ptr(&Z_OBJCE_P(container)->properties_info, Z_STR_P(dim));
                prop_name = zend_string_copy(Z_STR_P(dim));
            } else {
                return NULL;
            }
            zval rv;
            uint32_t *guard, orig_guard;
            if (Z_OBJCE_P(container)->ce_flags & ZEND_ACC_USE_GUARDS) {
                guard = zend_get_property_guard(Z_OBJ_P(container), prop_name);
                orig_guard = *guard;
                *guard |= ZEND_GUARD_PROPERTY_MASK; // bypass __magicMethods
            } else {
                guard = NULL;
            }
            zval *ret = zend_read_property_ex(prop ? prop->ce : Z_OBJCE_P(container), Z_OBJ_P(container), prop_name, 1, &rv);
            if (guard) {
                *guard = orig_guard;
            }
            zend_string_release(prop_name);
            if (ret == &EG(uninitialized_zval)) {
                return NULL;
            }
            if (ret == &rv) {
                ret = dd_persist_eval_arena(ctx, ret);
            }
            return ret;
        case IS_ARRAY:
            switch (index.tag) {
                case DDOG_INTERMEDIATE_VALUE_STRING:
                    return zend_symtable_str_find(Z_ARR_P(container), index.string.ptr, index.string.len);
                case DDOG_INTERMEDIATE_VALUE_NUMBER:
                    return zend_hash_index_find(Z_ARR_P(container), (zend_ulong)index.number);
                case DDOG_INTERMEDIATE_VALUE_REFERENCED:
                    ZVAL_DEREF(dim);
                    switch (Z_TYPE_P(dim)) {
                        case IS_STRING:
                            return zend_symtable_find(Z_ARR_P(container), Z_STR_P(dim));
                        case IS_LONG:
                            return zend_hash_index_find(Z_ARR_P(container), (zend_ulong) Z_LVAL_P(dim));
                        case IS_DOUBLE:
                            return zend_hash_index_find(Z_ARR_P(container), (zend_ulong) Z_DVAL_P(dim));
                    }
                    return NULL;
                default:
                    return NULL;
            }
        case IS_STRING:
            zend_long off;
            switch (index.tag) {
                case DDOG_INTERMEDIATE_VALUE_STRING:
                    char *end = (char *)index.string.ptr + index.string.len;
                    off = strtoll(index.string.ptr, &end, 10);
                    break;
                case DDOG_INTERMEDIATE_VALUE_NUMBER:
                    off = (zend_long)index.number;
                    break;
                case DDOG_INTERMEDIATE_VALUE_REFERENCED:
                    bool success;
                    off = dd_zval_convert_index((zval *)index.referenced, &success);
                    if (!success) {
                        return NULL;
                    }
                    break;
                default:
                    return NULL;
            }
            zval zv;
            if (off < 0 || off >= Z_STRLEN_P(container)) {
                ZVAL_EMPTY_STRING(&zv);
            } else {
                char chr = Z_STRVAL_P(container)[off];
#if PHP_VERSION_ID < 70200
                ZVAL_STRINGL(&zv, chr, 1);
#else
                ZVAL_STR_COPY(&zv, zend_one_char_string[(unsigned char) chr]);
#endif
            }
            return dd_persist_eval_arena(ctx, &zv);
        default:
            return NULL;
    }
}

static uint64_t dd_eval_length(void *ctx, const void *zvp) {
    UNUSED(ctx);
    const zval *zv = zvp;
    retry:
    switch (Z_TYPE_P(zv)) {
        case IS_REFERENCE:
            ZVAL_DEREF(zv);
            goto retry;

        case IS_ARRAY:
            return zend_array_count(Z_ARRVAL_P(zv));

        case IS_OBJECT:
            /* first, we check if the handler is defined */
            if (Z_OBJ_HT_P(zv)->count_elements) {
                zend_long num;
                zend_object *ex = EG(exception);
                if (SUCCESS == Z_OBJ_HT(*zv)->count_elements(Z_OBJ_P(zv), &num)) {
                    EG(exception) = ex;
                    return (uint64_t)num;
                }
                if (EG(exception)) {
                    zend_clear_exception();
                }
                EG(exception) = ex;
            }
            /* if not and the object implements Countable we call its count() method */
            if (instanceof_function(Z_OBJCE_P(zv), zend_ce_countable)) {
                zval retval;
                zai_symbol_call_named(ZAI_SYMBOL_SCOPE_OBJECT, Z_OBJ_P(zv), &(zai_str) ZAI_STRL("count"), &retval, 0);
                if (Z_TYPE(retval) != IS_UNDEF) {
                    convert_to_long(&retval);
                    return Z_LVAL(retval);
                }
            }

            return zend_array_count(Z_OBJPROP_P(zv));

        default:
            return 1;
    }
}

static ddog_VoidCollection dd_eval_try_enumerate(void *ctx, const void *zvp) {
    UNUSED(ctx);
    const zval *zv = zvp;
    HashTable *values;
    retry:
    switch (Z_TYPE_P(zv)) {
        case IS_REFERENCE:
            ZVAL_DEREF(zv);
            goto retry;

        case IS_ARRAY:
            values = Z_ARR_P(zv);
            break;

        case IS_OBJECT:
            values = Z_OBJPROP_P(zv);
            break;

        default:
            return dd_empty_collection;
    }

    zval *val;
    int idx = 0;
    ddog_VoidCollection collection = dd_alloc_void_collection(zend_hash_num_elements(values));
    ZEND_HASH_FOREACH_VAL_IND(values, val) {
        ((zval **)collection.elements)[idx++] = val;
    } ZEND_HASH_FOREACH_END();
    collection.count = idx;
    return collection;
}

static void dd_free_void_collection_string(struct ddog_VoidCollection str) {
    zend_string_release((zend_string *)((char*)str.elements - XtOffsetOf(zend_string, val)));
}

static void dd_stringify_limited_str(zend_string *string, smart_str *str, const ddog_Capture *config) {
    if (ZSTR_LEN(string) <= config->max_length) {
        smart_str_append(str, string);
    } else {
        smart_str_appendl(str, ZSTR_VAL(string), config->max_length);
        smart_str_appends(str, "...");
    }
}

static void dd_stringify_zval(zval *zv, smart_str *str, const ddog_Capture *config, int remaining_nesting) {
    ZVAL_DEREF(zv);
    switch (Z_TYPE_P(zv)) {
        case IS_FALSE:
            smart_str_appends(str, "false");
            break;

        case IS_TRUE:
            smart_str_appends(str, "true");
            break;

        case IS_LONG:
            smart_str_append_long(str, Z_LVAL_P(zv));
            break;

        case IS_DOUBLE:
            smart_str_append_double(str, Z_DVAL_P(zv), EG(precision), 0);
            break;

        case IS_STRING:
            dd_stringify_limited_str(Z_STR_P(zv), str, config);
            break;

        case IS_ARRAY: {
            if (remaining_nesting == 0) {
                smart_str_appends(str, "[...]");
                break;
            }
            zval *val;
            bool first = true;
            smart_str_appendc(str, '[');
            if (zend_array_is_list(Z_ARR_P(zv))) {
                int remaining_fields = config->max_collection_size;
                ZEND_HASH_FOREACH_VAL(Z_ARR_P(zv), val) {
                    if (!first) {
                        smart_str_appends(str, ", ");
                    }
                    first = false;
                    if (remaining_fields-- == 0) {
                        smart_str_appends(str, "...]");
                        break;
                    }

                    dd_stringify_zval(val, str, config, remaining_nesting - 1);
                } ZEND_HASH_FOREACH_END();
            } else {
                zend_long idx;
                zend_string *key;
                int remaining_fields = config->max_collection_size;
                ZEND_HASH_FOREACH_KEY_VAL(Z_ARR_P(zv), idx, key, val) {
                    if (!first) {
                        smart_str_appends(str, ", ");
                    }
                    first = false;
                    if (remaining_fields-- == 0) {
                        smart_str_appends(str, "...]");
                        break;
                    }

                    if (key) {
                        dd_stringify_limited_str(key, str, config);
                    } else {
                        smart_str_append_long(str, idx);
                    }
                    smart_str_appends(str, " => ");
                    dd_stringify_zval(val, str, config, remaining_nesting - 1);
                } ZEND_HASH_FOREACH_END();
            }
            smart_str_appendc(str, ']');
            break;
        }

        case IS_OBJECT: {
            zend_class_entry *ce = Z_OBJCE_P(zv);
            smart_str_appendc(str, '(');
            smart_str_append(str, ce->name);
            smart_str_appendc(str, ')');
            smart_str_appendc(str, '{');
            if (remaining_nesting == 0) {
                smart_str_appends(str, "...}");
                break;
            }
            zval *val;
            zend_string *key;
            int remaining_fields = config->max_field_depth;
#if PHP_VERSION_ID < 70400
            int is_temp = 0;
#endif
            // reverse to prefer child class properties first
            HashTable *ht = ce->type == ZEND_INTERNAL_CLASS ?
#if PHP_VERSION_ID < 70400
                    Z_OBJDEBUG_P(zv, is_temp)
#else
                    zend_get_properties_for(zv, ZEND_PROP_PURPOSE_DEBUG)
#endif
                    : Z_OBJPROP_P(zv);
            bool first = true;
            ZEND_HASH_REVERSE_FOREACH_STR_KEY_VAL(ht, key, val) {
                if (!key) {
                    continue;
                }

                if (!first) {
                    smart_str_appends(str, ", ");
                }
                first = false;
                if (remaining_fields-- == 0) {
                    smart_str_appends(str, "...}");
                    break;
                }

                if (ZSTR_LEN(key) < 3 || ZSTR_VAL(key)[0]) {
                    smart_str_append(str, key);
                } else if (ZSTR_VAL(key)[1] == '*') { // skip \0*\0
                    smart_str_appendl(str, ZSTR_VAL(key) + 3, ZSTR_LEN(key) - 3);
                } else {
                    int classname_len = strlen(ZSTR_VAL(key) + 1);
                    smart_str_appendl(str, ZSTR_VAL(key) + 1, classname_len);
                    smart_str_appends(str, "::");
                    smart_str_appendl(str, ZSTR_VAL(key) + classname_len + 2, ZSTR_LEN(key) - classname_len - 2);
                }
                dd_stringify_zval(val, str, config, remaining_nesting - 1);
            } ZEND_HASH_FOREACH_END();
            if (ce->type == ZEND_INTERNAL_CLASS) {
#if PHP_VERSION_ID < 70400
                if (is_temp) {
                    zend_array_release(ht);
                }
#else
                zend_release_properties(ht);
#endif
            }
            break;
        }

        case IS_RESOURCE: {
            smart_str_appends(str, zend_rsrc_list_get_rsrc_type(Z_RES_P(zv)));
            smart_str_appendc(str, '#');
            smart_str_append_long(str, Z_RES_P(zv)->handle);
            break;
        }

        default:
            smart_str_appends(str, "null");
    }
}


static ddog_VoidCollection dd_eval_stringify(void *ctx, const void *zvp) {
    UNUSED(ctx);
    const zval *zv = zvp;

    smart_str str = {0};
    dd_stringify_zval(zv, &str, 1);
    return (ddog_VoidCollection){
        .free = dd_free_void_collection_string,
        .count = ZSTR_LEN(str.s),
        .elements = ZSTR_VAL(str.s),
    };
}

static ddog_VoidCollection dd_eval_get_string(void *ctx, const void *zvp) {
    UNUSED(ctx);
    const zval *zv = zvp;
    zend_string *str = ddtrace_convert_to_str(zv);
    return (ddog_VoidCollection){
            .free = dd_free_void_collection_string,
            .count = ZSTR_LEN(str),
            .elements = ZSTR_VAL(str),
    };
}

static intptr_t dd_eval_convert_index(void *ctx, const void *zvp) {
    UNUSED(ctx);
    bool success;
    return dd_zval_convert_index((zval *)zvp, &success);
}

const ddog_Evaluator dd_evaluator = {
        .equals = dd_eval_equals,
        .greater_than = dd_eval_greater_than,
        .greater_or_equals = dd_eval_greater_or_equals,
        .fetch_identifier = dd_eval_fetch_identifier,
        .fetch_index = dd_eval_fetch_index,
        .fetch_nested = dd_eval_fetch_index,
        .length = dd_eval_length,
        .try_enumerate = dd_eval_try_enumerate,
        .stringify = dd_eval_stringify,
        .get_string = dd_eval_get_string,
        .convert_index = dd_eval_convert_index,
};

ddog_LiveDebuggerSetup ddtrace_live_debugger_setup = {
    .callbacks = {
        .set_span_probe = dd_set_span_probe,
        .set_span_decoration = dd_set_span_decoration,
        .set_log_probe = dd_set_log_probe,
        .set_metric_probe = dd_set_metric_probe,
        .remove_probe = dd_remove_live_debugger_probe,
    },
    .evaluator = &dd_evaluator,
};
