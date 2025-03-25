#include "tracer_tag_propagation.h"

#include <Zend/zend_smart_str.h>

#include "../compat_string.h"
#include "../configuration.h"
#include "../ddtrace.h"
#include "../trace_source.h"
#include "../priority_sampling/priority_sampling.h"
#include <components/log/log.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_clean_tracer_tags(zend_array *root_meta, zend_array *propagated_tags) {
    zend_string *tagname;
    ZEND_HASH_FOREACH_STR_KEY(propagated_tags, tagname) {
        zend_hash_del(root_meta, tagname);
    } ZEND_HASH_FOREACH_END();
    zend_hash_clean(propagated_tags);
}

void ddtrace_add_tracer_tags_from_header(zend_string *headerstr, zend_array *root_meta, zend_array *propagated_tags) {
    ddtrace_clean_tracer_tags(root_meta, propagated_tags);

    char *header = ZSTR_VAL(headerstr), *headerend = header + ZSTR_LEN(headerstr);

    if (ZSTR_LEN(headerstr) > 512) {
        zval error_zv;
        ZVAL_STRING(&error_zv, "extract_max_size");
        zend_hash_str_update(root_meta, ZEND_STRL("_dd.propagation_error"), &error_zv);
        return;
    }

    for (char *tagstart = header; header < headerend; ++header) {
        if (*header == '=') {
            zend_string *tag_name = zend_string_init(tagstart, header - tagstart, 0);
            char *valuestart = ++header;

            while (header < headerend && *header != ',') {
                ++header;
            }

            // tags not starting with _dd.p. must not be propagated to prevent information leaks or arbitrary
            // information injection
            if (ZSTR_LEN(tag_name) >= sizeof("_dd.p.") &&
                strncmp(ZSTR_VAL(tag_name), "_dd.p.", sizeof("_dd.p.") - 1) == 0) {
                zval zv;
                bool add = true;
                ZVAL_STRINGL(&zv, valuestart, header - valuestart);
                if (strncmp(ZSTR_VAL(tag_name), DD_P_TS_KEY, sizeof(DD_P_TS_KEY) - 1) == 0) {
                    add = ddtrace_trace_source_set_from_hexadecimal(Z_STR_P(&zv));                    
                }
                if (add) {
                    zend_hash_update(root_meta, tag_name, &zv);
                    zend_hash_add_empty_element(propagated_tags, tag_name);
                } else {
                    zval_ptr_dtor(&zv);
                }
            }
            zend_string_release(tag_name);

            tagstart = ++header;
        } else if (*header == ',') {
            // we skip invalid tags without = within
            LOG(WARN, "Found x-datadog-tags header without key-separating equals character; raw input: %s", ZSTR_VAL(headerstr));
            tagstart = ++header;

            zval error_zv;
            ZVAL_STRING(&error_zv, "decoding_error");
            zend_hash_str_update(root_meta, ZEND_STRL("_dd.propagation_error"), &error_zv);
        }
    }
}

void ddtrace_add_tracer_tags_from_array(zend_array *array, zend_array *root_meta, zend_array *propagated_tags) {
    ddtrace_clean_tracer_tags(root_meta, propagated_tags);

    zend_string *tagname;
    zval *tag;
    ZEND_HASH_FOREACH_STR_KEY_VAL(array, tagname, tag) {
        if (tagname) {
            zval tagstr;
            ddtrace_convert_to_string(&tagstr, tag);
            zend_hash_update(root_meta, tagname, &tagstr);
            zend_hash_add_empty_element(propagated_tags, tagname);
        }
    }
    ZEND_HASH_FOREACH_END();
}

static zend_array *ddtrace_get_propagated() {
    zend_array *propagated = &DDTRACE_G(propagated_root_span_tags);
    ddtrace_root_span_data *root_span = DDTRACE_G(active_stack)->root_span;
    if (root_span) {
        propagated = ddtrace_property_array(&root_span->property_propagated_tags);
    }

    return propagated;
}

static zend_array *ddtrace_get_root_meta() {
    zend_array *root_meta = &DDTRACE_G(root_span_tags_preset);
    ddtrace_root_span_data *root_span = DDTRACE_G(active_stack)->root_span;
    if (root_span) {
        root_meta = ddtrace_property_array(&root_span->property_meta);
    }

    return root_meta;
}

zval *ddtrace_propagated_tags_get_tag(const char *tag) {
    if (!tag) {
        return NULL;
    }
    zend_array *propagated = ddtrace_get_propagated();
    zend_array *root_meta = ddtrace_get_root_meta();
    size_t tag_len = strlen(tag);

    if (!zend_hash_str_find(propagated, tag, tag_len)) {
        return NULL;
    }

    return zend_hash_str_find(root_meta, tag, tag_len);
}

