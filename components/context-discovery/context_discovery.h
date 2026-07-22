#ifndef DDOG_PHP_CONTEXT_DISCOVERY_H
#define DDOG_PHP_CONTEXT_DISCOVERY_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#if !defined(__linux__)
typedef struct {
    const uint8_t *address;
    uintptr_t length;
} ddog_php_process_ctx_mapping;

typedef struct {
    ddog_php_process_ctx_mapping (*process_ctx)(void);
    void **(*thread_ctx)(void);
} ddog_php_context_discovery;

void ddog_php_context_discovery_reset(ddog_php_context_discovery *discovery);
bool ddog_php_context_discovery_resolve_ddtrace(ddog_php_context_discovery *discovery, void *module_handle);
ddog_php_process_ctx_mapping ddog_php_context_discovery_process_mapping(const ddog_php_context_discovery *discovery);
void **ddog_php_context_discovery_thread_slot(const ddog_php_context_discovery *discovery);
#endif
void *ddog_php_context_discovery_resolve_tls(void *symbol);
void **ddog_php_context_discovery_otel_thread_slot(void);

#endif
