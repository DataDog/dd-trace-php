#include "distributed_tracing_headers.h"
#include "configuration.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"
#include "serializer.h"
#include <config/config_ini.h>
#include <headers/headers.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static inline bool dd_is_hex_char(char chr) {
    return (chr >= '0' && chr <= '9') || (chr >= 'a' && chr <= 'f');
}

static ddtrace_distributed_tracing_result dd_init_empty_result(void) {
    ddtrace_distributed_tracing_result result = {0};
    result.priority_sampling = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    zend_hash_init(&result.tracestate_unknown_dd_keys, 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&result.propagated_tags, 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&result.meta_tags, 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&result.baggage, 8, unused, ZVAL_PTR_DTOR, 0);
    return result;
}

static void dd_check_tid(ddtrace_distributed_tracing_result *result) {
    zval *tidzv = zend_hash_str_find(&result->meta_tags, ZEND_STRL("_dd.p.tid"));
    if (tidzv && result->trace_id.low) {
        uint64_t tid = ddtrace_parse_hex_span_id(tidzv);
        uint64_t cur_high = result->trace_id.high;
        if (tid && Z_TYPE_P(tidzv) == IS_STRING && Z_STRLEN_P(tidzv) == 16) {
            if (!cur_high || tid == cur_high) {
                result->trace_id.high = tid;
            } else {
                zval error;
                ZVAL_STR(&error, zend_strpprintf(0, "inconsistent_tid %s", Z_STRVAL_P(tidzv)));
                zend_hash_str_update(&result->meta_tags, ZEND_STRL("_dd.propagation_error"), &error);
            }
        } else if (Z_TYPE_P(tidzv) == IS_STRING && strcmp(Z_STRVAL_P(tidzv), "0") != 0) {
            zval error;
            ZVAL_STR(&error, zend_strpprintf(0, "malformed_tid %s", Z_STRVAL_P(tidzv)));
            zend_hash_str_update(&result->meta_tags, ZEND_STRL("_dd.propagation_error"), &error);
        }
        zend_hash_str_del(&result->meta_tags, ZEND_STRL("_dd.p.tid"));
        zend_hash_str_del(&result->propagated_tags, ZEND_STRL("_dd.p.tid"));
    }
}

static inline int hex2int(char c) {
    return (c >= '0' && c <= '9') ? c - '0' :
           (c >= 'a' && c <= 'f') ? c - 'a' + 10 :
           (c >= 'A' && c <= 'F') ? c - 'A' + 10 : -1;
}

