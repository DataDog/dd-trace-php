#ifndef DDTRACE_PHP_H
#define DDTRACE_PHP_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include "common.h"

ddtrace_CharSlice ddtrace_get_container_id(void);

void ddtrace_set_container_cgroup_path(ddtrace_CharSlice path);

#endif /* DDTRACE_PHP_H */
