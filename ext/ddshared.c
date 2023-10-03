#include <components-rs/ddtrace.h>
#include <Zend/zend_API.h>

#include "ddshared.h"
#include "ddtrace.h"
#include <components/log/log.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

zend_string *ddtrace_php_version;

void ddshared_minit(void) {
    ddtrace_set_container_cgroup_path((ddog_CharSlice){ .ptr = DDTRACE_G(cgroup_file), .len = strlen(DDTRACE_G(cgroup_file)) });

    ddtrace_php_version = Z_STR_P(zend_get_constant_str(ZEND_STRL("PHP_VERSION")));
}
