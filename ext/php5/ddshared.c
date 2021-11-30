#include "ddshared.h"

#include <components/container_id/container_id.h>
#include "ddtrace.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static char dd_container_id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];

void ddshared_minit(TSRMLS_D) {
    if (!datadog_php_container_id_from_file(dd_container_id, DDTRACE_G(cgroup_file))) {
        ddtrace_log_debugf("Failed to parse cgroup file '%s'.", DDTRACE_G(cgroup_file));
    }
}

char *ddshared_container_id(void) { return dd_container_id; }
