#ifndef DDTRACE_CONFIG_H
#define DDTRACE_CONFIG_H

#include "config/config.h"

// TODO Use existing X Macro in configuration.h
typedef enum {
    DDTRACE_CONFIG_DD_SERVICE,
    DDTRACE_CONFIG_DD_TAGS,
    DDTRACE_CONFIG_DD_TRACE_AGENT_PORT,
    DDTRACE_CONFIG_DD_TRACE_DEBUG,
    DDTRACE_CONFIG_DD_TRACE_SAMPLE_RATE,
} ddtrace_config_id;

void ddtrace_config_minit(int module_number);

#endif  // DDTRACE_CONFIG_H
