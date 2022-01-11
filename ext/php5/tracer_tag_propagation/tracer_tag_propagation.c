#include "tracer_tag_propagation.h"

#include <ext/standard/php_smart_str.h>

#include "../compat_string.h"
#include "../configuration.h"
#include "../ddtrace.h"
#include "../logging.h"
#include "../priority_sampling/priority_sampling.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_add_tracer_tags_from_header(zai_string_view *headerstr TSRMLS_DC) {
    char *header = (char *)headerstr->ptr, *headerend = header + headerstr->len;

    for (char *tagstart = header; header < headerend; ++header) {
        if (*header == '=') {
            zai_string_view tag_name = {header - tagstart, tagstart};
            char *valuestart = ++header;

            while (header < headerend && *header != ',') {
                ++header;
            }

            zval *zv;
            MAKE_STD_ZVAL(zv);
            Z_TYPE_P(zv) = IS_STRING;
            Z_STRLEN_P(zv) = header - valuestart;
            Z_STRVAL_P(zv) = emalloc(Z_STRLEN_P(zv) + 1);
            memcpy(Z_STRVAL_P(zv), valuestart, header - valuestart);
            Z_STRVAL_P(zv)[Z_STRLEN_P(zv)] = 0;

            char *key = emalloc(tag_name.len + 1);

            memcpy(key, tag_name.ptr, tag_name.len);

            key[tag_name.len] = 0;

            zend_hash_update(&DDTRACE_G(root_span_tags_preset), key, tag_name.len + 1, &zv, sizeof(zval *), NULL);
            zend_hash_add_empty_element(&DDTRACE_G(propagated_root_span_tags), key, tag_name.len + 1);
            efree(key);
        }
        // we skip invalid tags without = within
        if (*header == ',') {
            ddtrace_log_debugf("Found x-datadog-tags header without key-separating equals character; raw input: %.*s",
                               headerstr->len, headerstr->ptr);
            tagstart = ++header;
        }
    }
}

zai_string_view ddtrace_format_propagated_tags(TSRMLS_D) {
    // we propagate all tags on the current root span which were originally propagated, including the explicitly
    // defined tags here
    zend_hash_add_empty_element(&DDTRACE_G(propagated_root_span_tags), "_dd.p.upstream_services",
                                sizeof("_dd.p.upstream_services"));

    HashTable *tags = &DDTRACE_G(root_span_tags_preset);
    if (DDTRACE_G(root_span)) {
        tags = Z_ARRVAL_P(ddtrace_spandata_property_meta(&DDTRACE_G(root_span)->span));
    }

    smart_str taglist = {0};
    HashPosition pos;
    char *key;
    uint klen;
    ulong kidx;

    for (zend_hash_internal_pointer_reset_ex(&DDTRACE_G(propagated_root_span_tags), &pos);
         zend_hash_get_current_key_ex(&DDTRACE_G(propagated_root_span_tags), (char **)&key, (uint *)&klen, &kidx, 0,
                                      &pos) == HASH_KEY_IS_STRING;
         zend_hash_move_forward_ex(&DDTRACE_G(propagated_root_span_tags), &pos)) {
        zval **tag;

        if (zend_hash_find(tags, key, klen, (void **)&tag) == SUCCESS) {
            zval str;
            char *error = NULL;
            ddtrace_convert_to_string(&str, *tag TSRMLS_CC);

            for (char *cur = key, *end = cur + klen - 1; cur < end; ++cur) {
                if (*cur < 0x20 || *cur > 0x7E || *cur == '=' || *cur == ',') {
                    ddtrace_log_errf("The to be propagated tag name '%s' is invalid and is thus dropped.", key);
                    error = "encoding_error";
                    goto error;
                }
            }

            for (char *cur = Z_STRVAL(str), *end = cur + Z_STRLEN(str); cur < end; ++cur) {
                if (*cur < 0x20 || *cur > 0x7E || *cur == ',') {
                    ddtrace_log_errf("The to be propagated tag '%s=%.*s' value is invalid and is thus dropped.", key,
                                     Z_STRLEN(str), Z_STRVAL(str));
                    error = "encoding_error";
                    goto error;
                }
            }

            if ((taglist.c ? taglist.len : 0) + (klen - 1) + Z_STRLEN(str) + 2 <=
                (size_t)get_DD_TRACE_TAGS_PROPAGATION_MAX_LENGTH()) {
                if (taglist.c) {
                    smart_str_appendc(&taglist, ',');
                }
                smart_str_appendl(&taglist, key, klen - 1);
                smart_str_appendc(&taglist, '=');
                smart_str_appendl(&taglist, Z_STRVAL(str), Z_STRLEN(str));
            } else {
                ddtrace_log_errf(
                    "The to be propagated tag '%s=%.*s' is too long and exceeds the maximum limit of %ld characters and is thus dropped.",
                    key, Z_STRLEN(str), Z_STRVAL(str), get_DD_TRACE_TAGS_PROPAGATION_MAX_LENGTH());

                error = "max_size";
            }

        error:
            zval_dtor(&str);

            if (error) {
                zval *error_zv;
                MAKE_STD_ZVAL(error_zv);
                ZVAL_STRING(error_zv, error, 1);
                zend_hash_update(tags, "_dd.propagation_error", sizeof("_dd.propagation_error"), (void *)&error_zv,
                                 sizeof(zval *), NULL);
            }
        }
    }

    smart_str_0(&taglist);

    return (zai_string_view){taglist.len, taglist.c};
}