void ddtrace_get_propagated_tags(zend_array *tags) {
    zend_array *propagated = ddtrace_get_propagated();
    zend_array *root_meta = ddtrace_get_root_meta();

    zend_string *tagname;
    ZEND_HASH_FOREACH_STR_KEY(propagated, tagname) {
        zval *tag;
        if ((tag = zend_hash_find(root_meta, tagname))) {
            Z_TRY_ADDREF_P(tag);
            zend_hash_update(tags, tagname, tag);
        }
    }
    ZEND_HASH_FOREACH_END();
}

zend_string *ddtrace_format_root_propagated_tags(void) {
    zend_array *propagated = ddtrace_get_propagated();
    zend_array *root_meta = ddtrace_get_root_meta();

    return ddtrace_format_propagated_tags(propagated, root_meta);
}

zend_string *ddtrace_format_propagated_tags(zend_array *propagated, zend_array *tags) {
    // we propagate all tags on the current root span which were originally propagated, including the explicitly
    // defined tags here
    zend_hash_str_del(propagated, ZEND_STRL("_dd.p.upstream_services"));

    smart_str taglist = {0};

    ddtrace_trace_id trace_id = ddtrace_peek_trace_id();
    if (trace_id.high) {
        smart_str_append_printf(&taglist, "_dd.p.tid=%016" PRIx64, trace_id.high);
    }

    zend_string *tagname;
    ZEND_HASH_FOREACH_STR_KEY(propagated, tagname) {
        zval *tag = zend_hash_find(tags, tagname), error_zv = {0};
        if (tag) {
            zend_string *str = ddtrace_convert_to_str(tag);

            for (char *cur = ZSTR_VAL(tagname), *end = cur + ZSTR_LEN(tagname); cur < end; ++cur) {
                if (*cur < 0x20 || *cur > 0x7E || *cur == '=' || *cur == ',') {
                    LOG(ERROR, "The to be propagated tag name '%s' is invalid and is thus dropped.",
                                     ZSTR_VAL(tagname));
                    ZVAL_STRING(&error_zv, "encoding_error");
                    goto error;
                }
            }

            for (char *cur = ZSTR_VAL(str), *end = cur + ZSTR_LEN(str); cur < end; ++cur) {
                if (*cur < 0x20 || *cur > 0x7E || *cur == ',') {
                    LOG(ERROR, "The to be propagated tag '%s=%s' value is invalid and is thus dropped.",
                                     ZSTR_VAL(tagname), ZSTR_VAL(str));
                    ZVAL_STRING(&error_zv, "encoding_error");
                    goto error;
                }
            }

            if ((taglist.s ? ZSTR_LEN(taglist.s) : 0) + ZSTR_LEN(tagname) + ZSTR_LEN(str) + 2 <=
                (size_t)get_DD_TRACE_X_DATADOG_TAGS_MAX_LENGTH()) {
                if (taglist.s) {
                    smart_str_appendc(&taglist, ',');
                }
                smart_str_append(&taglist, tagname);
                smart_str_appendc(&taglist, '=');
                smart_str_append(&taglist, str);
            } else if (get_DD_TRACE_X_DATADOG_TAGS_MAX_LENGTH()) {
                LOG(ERROR,
                    "The to be propagated tag '%s=%s' is too long and exceeds the maximum limit of " ZEND_LONG_FMT
                    " characters and is thus dropped.",
                    ZSTR_VAL(tagname), ZSTR_VAL(str), get_DD_TRACE_X_DATADOG_TAGS_MAX_LENGTH());
                ZVAL_STRING(&error_zv, "inject_max_size");
            } else {
                ZVAL_STRING(&error_zv, "disabled");
            }

        error:
            zend_string_release(str);

            if (!Z_ISUNDEF(error_zv)) {
                zend_hash_str_update(tags, ZEND_STRL("_dd.propagation_error"), &error_zv);
                smart_str_free(&taglist);
                return NULL;
            }
        }
    }
    ZEND_HASH_FOREACH_END();

    smart_str_0(&taglist);
    return taglist.s;
}

void ddtrace_add_propagated_tag(zend_string *key, zval *value) {
    zend_array *propagated = ddtrace_get_propagated();
    zend_array *root_meta = ddtrace_get_root_meta();

    zval tagstr;
    ddtrace_convert_to_string(&tagstr, value);
    zend_hash_update(root_meta, key, &tagstr);
    zend_hash_add_empty_element(propagated, key);
}

DDTRACE_PUBLIC void ddtrace_add_propagated_tag_on_span_zobj(zend_string *key, zval *value) { ddtrace_add_propagated_tag(key, value); }
