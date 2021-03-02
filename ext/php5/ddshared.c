#include "ddshared.h"

#include "datadog/container_id.h"
#include "datadog/string.h"
#include "ddtrace.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

datadog_string *dd_container_id = NULL;

void ddshared_minit(TSRMLS_D) {
    if (DDTRACE_G(cgroup_file) && DDTRACE_G(cgroup_file)[0]) {
        dd_container_id = datadog_container_id(DDTRACE_G(cgroup_file));
    }
}

void ddshared_mshutdown(void) {
    if (dd_container_id) {
        datadog_string_free(dd_container_id);
    }
}

datadog_string *ddshared_container_id(void) { return dd_container_id; }
