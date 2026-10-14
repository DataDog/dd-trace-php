#include "data_streams.h"

#include "configuration.h"
#include <components-rs/datadog.h>
#include <ext/datadog.h>
#include <ext/ffi_utils.h>
#include <ext/process_tags.h>
#include <ext/target_metadata.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

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
    ddog_DsmIdentity identity = {
        .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
        .version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
        .service = dd_zend_string_to_CharSlice(default_service ? default_service : service),
        .process_tags = dd_zend_string_to_CharSlice(datadog_process_tags_get_serialized()),
        .container_tags_hash = container_tags_hash ? dd_zend_string_to_CharSlice(container_tags_hash) : DDOG_CHARSLICE_C(""),
    };

    uint8_t context[ddog_DSM_CONTEXT_MAX_LEN];
    size_t context_len = ddog_dsm_set_checkpoint(DATADOG_G(sidecar), &identity, tags, tag_count,
                                                 carrier ? dd_zend_string_to_CharSlice(carrier) : DDOG_CHARSLICE_C(""),
                                                 0, context, sizeof(context));
    efree(tags);
    if (default_service) {
        zend_string_release(default_service);
    }

    return context_len ? zend_string_init((const char *)context, context_len, 0) : NULL;
}

void ddtrace_dsm_rshutdown(void) {
    ddog_dsm_reset_pathway();
}