static void ddtrace_deserialize_baggage(char *baggage_ptr, char *baggage_end, HashTable *baggage) {
    bool is_malformed = false;

    while (baggage_ptr < baggage_end) {
        // Extract key
        char *key_start = baggage_ptr;
        while (baggage_ptr < baggage_end && *baggage_ptr != '=' && *baggage_ptr != ',') {
            ++baggage_ptr;
        }

        if (baggage_ptr >= baggage_end || *baggage_ptr != '=') {
            is_malformed = true;
            break;
        }

        size_t key_len = baggage_ptr - key_start;
        if (key_len == 0) {  // Empty key is invalid
            is_malformed = true;
            break;
        }
        ++baggage_ptr; // Move past '='

        // Extract value
        char *value_start = baggage_ptr;
        while (baggage_ptr < baggage_end && *baggage_ptr != ',') {
            ++baggage_ptr;
        }

        size_t value_len = baggage_ptr - value_start;
        if (value_len == 0) {  // Empty value is invalid
            is_malformed = true;
            break;
        }

        // Allocate decoded key/value storage
        zend_string *decoded_key = zend_string_alloc(key_len, 0);
        zend_string *decoded_value = zend_string_alloc(value_len, 0);

        char *out_key = ZSTR_VAL(decoded_key);
        char *out_value = ZSTR_VAL(decoded_value);

        // Decode key (no validation, just decoding)
        char *in = key_start, *end = key_start + key_len;
        while (in < end) {
            if (*in == '%' && (in + 2 < end) && isxdigit((unsigned char)in[1]) && isxdigit((unsigned char)in[2])) {
                int high = hex2int(in[1]);
                int low = hex2int(in[2]);
                if (high == -1 || low == -1) {
                    is_malformed = true;  // Only discard if decoding fails
                    break;
                }
                *out_key++ = (char)((high << 4) + low);
                in += 3;
            } else {
                *out_key++ = *in++;
            }
        }
        ZSTR_LEN(decoded_key) = out_key - ZSTR_VAL(decoded_key);
        ZSTR_VAL(decoded_key)[ZSTR_LEN(decoded_key)] = '\0';

        // Decode value (same logic, no validation, just decoding)
        in = value_start, end = value_start + value_len;
        while (in < end) {
            if (*in == '%' && (in + 2 < end) && isxdigit((unsigned char)in[1]) && isxdigit((unsigned char)in[2])) {
                int high = hex2int(in[1]);
                int low = hex2int(in[2]);
                if (high == -1 || low == -1) {
                    is_malformed = true;
                    break;
                }
                *out_value++ = (char)((high << 4) + low);
                in += 3;
            } else {
                *out_value++ = *in++;
            }
        }
        ZSTR_LEN(decoded_value) = out_value - ZSTR_VAL(decoded_value);
        ZSTR_VAL(decoded_value)[ZSTR_LEN(decoded_value)] = '\0';

        // **Do not validate the key** after decoding, just store it.
        if (is_malformed) {
            zend_string_release(decoded_key);
            zend_string_release(decoded_value);
            break;
        }

        // Store key-value in baggage
        zval baggage_value;
        ZVAL_STR(&baggage_value, decoded_value);
        zend_symtable_update(baggage, decoded_key, &baggage_value);
        zend_string_release(decoded_key);

        // Move past ',' if it's there
        if (baggage_ptr < baggage_end && *baggage_ptr == ',') {
            ++baggage_ptr;
        }
    }

    if (is_malformed) {
        zend_hash_clean(baggage);
    }
}

static ddtrace_distributed_tracing_result ddtrace_read_distributed_tracing_ids_datadog(ddtrace_read_header *read_header, void *data) {
    zend_string *trace_id_str, *parent_id_str, *priority_str, *propagated_tags;
    ddtrace_distributed_tracing_result result = dd_init_empty_result();

    read_header((zai_str)ZAI_STRL("X_DATADOG_ORIGIN"), "x-datadog-origin", &result.origin, data);

    if (read_header((zai_str)ZAI_STRL("X_DATADOG_TRACE_ID"), "x-datadog-trace-id", &trace_id_str, data)) {
        zval trace_zv;
        ZVAL_STR(&trace_zv, trace_id_str);
        result.trace_id = (ddtrace_trace_id) {.low = ddtrace_parse_userland_span_id(&trace_zv)};
        zend_string_release(trace_id_str);
    }

    if (!result.trace_id.low && !result.trace_id.high) {
        return result;
    }

    if (read_header((zai_str)ZAI_STRL("X_DATADOG_PARENT_ID"), "x-datadog-parent-id", &parent_id_str, data)) {
        zval parent_zv;
        ZVAL_STR(&parent_zv, parent_id_str);
        result.parent_id = ddtrace_parse_userland_span_id(&parent_zv);
        zend_string_release(parent_id_str);
    }

    if (read_header((zai_str)ZAI_STRL("X_DATADOG_SAMPLING_PRIORITY"), "x-datadog-sampling-priority", &priority_str, data)) {
        result.priority_sampling = strtol(ZSTR_VAL(priority_str), NULL, 10);
        zend_string_release(priority_str);
    }

    if (read_header((zai_str)ZAI_STRL("X_DATADOG_TAGS"), "x-datadog-tags", &propagated_tags, data)) {
        ddtrace_add_tracer_tags_from_header(propagated_tags, &result.meta_tags, &result.propagated_tags);
        zend_string_release(propagated_tags);

        dd_check_tid(&result);
    }

    return result;
}

