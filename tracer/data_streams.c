#include "data_streams.h"

#include "configuration.h"
#include <components-rs/datadog.h>
#include <ext/datadog.h>
#include <Zend/zend_smart_str.h>
#include <ext/ffi_utils.h>
#include <ext/process_tags.h>
#include <ext/target_metadata.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

/**
 * The process tags to hash: the same list the sidecar reports in the DSM payload, which is
 * these process tags followed by the service-source tag its `process_tags_with_svc_source` adds.
 * Neither is sent to the sidecar while process tags are disabled.
 */
static zend_string *dd_dsm_process_tags(zend_string *service) {
    if (!datadog_process_tags_enabled()) {
        return NULL;
    }

    smart_str tags = {0};
    smart_str_append(&tags, datadog_process_tags_get_serialized());
    if (ZSTR_LEN(service)) {
        smart_str_appends(&tags, ",svc.user:true");
    } else {
        zend_string *default_service = datadog_default_service_name();
        const char *normalized = ddog_normalize_process_tag_value(dd_zend_string_to_CharSlice(default_service));
        zend_string_release(default_service);
        if (normalized) {
            if (*normalized) {
                smart_str_appends(&tags, ",svc.auto:");
                smart_str_appends(&tags, normalized);
            }
            ddog_free_normalized_tag_value(normalized);
        }
    }
    smart_str_0(&tags);
    return tags.s;
}

zend_string *ddtrace_dsm_set_checkpoint(zend_array *edge_tags, zend_string *carrier) {
    if (!get_DD_DATA_STREAMS_ENABLED()) {
        return NULL;
    }

    uint32_t tag_count = 0;
    ddog_CharSlice *tags = safe_emalloc(zend_hash_num_elements(edge_tags), sizeof(ddog_CharSlice), 0);
    zval *tag;
    ZEND_HASH_FOREACH_VAL(edge_tags, tag) {
        if (Z_TYPE_P(tag) == IS_STRING) {
            tags[tag_count++] = dd_zend_string_to_CharSlice(Z_STR_P(tag));
        }
    } ZEND_HASH_FOREACH_END();

    zend_string *service = get_DD_SERVICE();
    zend_string *default_service = ZSTR_LEN(service) ? NULL : datadog_default_service_name();
    zend_string *container_tags_hash = datadog_process_tags_get_container_tags_hash();
    zend_string *process_tags = dd_dsm_process_tags(service);
    ddog_DsmIdentity identity = {
        .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
        .version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
        .service = dd_zend_string_to_CharSlice(default_service ? default_service : service),
        .process_tags = process_tags ? dd_zend_string_to_CharSlice(process_tags) : DDOG_CHARSLICE_C(""),
        .container_tags_hash = container_tags_hash ? dd_zend_string_to_CharSlice(container_tags_hash) : DDOG_CHARSLICE_C(""),
    };

    uint8_t context[ddog_DSM_CONTEXT_MAX_LEN];
    size_t context_len = ddog_dsm_set_checkpoint(DATADOG_G(sidecar), &identity, tags, tag_count,
                                                 carrier ? dd_zend_string_to_CharSlice(carrier) : DDOG_CHARSLICE_C(""),
                                                 0, context, sizeof(context));
    efree(tags);
    if (process_tags) {
        zend_string_release(process_tags);
    }
    if (default_service) {
        zend_string_release(default_service);
    }

    return context_len ? zend_string_init((const char *)context, context_len, 0) : NULL;
}

void ddtrace_dsm_rshutdown(void) {
    ddog_dsm_reset_pathway();
}
