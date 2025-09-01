#include "code_origins.h"
#include "configuration.h"
#include "zend_generators.h"
#include <Zend/zend_API.h>

static inline bool dd_is_dir_sep(const char c) {
#ifdef _WIN32
    return c == '/' || c == '\\';
#else
    return c == '/';
#endif
}

void ddtrace_add_code_origin_information(ddtrace_span_data *span, int skip_frames) {
    zend_array *meta = ddtrace_property_array(&span->property_meta);

    zend_long max_frames = get_DD_CODE_ORIGIN_MAX_USER_FRAMES();
    int current_frame = 0;
    for (zend_execute_data *execute_data = EG(current_execute_data); execute_data && current_frame < max_frames; execute_data = EX(prev_execute_data)) {
        if (UNEXPECTED(!EX(func))) {
            execute_data = zend_generator_check_placeholder_frame(execute_data);
        }
        if (!EX(func) || !ZEND_USER_CODE(EX(func)->type) || !EX(func)->op_array.filename) {
            continue;
        }
        if (skip_frames > 0) {
            --skip_frames;
            continue;
        }
        // Heuristically exclude code outside of the git repository, essentially
        const char *vendor = zend_memnstr(ZSTR_VAL(EX(func)->op_array.filename), ZEND_STRL("vendor"), ZSTR_VAL(EX(func)->op_array.filename) + ZSTR_LEN(EX(func)->op_array.filename));
        if (vendor && dd_is_dir_sep(vendor[-1]) && dd_is_dir_sep(vendor[6])) {
            continue;
        }

        if (current_frame == 0) {
            zval type, *kind = zend_hash_str_find_deref(meta, ZEND_STRL("span.kind"));
            ZVAL_STRING(&type, (kind && Z_TYPE_P(kind) == IS_STRING ? zend_string_equals_literal(Z_STR_P(kind), "server") || zend_string_equals_literal(Z_STR_P(kind), "producer") : &span->root->span == span) ? "entry" : "exit");
            if (!zend_hash_str_add(meta, ZEND_STRL("_dd.code_origin.type"), &type)) {
                zend_string_release(Z_STR(type));
                return; // skip if already present
            }
        }

        zval zv;
        zend_string *key;

        key = zend_strpprintf(0, "_dd.code_origin.frames.%d.file", current_frame);
        ZVAL_STR_COPY(&zv, EX(func)->op_array.filename);
        zend_hash_update(meta, key, &zv);
        zend_string_release(key);

        key = zend_strpprintf(0, "_dd.code_origin.frames.%d.line", current_frame);
        ZVAL_LONG(&zv, current_frame == 0 ? EX(func)->op_array.line_start : EX(opline)->lineno);
        zend_hash_update(meta, key, &zv);
        zend_string_release(key);

        if (EX(func)->op_array.function_name) {
            key = zend_strpprintf(0, "_dd.code_origin.frames.%d.method", current_frame);
            ZVAL_STR_COPY(&zv, EX(func)->op_array.function_name);
            zend_hash_update(meta, key, &zv);
            zend_string_release(key);
        }

        if (EX(func)->op_array.scope) {
            key = zend_strpprintf(0, "_dd.code_origin.frames.%d.type", current_frame);
            ZVAL_STR_COPY(&zv, EX(func)->op_array.scope->name);
            zend_hash_update(meta, key, &zv);
            zend_string_release(key);
        }
        ++current_frame;
    }
}

void ddtrace_maybe_add_code_origin_information(ddtrace_span_data *span) {
    if (get_DD_CODE_ORIGIN_FOR_SPANS_ENABLED()) {
        zval *type = &span->property_type;
        ZVAL_DEREF(type);
        if (Z_TYPE_P(type) == IS_STRING && Z_STRLEN_P(type) != 0) {
            // If it's identical, we don't add it to the child so that only the parent span will be added the code origin information
            if (span->parent && zend_is_identical(type, &span->parent->property_type)) {
                zend_array *meta = ddtrace_property_array(&span->property_meta);
                zval *kind = zend_hash_str_find(meta, ZEND_STRL("span.kind"));
                if (kind) {
                    zend_array *parent_meta = ddtrace_property_array(&span->parent->property_meta);
                    zval *parent_kind = zend_hash_str_find(parent_meta, ZEND_STRL("span.kind"));
                    // span.kind must match, otherwise websocket has false positives for example, where both entry and exit span have type websocket
                    if (!parent_kind || zend_is_identical(kind, parent_kind)) {
                        return;
                    }
                } else {
                    return;
                }
            }

            ddtrace_add_code_origin_information(span, 0);
        }
    }
}
