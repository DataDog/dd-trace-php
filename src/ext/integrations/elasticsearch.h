#ifndef DD_INTEGRATIONS_ELASTICSEARCH_H
#define DD_INTEGRATIONS_ELASTICSEARCH_H
#include "configuration.h"
#include "integrations.h"

#define _DD_AL_ES(class, method)                       \
    DDTRACE_DEFERRED_INTEGRATION_LOADER(class, method, \
                                        "DDTrace\\Integrations\\ElasticSearch\\V1\\ElasticSearchIntegration")

static inline void _dd_es_initialize_deferred_integration(TSRMLS_D) {
    if (!ddtrace_config_integration_enabled_ex(DDTRACE_INTEGRATION_ELASTICSEARCH TSRMLS_CC)) {
        return;
    }

    _DD_AL_ES("elasticsearch\\client", "__construct");
}

#endif
