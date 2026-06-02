#include <Zend/zend_string.h>
#include "datadog.h"
#include "configuration.h"

zend_string *datadog_default_service_name(void);
void datadog_populate_target_data_with_defaults(ddtrace_span_data *span, zend_string **service, zend_string **env, zend_string **version, zend_string *cfg_service, zend_string *cfg_env, zend_string *cfg_version);

static inline void datadog_populate_target_data(ddtrace_span_data *span, zend_string **service, zend_string **env, zend_string **version) {
    datadog_populate_target_data_with_defaults(span, service, env, version, get_DD_SERVICE(), get_DD_ENV(), get_DD_VERSION());
}
