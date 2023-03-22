#ifndef DDTRACE_PHP_H
#define DDTRACE_PHP_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <telemetry.h>
#include <common.h>


typedef uint64_t ddog_QueueId;

extern uint8_t ddtrace_runtime_id[16];

void ddtrace_generate_runtime_id(void);

void ddtrace_format_runtime_id(uint8_t *buf);

ddog_CharSlice ddtrace_get_container_id(void);

void ddtrace_set_container_cgroup_path(ddog_CharSlice path);

bool ddtrace_detect_composer_installed_json(ddog_TelemetryTransport **transport,
                                            const struct ddog_InstanceId *instance_id,
                                            const ddog_QueueId *queue_id,
                                            ddog_CharSlice path);

#endif /* DDTRACE_PHP_H */
