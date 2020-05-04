#ifndef DDTRACE_BLACKLIST_H
#define DDTRACE_BLACKLIST_H

#include <php.h>
#include <stdbool.h>

extern bool ddtrace_has_blacklisted_module;

void ddtrace_blacklist_startup();

#endif  // DDTRACE_BLACKLIST_H