static ddtrace_distributed_tracing_result ddtrace_read_distributed_tracing_ids_b3_single_header(ddtrace_read_header *read_header, void *data) {
    zend_string *b3_header_str;
    ddtrace_distributed_tracing_result result = dd_init_empty_result();

    if (read_header((zai_str)ZAI_STRL("B3"), "b3", &b3_header_str, data)) {
        char *b3_ptr = ZSTR_VAL(b3_header_str), *b3_end = b3_ptr + ZSTR_LEN(b3_header_str);
        char *b3_traceid = b3_ptr;
        while (b3_ptr < b3_end && *b3_ptr != '-') {
            ++b3_ptr;
        }

        result.trace_id = ddtrace_parse_hex_trace_id(b3_traceid, b3_ptr - b3_traceid);

        char *b3_spanid = ++b3_ptr;
        while (b3_ptr < b3_end && *b3_ptr != '-') {
            ++b3_ptr;
        }

        result.parent_id = ddtrace_parse_hex_span_id_str(b3_spanid, b3_ptr - b3_spanid);

        char *b3_sampling = ++b3_ptr;
        while (b3_ptr < b3_end && *b3_ptr != '-') {
            ++b3_ptr;
        }

        if (b3_ptr - b3_sampling == 1) {
            if (*b3_sampling == '0') {
                result.priority_sampling = 0;
            } else if (*b3_sampling == '1') {
                result.priority_sampling = 1;
            } else if (*b3_sampling == 'd') {
                result.priority_sampling = PRIORITY_SAMPLING_USER_KEEP;
            }
        } else if (b3_ptr - b3_sampling == 4 && strncmp(b3_sampling, "true", 4) == 0) {
            result.priority_sampling = 1;
        } else if (b3_ptr - b3_sampling == 5 && strncmp(b3_sampling, "false", 5) == 0) {
            result.priority_sampling = 0;
        }
        zend_string_release(b3_header_str);
    }

    return result;
}

static ddtrace_distributed_tracing_result ddtrace_read_distributed_tracing_ids_b3(ddtrace_read_header *read_header, void *data) {
    zend_string *trace_id_str, *parent_id_str, *priority_str;
    ddtrace_distributed_tracing_result result = dd_init_empty_result();

    if (read_header((zai_str)ZAI_STRL("X_B3_TRACEID"), "x-b3-traceid", &trace_id_str, data)) {
        result.trace_id = ddtrace_parse_hex_trace_id(ZSTR_VAL(trace_id_str), ZSTR_LEN(trace_id_str));
        zend_string_release(trace_id_str);
    }

    if (!result.trace_id.low && !result.trace_id.high) {
        return result;
    }

    if (read_header((zai_str)ZAI_STRL("X_B3_SPANID"), "x-b3-spanid", &parent_id_str, data)) {
        zval parent_zv;
        ZVAL_STR(&parent_zv, parent_id_str);
        result.parent_id = ddtrace_parse_hex_span_id(&parent_zv);
        zend_string_release(parent_id_str);
    }

    if (read_header((zai_str)ZAI_STRL("X_B3_SAMPLED"), "x-b3-sampled", &priority_str, data)) {
        if (ZSTR_LEN(priority_str) == 1) {
            if (ZSTR_VAL(priority_str)[0] == '0') {
                result.priority_sampling = 0;
            } else if (ZSTR_VAL(priority_str)[0] == '1') {
                result.priority_sampling = 1;
            }
        } else if (zend_string_equals_literal(priority_str, "true")) {
            result.priority_sampling = 1;
        } else if (zend_string_equals_literal(priority_str, "false")) {
            result.priority_sampling = 0;
        }
        zend_string_release(priority_str);
    } else if (read_header((zai_str)ZAI_STRL("X_B3_FLAGS"), "x-b3-flags", &priority_str, data)) {
        if (ZSTR_LEN(priority_str) == 1 && ZSTR_VAL(priority_str)[1] == '1') {
            result.priority_sampling = PRIORITY_SAMPLING_USER_KEEP;
        }
        zend_string_release(priority_str);
    }

    return result;
}

