#include "ddshared.h"

#include "container_id/container_id.h"
#include "ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static char dd_container_id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];

void ddshared_minit(void) { datadog_php_container_id(dd_container_id, DDTRACE_G(cgroup_file)); }

char *ddshared_container_id(void) { return dd_container_id; }
