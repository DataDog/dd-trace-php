#include "tracer_tag_propagation.h"

#include <Zend/zend_smart_str.h>

#include "../compat_string.h"
#include "../configuration.h"
#include "../ddtrace.h"
#include "../logging.h"
#include "../priority_sampling/priority_sampling.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void dd_clean_old_tags() {
    zend_string *tagname;
    ZEND_HASH_FOREACH_STR_KEY(&DDTRACE_G(propagated_root_span_tags), tagname) {
        zend_hash_del(&DDTRACE_G(root_span_tags_preset), tagname);
    }
    ZEND_HASH_FOREACH_END();
    zend_hash_clean(&DDTRACE_G(propagated_root_span_tags));
}

void ddtrace_add_tracer_tags_from_header(zend_string *headerstr) {
    dd_clean_old_tags();

    char *header = ZSTR_VAL(headerstr), *headerend = header + ZSTR_LEN(headerstr);

    for (char *tagstart = header; header < headerend; ++header) {
        if (*header == '=') {
            zend_string *tag_name = zend_string_init(tagstart, header - tagstart, 0);
            char *valuestart = ++header;

            while (header < headerend && *header != ',') {
                ++header;
            }

            zval zv;
            ZVAL_STRINGL(&zv, valuestart, header - valuestart);
            zend_hash_update(&DDTRACE_G(root_span_tags_preset), tag_name, &zv);
            zend_hash_add_empty_element(&DDTRACE_G(propagated_root_span_tags), tag_name);
            zend_string_release(tag_name);
        }
        // we skip invalid tags without = within
        if (*header == ',') {
            ddtrace_log_debugf("Found x-datadog-tags header without key-separating equals character; raw input: %.*s",
                               ZSTR_LEN(headerstr), ZSTR_VAL(headerstr));
            tagstart = ++header;
        }
    }
}

void ddtrace_add_tracer_tags_from_array(zend_array *array) {
    dd_clean_old_tags();

    zend_string *tagname;
    zval *tag;
    ZEND_HASH_FOREACH_STR_KEY_VAL(array, tagname, tag) {
        if (tagname) {
            zval tagstr;
            ddtrace_convert_to_string(&tagstr, tag);
            zend_hash_update(&DDTRACE_G(root_span_tags_preset), tagname, &tagstr);
            zend_hash_add_empty_element(&DDTRACE_G(propagated_root_span_tags), tagname);
        }
    }
    ZEND_HASH_FOREACH_END();
}

zval ddtrace_get_propagated_tags(void) {
    zval zv;
    array_init(&zv);
    zend_string *tagname;
    ZEND_HASH_FOREACH_STR_KEY(&DDTRACE_G(propagated_root_span_tags), tagname) {
        zval *tag;
        if ((tag = zend_hash_find(&DDTRACE_G(root_span_tags_preset), tagname))) {
            Z_TRY_ADDREF_P(tag);
            zend_hash_add(Z_ARR(zv), tagname, tag);
        }
    }
    ZEND_HASH_FOREACH_END();
    return zv;
}

zend_string *ddtrace_format_propagated_tags(void) {
    // we propagate all tags on the current root span which were originally propagated, including the explicitly
    // defined tags here
    zend_hash_str_add_empty_element(&DDTRACE_G(propagated_root_span_tags), ZEND_STRL("_dd.p.upstream_services"));

    zend_array *tags = &DDTRACE_G(root_span_tags_preset);
    if (DDTRACE_G(root_span)) {
        tags = ddtrace_spandata_property_meta(&DDTRACE_G(root_span)->span);
    }

    smart_str taglist = {0};

    zend_string *tagname;
    ZEND_HASH_FOREACH_STR_KEY(&DDTRACE_G(propagated_root_span_tags), tagname) {
        zval *tag = zend_hash_find(tags, tagname), error_zv = {0};
        if (tag) {
            zend_string *str = ddtrace_convert_to_str(tag);

            for (char *cur = ZSTR_VAL(tagname), *end = cur + ZSTR_LEN(tagname); cur < end; ++cur) {
                if (*cur < 0x20 || *cur > 0x7E || *cur == '=' || *cur == ',') {
                    ddtrace_log_errf("The to be propagated tag name '%s' is invalid and is thus dropped.",
                                     ZSTR_VAL(tagname));
                    ZVAL_STRING(&error_zv, "encoding_error");
                    goto error;
                }
            }

            for (char *cur = ZSTR_VAL(str), *end = cur + ZSTR_LEN(str); cur < end; ++cur) {
                if (*cur < 0x20 || *cur > 0x7E || *cur == ',') {
                    ddtrace_log_errf("The to be propagated tag '%s=%.*s' value is invalid and is thus dropped.",
                                     ZSTR_VAL(tagname), ZSTR_LEN(str), ZSTR_VAL(str));
                    ZVAL_STRING(&error_zv, "encoding_error");
                    goto error;
                }
            }

            if ((taglist.s ? ZSTR_LEN(taglist.s) : 0) + ZSTR_LEN(tagname) + ZSTR_LEN(str) + 2 <=
                (size_t)get_DD_TRACE_TAGS_PROPAGATION_MAX_LENGTH()) {
                if (taglist.s) {
                    smart_str_appendc(&taglist, ',');
                }
                smart_str_append(&taglist, tagname);
                smart_str_appendc(&taglist, '=');
                smart_str_append(&taglist, str);
            } else {
                ddtrace_log_errf(
                    "The to be propagated tag '%s=%.*s' is too long and exceeds the maximum limit of " ZEND_LONG_FMT
                    " characters and is thus dropped.",
                    ZSTR_VAL(tagname), ZSTR_LEN(str), ZSTR_VAL(str), get_DD_TRACE_TAGS_PROPAGATION_MAX_LENGTH());
                ZVAL_STRING(&error_zv, "max_size");
            }

        error:
            zend_string_release(str);

            if (!Z_ISUNDEF(error_zv)) {
                zend_hash_str_update(tags, "_dd.propagation_error", sizeof("_dd.propagation_error") - 1, &error_zv);
                smart_str_free(&taglist);
                return NULL;
            }
        }
    }
    ZEND_HASH_FOREACH_END();

    smart_str_0(&taglist);
    return taglist.s;
}