static ddtrace_distributed_tracing_result ddtrace_read_distributed_tracing_ids_tracecontext(ddtrace_read_header *read_header, void *data) {
    zend_string *traceparent, *tracestate;
    ddtrace_distributed_tracing_result result = dd_init_empty_result();

    // "{version:2}-{trace-id:32}-{parent-id:16}-{trace-flags:2}"
    if (read_header((zai_str)ZAI_STRL("TRACEPARENT"), "traceparent", &traceparent, data)) {
        // skip whitespace
        char *ws = ZSTR_VAL(traceparent), *wsend = ws + ZSTR_LEN(traceparent);
        while (ws < wsend && isspace(*ws)) {
            ++ws;
        }
        if (ws == wsend) {
            zend_string_release(traceparent);
            return result;
        }
        while (isspace(*--wsend));

        size_t tracedata_len = wsend + 1 - ws;
        struct {
            char version[2];
            char version_hyphen;
            char trace_id[32];
            char trace_id_hyphen;
            char parent_id[16];
            char parent_id_hyphen;
            char trace_flags[2];
            char trailing_data[];
        } *tracedata = (void *)ws;

        if (tracedata_len < sizeof(*tracedata)
            || !dd_is_hex_char(tracedata->version[0]) || !dd_is_hex_char(tracedata->version[1])
            || *(uint16_t *)tracedata->version == ('f' << 8) + 'f' // 0xFF is invalid version
            || tracedata->version_hyphen != '-'
            || tracedata->trace_id_hyphen != '-'
            || tracedata->parent_id_hyphen != '-'
            || !dd_is_hex_char(tracedata->trace_flags[0]) || !dd_is_hex_char(tracedata->trace_flags[1])
            || (tracedata_len > sizeof(*tracedata)
                && ((tracedata->version[0] == '0' && tracedata->version[1] == '0') || tracedata->trailing_data[0] != '-'))
                ) {
            zend_string_release(traceparent);
            return result;
        }

        ddtrace_trace_id trace_id = {
                .high = ddtrace_parse_hex_span_id_str(tracedata->trace_id, 16),
                .low = ddtrace_parse_hex_span_id_str(&tracedata->trace_id[16], 16)
        };
        uint64_t parent_id = ddtrace_parse_hex_span_id_str(tracedata->parent_id, 16);

        zend_string_release(traceparent);

        if ((!trace_id.low && !trace_id.high) || !parent_id) {
            return result;
        }

        result.trace_id = trace_id;
        result.parent_id = parent_id;
        result.priority_sampling = (tracedata->trace_flags[1] & 1) == (tracedata->trace_flags[1] <= '9'); // ('a' & 1) == 1

        zend_string *span_parent_key = zend_string_init("_dd.parent_id", strlen("_dd.parent_id"), 0);

        // header format: "[*,]dd=p:0000000000000111;s:1;o:rum;t.dm:-4;t.usr.id:12345[,*]"
        if (read_header((zai_str)ZAI_STRL("TRACESTATE"), "tracestate", &tracestate, data)) {
            bool last_comma = true;
            result.tracestate = zend_string_alloc(ZSTR_LEN(tracestate), 0);
            char *persist = ZSTR_VAL(result.tracestate);
            int commas = 0;
            for (char *ptr = ZSTR_VAL(tracestate), *end = ptr + ZSTR_LEN(tracestate); ptr < end; ++ptr) {
                // dd member
                if (last_comma && ptr + 2 < end && ptr[0] == 'd' && ptr[1] == 'd' && (ptr[2] == '=' || ptr[2] == '\t' || ptr[2] == ' ')) {
                    // If there's dd= members, ignore x-datadog-tags fully
                    while (ptr < end && *ptr != '=') {
                        ++ptr;
                    }

                    do {
                        char *keystart = ++ptr;
                        while (ptr < end && *ptr != ';' && *ptr != ',' && *ptr != ':') {
                            ++ptr;
                        }
                        size_t keylen = ptr - keystart;
                        if (ptr >= end) {
                            break;
                        }
                        char *valuestart = ++ptr;
                        while (ptr < end && *ptr != ';' && *ptr != ',') {
                            ++ptr;
                        }
                        char *valueend = ptr;
                        while (*valueend == ' ' || *valueend == '\t') {
                            --valueend;
                        }
                        size_t valuelen = valueend - valuestart;

                        if (keylen == 1 && keystart[0] == 'p') {
                            if (span_parent_key) {
                                zval zv;
                                ZVAL_STRINGL(&zv, valuestart, valuelen);
                                zend_hash_update(&result.meta_tags, span_parent_key, &zv);
                                zend_string_release(span_parent_key);
                                span_parent_key = NULL;
                            }
                        } else if (keylen == 1 && keystart[0] == 's') {
                            int extraced_priority = strtol(valuestart, NULL, 10);
                            if ((result.priority_sampling > 0) == (extraced_priority > 0)) {
                                result.priority_sampling = extraced_priority;
                            } else {
                                result.conflicting_sampling_priority = true;
                            }
                        } else if (keylen == 1 && keystart[0] == 'o') {
                            if (result.origin) {
                                zend_string_release(result.origin);
                            }
                            result.origin = zend_string_init(valuestart, valuelen, 0);
                            for (char *valptr = ZSTR_VAL(result.origin), *valend = valptr + valuelen; valptr < valend; ++valptr) {
                                if (*valptr == '~') {
                                    *valptr = '=';
                                }
                            }
                        } else if (keylen > 2 && keystart[0] == 't' && keystart[1] == '.') {
                            zend_string *tag_name = zend_strpprintf(0, "_dd.p.%.*s", (int) keylen - 2, keystart + 2);
                            zval zv;
                            ZVAL_STRINGL(&zv, valuestart, valuelen);
                            for (char *valptr = Z_STRVAL(zv), *valend = valptr + valuelen; valptr < valend; ++valptr) {
                                if (*valptr == '~') {
                                    *valptr = '=';
                                }
                            }
                            zend_hash_update(&result.meta_tags, tag_name, &zv);
                            zend_hash_add_empty_element(&result.propagated_tags, tag_name);
                            zend_string_release(tag_name);
                        } else {
                            zval zv;
                            ZVAL_STRINGL(&zv, valuestart, valuelen);
                            zend_hash_str_update(&result.tracestate_unknown_dd_keys, keystart, keylen, &zv);
                        }
                    } while (*ptr == ';');

                    continue;
                }
                *(persist++) = *ptr;

                if (*ptr == ' ' || *ptr == '\t') {
                    continue;
                }

                last_comma = *ptr == ',';
                // preserve only up to 31 vendor specific values, excluding our own
                if (last_comma && ++commas == 30) {
                    --persist;
                    break;
                }
            }
            *persist = 0; // and zero-terminate it
            ZSTR_LEN(result.tracestate) = persist - ZSTR_VAL(result.tracestate);
            zend_string_release(tracestate);
        }

        if (span_parent_key) {
            zval zv;
            ZVAL_STRING(&zv, "0000000000000000");
            zend_hash_update(&result.meta_tags, span_parent_key, &zv);
            zend_string_release(span_parent_key);
        }

        dd_check_tid(&result);
    }

    return result;
}

