#ifndef DD_SIDECAR_SHM_H
#define DD_SIDECAR_SHM_H

#include <Zend/zend.h>
#include <Zend/zend_hash.h>
#include <components-rs/sidecar.h>
#include "threads.h"

// Entry for tracking shared memory per (service, env) tuple
typedef struct {
    char *service;
    char *env;
    ddog_ShmHandle *handle;
    int ref_count;
} ddtrace_shm_entry_t;

// Global registry for shared memory handles
typedef struct {
    HashTable *shm_map;  // Maps "service:env" -> ddtrace_shm_entry_t
    MUTEX_T mutex;       // Protects the hash table
} ddtrace_shm_registry_t;

// Initialize the SHM registry (called during MINIT)
void ddtrace_shm_registry_init(void);

// Destroy the SHM registry (called during MSHUTDOWN)
void ddtrace_shm_registry_destroy(void);

// Get or create anonymous SHM handle for a (service, env) tuple
// Returns NULL on failure
ddog_ShmHandle *ddtrace_shm_get_or_create(const char *service, const char *env);

// Increment reference count for a SHM handle
void ddtrace_shm_addref(const char *service, const char *env);

// Decrement reference count and free if zero
void ddtrace_shm_release(const char *service, const char *env);

#endif // DD_SIDECAR_SHM_H
