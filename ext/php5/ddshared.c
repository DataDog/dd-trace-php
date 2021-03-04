#include "ddshared.h"

#include "datadog/container_id.h"
#include "ddtrace.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

char dd_container_id[DATADOG_CONTAINER_ID_LEN + 1];

void ddshared_minit(TSRMLS_D) {
    datadog_container_id(dd_container_id, DDTRACE_G(cgroup_file));
}

char *ddshared_container_id(void) { return dd_container_id; }