ddtrace_distributed_tracing_result ddtrace_read_distributed_tracing_ids(ddtrace_read_header *read_header, void *data) {
    ddtrace_distributed_tracing_result result = {0};

    zend_array *extract = zai_config_is_modified(DDTRACE_CONFIG_DD_TRACE_PROPAGATION_STYLE)
                          && !zai_config_is_modified(DDTRACE_CONFIG_DD_TRACE_PROPAGATION_STYLE_EXTRACT)
                          ? get_DD_TRACE_PROPAGATION_STYLE() : get_DD_TRACE_PROPAGATION_STYLE_EXTRACT();

    zend_string *extraction_style;
    ddtrace_distributed_tracing_result (*func)(ddtrace_read_header *read_header, void *data) = NULL;

    ZEND_HASH_FOREACH_STR_KEY(extract, extraction_style) {
        bool has_trace = result.trace_id.low || result.trace_id.high;

        if (!has_trace && zend_string_equals_literal(extraction_style, "datadog")) {
            func = ddtrace_read_distributed_tracing_ids_datadog;
        } else if (zend_string_equals_literal(extraction_style, "tracecontext")) {
            func = ddtrace_read_distributed_tracing_ids_tracecontext;
        } else if (!has_trace && (zend_string_equals_literal(extraction_style, "b3") || zend_string_equals_literal(extraction_style, "b3multi"))) {
            func = ddtrace_read_distributed_tracing_ids_b3;
        } else if (!has_trace && zend_string_equals_literal(extraction_style, "b3 single header")) {
            func = ddtrace_read_distributed_tracing_ids_b3_single_header;
        } else {
            continue;
        }

        if (!has_trace) {
            zend_string *existing_origin = result.origin;

            if (result.meta_tags.arData) {
                zend_hash_destroy(&result.meta_tags);
            }
            if (result.propagated_tags.arData) {
                zend_hash_destroy(&result.propagated_tags);
            }
            if (result.tracestate_unknown_dd_keys.arData) {
                zend_hash_destroy(&result.tracestate_unknown_dd_keys);
            }

            result = func(read_header, data);

            // As an exception, the x-datadog-origin can be submitted standalone, without valid trace id
            if (existing_origin) {
                if (result.trace_id.low || result.trace_id.high) {
                    zend_string_release(existing_origin);
                } else {
                    if (result.origin) {
                        zend_string_release(result.origin);
                    }
                    result.origin = existing_origin;
                }
            }
        } else {
            ddtrace_distributed_tracing_result new_result = func(read_header, data);

            if (result.trace_id.low == new_result.trace_id.low && result.trace_id.high == new_result.trace_id.high) {
                if (!result.tracestate && new_result.tracestate) {
                    result.tracestate = new_result.tracestate;
                    new_result.tracestate = NULL;

                    zend_hash_destroy(&result.tracestate_unknown_dd_keys);
                    result.tracestate_unknown_dd_keys = new_result.tracestate_unknown_dd_keys;
                    zend_hash_init(&new_result.tracestate_unknown_dd_keys, 0, NULL, NULL, 0);
                }
                if (result.parent_id != new_result.parent_id) {
                    // set last datadog span_id tag
                    zval *lp_id = zend_hash_str_find(&new_result.meta_tags, ZEND_STRL("_dd.parent_id"));
                    if (lp_id && !zend_string_equals_literal(Z_STR_P(lp_id), "0000000000000000")) {
                        Z_TRY_ADDREF_P(lp_id);
                        zend_hash_str_update(&result.meta_tags, ZEND_STRL("_dd.parent_id"), lp_id);
                    } else if (result.parent_id != 0) {
                        zval parent_id_zval;
                        ZVAL_STR(&parent_id_zval, zend_string_alloc(16, 0));
                        sprintf(Z_STRVAL_P(&parent_id_zval), "%016" PRIx64, result.parent_id);
                        zend_hash_str_update(&result.meta_tags, ZEND_STRL("_dd.parent_id"), &parent_id_zval);
                    }
                    result.parent_id = new_result.parent_id;
                }
            }

            if (new_result.tracestate) {
                zend_string_release(new_result.tracestate);
            }
            if (new_result.origin) {
                zend_string_release(new_result.origin);
            }

            zend_hash_destroy(&new_result.meta_tags);
            zend_hash_destroy(&new_result.propagated_tags);
            zend_hash_destroy(&new_result.tracestate_unknown_dd_keys);
        }
    } ZEND_HASH_FOREACH_END();

    if (zend_hash_str_exists(extract, ZEND_STRL("baggage"))) {
        zend_string *baggage_header;
        if (read_header((zai_str)ZAI_STRL("BAGGAGE"), "baggage", &baggage_header, data)) {
            char *baggage_ptr = ZSTR_VAL(baggage_header);
            char *baggage_end = baggage_ptr + ZSTR_LEN(baggage_header);

            if (!func) {
                result = dd_init_empty_result();
            }

            ddtrace_deserialize_baggage(baggage_ptr, baggage_end, &result.baggage);
            zend_string_release(baggage_header);

            return result;
        }
    }

    if (!func) {
        return dd_init_empty_result();
    }

    return result;
}

