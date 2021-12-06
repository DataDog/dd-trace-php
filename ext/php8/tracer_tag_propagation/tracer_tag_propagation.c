#include "tracer_tag_propagation.h"

#include <Zend/zend_smart_str.h>

#include "../compat_string.h"
#include "../configuration.h"
#include "../ddtrace.h"
#include "../logging.h"
#include "../priority_sampling/priority_sampling.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_add_tracer_tags_from_header(zend_string *headerstr) {
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
            tagstart = ++header;
        }
    }
}

zend_string *ddtrace_format_propagated_tags(void) {
    // we propagate all tags on the current root span which were originally propagated, including the explicitly
    // defined tags here
    zend_hash_str_add_empty_element(&DDTRACE_G(propagated_root_span_tags), ZEND_STRL("_dd.p.upstream_services"));

    zend_array *tags = &DDTRACE_G(root_span_tags_preset);
    if (DDTRACE_G(root_span)) {
        zval *meta = ddtrace_spandata_property_meta(&DDTRACE_G(root_span)->span);
        ZVAL_DEREF(meta);
        if (Z_TYPE_P(meta) == IS_ARRAY) {
            tags = Z_ARRVAL_P(meta);
        }
    }

    smart_str taglist = {0};

    zend_string *tagname;
    ZEND_HASH_FOREACH_STR_KEY(&DDTRACE_G(propagated_root_span_tags), tagname) {
        zval *tag = zend_hash_find(tags, tagname);
        if (tag) {
            zend_string *str = ddtrace_convert_to_str(tag);

            if ((taglist.s ? ZSTR_LEN(taglist.s) : 0) + ZSTR_LEN(tagname) + ZSTR_LEN(str) + 2 <=
                (size_t)get_DD_TRACE_MAX_PROPAGATED_TAGS_LENGTH()) {
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
                    ZSTR_VAL(tagname), ZSTR_LEN(str), ZSTR_VAL(str), get_DD_TRACE_MAX_PROPAGATED_TAGS_LENGTH());
            }

            zend_string_release(str);
        }
    }
    ZEND_HASH_FOREACH_END();

    smart_str_0(&taglist);
    return taglist.s;
}
