#include "data_streams.h"

#include "configuration.h"
#include <components-rs/datadog.h>
#include <ext/datadog.h>
#include <ext/ffi_utils.h>
#include <ext/process_tags.h>
#include <ext/target_metadata.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

// The string tags of `tags`, as slices into it; free with efree. Non-strings are skipped.
static ddog_CharSlice *dd_dsm_tag_slices(zend_array *tags, uint32_t *count) {
    *count = 0;
    ddog_CharSlice *slices = safe_emalloc(zend_hash_num_elements(tags), sizeof(ddog_CharSlice), 0);
    zval *tag;
    ZEND_HASH_FOREACH_VAL(tags, tag) {
        if (Z_TYPE_P(tag) == IS_STRING) {
            slices[(*count)++] = dd_zend_string_to_CharSlice(Z_STR_P(tag));
        }
    } ZEND_HASH_FOREACH_END();
    return slices;
}

// Fills `identity` from the configuration. Returns a service name to release once `identity` is
// no longer used, or NULL.
static zend_string *dd_dsm_identity(ddog_DsmIdentity *identity) {
    zend_string *service = get_DD_SERVICE();
    zend_string *default_service = ZSTR_LEN(service) ? NULL : datadog_default_service_name();
    zend_string *base_hash = datadog_process_tags_get_base_hash();
    *identity = (ddog_DsmIdentity){
        .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
        .version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
        .service = dd_zend_string_to_CharSlice(default_service ? default_service : service),
        .base_hash = base_hash ? dd_zend_string_to_CharSlice(base_hash) : DDOG_CHARSLICE_C(""),
    };
    return default_service;
}

zend_string *ddtrace_dsm_set_checkpoint(zend_array *edge_tags, zend_string *carrier, zend_long payload_size) {
    if (!get_DD_DATA_STREAMS_ENABLED()) {
        return NULL;
    }

    uint32_t tag_count;
    ddog_CharSlice *tags = dd_dsm_tag_slices(edge_tags, &tag_count);
    ddog_DsmIdentity identity;
    zend_string *default_service = dd_dsm_identity(&identity);

    uint8_t context[ddog_DSM_CONTEXT_MAX_LEN];
    size_t context_len = ddog_dsm_set_checkpoint(DATADOG_G(sidecar), &identity, tags, tag_count,
                                                 carrier ? dd_zend_string_to_CharSlice(carrier) : DDOG_CHARSLICE_C(""),
                                                 payload_size, context, sizeof(context));
    efree(tags);
    if (default_service) {
        zend_string_release(default_service);
    }

    return context_len ? zend_string_init((const char *)context, context_len, 0) : NULL;
}

void ddtrace_dsm_track_backlog(zend_array *backlog_tags, zend_long offset) {
    if (!get_DD_DATA_STREAMS_ENABLED()) {
        return;
    }

    uint32_t tag_count;
    ddog_CharSlice *tags = dd_dsm_tag_slices(backlog_tags, &tag_count);
    ddog_DsmIdentity identity;
    zend_string *default_service = dd_dsm_identity(&identity);

    ddog_dsm_track_backlog(DATADOG_G(sidecar), &identity, tags, tag_count, offset);
    efree(tags);
    if (default_service) {
        zend_string_release(default_service);
    }
}

void ddtrace_dsm_push_pathway(void) {
    ddog_dsm_push_pathway();
}

void ddtrace_dsm_pop_pathway(void) {
    ddog_dsm_pop_pathway();
}

void ddtrace_dsm_rshutdown(void) {
    ddog_dsm_reset_pathway();
}
