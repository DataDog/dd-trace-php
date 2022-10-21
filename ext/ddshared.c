#include "ddshared.h"

#include <components/rust/ddtrace.h>

#include "ddtrace.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddshared_minit(void) {
    ddtrace_set_container_cgroup_path((ddog_CharSlice){ .ptr = DDTRACE_G(cgroup_file), .len = strlen(DDTRACE_G(cgroup_file)) });
}

const char *ddshared_container_id(void) {
    ddog_CharSlice id = ddtrace_get_container_id();
    if (id.len) {
        return id.ptr;
    }
    return "";
}
