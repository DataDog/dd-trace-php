#include "trace_source.h"

#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define TRACE_SOURCE_APM (1 << 0)
#define TRACE_SOURCE_ASM (1 << 1)
#define TRACE_SOURCE_DSM (1 << 2)
#define TRACE_SOURCE_DJM (1 << 3)
#define TRACE_SOURCE_DBM (1 << 4)

static zend_string *_dd_tag_p_ts;

void ddtrace_trace_source_minit() {
    _dd_tag_p_ts = zend_string_init_interned(ZEND_STRL(DD_P_TS_KEY), 1 /* permanent */);
}

zend_string *ddtrace_trace_source_get_encoded(uint32_t source) {
    zend_string *encoded = zend_string_alloc(2, 0);
    snprintf(ZSTR_VAL(encoded), 3, "%02x" PRIx64, source);
    return encoded;
}

static uint32_t ddtrace_tracer_source_decode_hexadecimal(zend_string *hexadecimal, char **endptr) {
    return strtol(ZSTR_VAL(hexadecimal), endptr, 16);
}

static void ddtrace_trace_source_add_propagated_tag(zend_string *encoded) {
    if (!encoded) {
        return;
    }
    zval ts_encoded_zv;
    ZVAL_STR(&ts_encoded_zv, encoded);
    ddtrace_add_propagated_tag(_dd_tag_p_ts, &ts_encoded_zv);
    zend_string_release(encoded);
}

static zend_array *ddtrace_trace_source_get_meta() {
    if (!DDTRACE_G(active_stack)) {
        return NULL;
    }
    ddtrace_root_span_data *root_span = DDTRACE_G(active_stack)->root_span;
    if (!root_span) {
        return NULL;
    }
    return ddtrace_property_array(&root_span->property_meta);
}

static void ddtrace_add_trace_source_to_meta(zend_string *encoded, zend_array *meta)
{
    if (!meta || !encoded) {
        return;
    }

    zval trace_source_zv;
    ZVAL_STR(&trace_source_zv, zend_string_copy(encoded));
    zend_hash_str_update(meta, ZEND_STRL(DD_P_TS_KEY), &trace_source_zv);
}

static void ddtrace_add_trace_source(uint32_t source, zend_array *meta)
{
    zend_string *encoded = ddtrace_trace_source_get_encoded(source);

    ddtrace_add_trace_source_to_meta(encoded, meta);
    ddtrace_trace_source_add_propagated_tag(encoded);
}

void ddtrace_trace_source_set_from_hexadecimal(zend_string *hexadecimal, zend_array *meta)
{
    if (!hexadecimal || ZSTR_LEN(hexadecimal) < 2 || ZSTR_LEN(hexadecimal) > 8) {
        return;
    }
    char *endptr = NULL;
    uint32_t binary = ddtrace_tracer_source_decode_hexadecimal(hexadecimal, &endptr);
    if (!endptr || *endptr != '\0') {
        return;
    }
    ddtrace_add_trace_source(binary, meta);
}

void ddtrace_trace_source_set_asm_source() {
    zend_array *meta = ddtrace_trace_source_get_meta();
    if (!meta) {
        return;
    }
    ddtrace_add_trace_source(TRACE_SOURCE_ASM, meta);
}

bool ddtrace_trace_source_is_meta_asm_sourced(zend_array *meta) {
    if (!meta) {
        return false;
    }

    zval *trace_source_zv = zend_hash_str_find(meta, ZEND_STRL(DD_P_TS_KEY));
    if (!trace_source_zv || Z_TYPE_P(trace_source_zv) != IS_STRING) {
        return false;
    }

    uint32_t source = ddtrace_tracer_source_decode_hexadecimal(Z_STR_P(trace_source_zv), NULL);

    return source & TRACE_SOURCE_ASM;
}

bool ddtrace_trace_source_is_trace_asm_sourced(zval *trace) {
    if (!trace || Z_TYPE_P(trace) != IS_ARRAY) {
        return false;
    }

    zval *root_span = zend_hash_index_find(Z_ARR_P(trace), 0);
    if (!root_span || Z_TYPE_P(root_span) != IS_ARRAY) {
        return false;
    }

    zval *meta = zend_hash_str_find(Z_ARR_P(root_span), ZEND_STRL("meta"));

    if (!meta || Z_TYPE_P(meta) != IS_ARRAY) {
        return false;
    }

    return ddtrace_trace_source_is_meta_asm_sourced(Z_ARR_P(meta));
}