void ddtrace_apply_distributed_tracing_result(ddtrace_distributed_tracing_result *result, ddtrace_root_span_data *span) {
    zval zv;

    zend_array *root_meta = span ? ddtrace_property_array(&span->property_meta) : &DDTRACE_G(root_span_tags_preset);
    if (span) {
        zend_string *tagname;
        ZEND_HASH_FOREACH_STR_KEY(ddtrace_property_array(&span->property_propagated_tags), tagname) {
            zend_hash_del(root_meta, tagname);
        } ZEND_HASH_FOREACH_END();

        ZVAL_ARR(&zv, emalloc(sizeof(HashTable)));
        *Z_ARR(zv) = result->propagated_tags;
        ddtrace_assign_variable(&span->property_propagated_tags, &zv);

        zend_hash_copy(root_meta, &result->meta_tags, NULL);

        if (result->origin) {
            ZVAL_STR(&zv, result->origin);
            ddtrace_assign_variable(&span->property_origin, &zv);
        }

        if (result->tracestate) {
            ZVAL_STR(&zv, result->tracestate);
            ddtrace_assign_variable(&span->property_tracestate, &zv);
        }

        ZVAL_ARR(&zv, emalloc(sizeof(HashTable)));
        *Z_ARR(zv) = result->tracestate_unknown_dd_keys;
        ddtrace_assign_variable(&span->property_tracestate_tags, &zv);

        zend_array *existing_baggage = ddtrace_property_array(&span->property_baggage);
        zend_string *key;
        zend_ulong key_i;
        zval *val;
        ZEND_HASH_FOREACH_KEY_VAL(&result->baggage, key_i, key, val) {
                if (key) {
                    zend_hash_update(existing_baggage, key, val);
                } else {
                    zend_hash_index_update(existing_baggage, key_i, val);
                }
                Z_TRY_ADDREF_P(val);
        } ZEND_HASH_FOREACH_END();
        zend_hash_destroy(&result->baggage);

        if (result->trace_id.low || result->trace_id.high) {
            span->trace_id = result->trace_id;
            span->parent_id = result->parent_id;
            ddtrace_update_root_id_properties(span);
        }
    } else {
        zend_hash_destroy(&DDTRACE_G(propagated_root_span_tags));
        DDTRACE_G(propagated_root_span_tags) = result->propagated_tags;
        zend_hash_destroy(&DDTRACE_G(tracestate_unknown_dd_keys));
        DDTRACE_G(tracestate_unknown_dd_keys) = result->tracestate_unknown_dd_keys;
        zend_hash_copy(&DDTRACE_G(root_span_tags_preset), &result->meta_tags, NULL);
        if (DDTRACE_G(dd_origin)) {
            zend_string_release(DDTRACE_G(dd_origin));
        }
        DDTRACE_G(dd_origin) = result->origin;
        if (DDTRACE_G(tracestate)) {
            zend_string_release(DDTRACE_G(tracestate));
        }
        DDTRACE_G(tracestate) = result->tracestate;  
        zend_hash_destroy(&DDTRACE_G(baggage));
        DDTRACE_G(baggage) = result->baggage;
        
        if (result->trace_id.low || result->trace_id.high) {
            DDTRACE_G(distributed_trace_id) = result->trace_id;
            DDTRACE_G(distributed_parent_trace_id) = result->parent_id;
        }
    }

    result->meta_tags.pDestructor = NULL; // we moved values directly
    zend_hash_destroy(&result->meta_tags);

    if (result->priority_sampling != DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        bool reset_decision_maker = result->conflicting_sampling_priority || !zend_hash_str_exists(root_meta, ZEND_STRL("_dd.p.dm"));
        if (reset_decision_maker) {
            if (result->priority_sampling > 0) {
                ZVAL_STRINGL(&zv, "-0", 2);
                zend_hash_str_update(root_meta, ZEND_STRL("_dd.p.dm"), &zv);
                zend_hash_str_add_empty_element(span ? ddtrace_property_array(&span->property_propagated_tags) : &DDTRACE_G(propagated_root_span_tags), ZEND_STRL("_dd.p.dm"));
            } else {
                zend_hash_str_del(root_meta, ZEND_STRL("_dd.p.dm"));
            }
        }
        if (!span) {
            DDTRACE_G(propagated_priority_sampling) = DDTRACE_G(default_priority_sampling) = result->priority_sampling;
        } else {
            ZVAL_LONG(&zv, result->priority_sampling);
            ddtrace_assign_variable(&span->property_propagated_sampling_priority, &zv);

            ddtrace_set_priority_sampling_on_span(span, result->priority_sampling, DD_MECHANISM_DEFAULT);
        }
    }
}

bool ddtrace_read_zai_header(zai_str zai_header, const char *lowercase_header, zend_string **header_value, void *data) {
    UNUSED(lowercase_header, data);
    if (zai_read_header(zai_header, header_value) != ZAI_HEADER_SUCCESS) {
        return false;
    }
    *header_value = zend_string_copy(*header_value);
    return true;
}
