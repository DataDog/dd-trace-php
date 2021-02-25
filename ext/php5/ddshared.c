#include "ddshared.h"

#include "datadog/container_id.h"
#include "datadog/string.h"
#include "ddtrace.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

datadog_string *dd_container_id = NULL;

void ddshared_minit(TSRMLS_D) {
    char *cgroup_file = NULL;
    if (DDTRACE_G(test_cgroup_file) && DDTRACE_G(test_cgroup_file)[0]) {
        cgroup_file = DDTRACE_G(test_cgroup_file);
        ddtrace_log_debugf("Using test cgroup file: %s", cgroup_file);
    }
    dd_container_id = datadog_container_id(cgroup_file);
}

void ddshared_mshutdown(void) {
    if (dd_container_id) {
        datadog_string_free(dd_container_id);
    }
}

datadog_string *ddshared_container_id(void) { return dd_container_id; }
