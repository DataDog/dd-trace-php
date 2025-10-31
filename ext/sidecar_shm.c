#include "sidecar_shm.h"
#include "ddtrace.h"
#include "sidecar.h"
#include "threads.h"
#include <components-rs/sidecar.h>
#include <components/log/log.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

// Global registry for shared memory handles
static ddtrace_shm_registry_t *shm_registry = NULL;

// Helper function to generate key from (service, env)
static zend_string *ddtrace_shm_make_key(const char *service, const char *env) {
    if (!env || !*env) {
        env = "none";
    }
    return zend_strpprintf(0, "%s:%s", service, env);
}

// Initialize the SHM registry (called during MINIT)
void ddtrace_shm_registry_init(void) {
    if (shm_registry) {
        return; // Already initialized
    }

    shm_registry = (ddtrace_shm_registry_t *)malloc(sizeof(ddtrace_shm_registry_t));
    if (!shm_registry) {
        LOG(ERROR, "Failed to allocate memory for SHM registry");
        return;
    }

    shm_registry->shm_map = (HashTable *)malloc(sizeof(HashTable));
    zend_hash_init(shm_registry->shm_map, 8, NULL, NULL, 1);
    shm_registry->mutex = tsrm_mutex_alloc();

    LOG(DEBUG, "SHM registry initialized");
}

// Destroy the SHM registry (called during MSHUTDOWN)
void ddtrace_shm_registry_destroy(void) {
    if (!shm_registry) {
        return;
    }

    if (shm_registry->mutex) {
        tsrm_mutex_lock(shm_registry->mutex);
    }

    // Free all SHM entries
    if (shm_registry->shm_map) {
        ddtrace_shm_entry_t *entry;
        ZEND_HASH_FOREACH_PTR(shm_registry->shm_map, entry) {
            if (entry) {
                if (entry->service) {
                    free(entry->service);
                }
                if (entry->env) {
                    free(entry->env);
                }
                if (entry->handle) {
                    ddog_drop_anon_shm_handle(entry->handle);
                }
                free(entry);
            }
        } ZEND_HASH_FOREACH_END();

        zend_hash_destroy(shm_registry->shm_map);
        free(shm_registry->shm_map);
    }

    if (shm_registry->mutex) {
        tsrm_mutex_unlock(shm_registry->mutex);
        tsrm_mutex_free(shm_registry->mutex);
    }

    free(shm_registry);
    shm_registry = NULL;

    LOG(DEBUG, "SHM registry destroyed");
}

// Get or create anonymous SHM handle for a (service, env) tuple
ddog_ShmHandle *ddtrace_shm_get_or_create(const char *service, const char *env) {
    if (!shm_registry || !service) {
        return NULL;
    }

    zend_string *key = ddtrace_shm_make_key(service, env);
    ddog_ShmHandle *handle = NULL;

    tsrm_mutex_lock(shm_registry->mutex);

    // Check if entry already exists
    ddtrace_shm_entry_t *entry = zend_hash_find_ptr(shm_registry->shm_map, key);
    if (entry) {
        entry->ref_count++;
        handle = entry->handle;
        LOG(DEBUG, "SHM handle found for %s:%s (ref_count=%d)", service, env, entry->ref_count);
        tsrm_mutex_unlock(shm_registry->mutex);
        zend_string_release(key);
        return handle;
    }

    // Create new entry
    entry = (ddtrace_shm_entry_t *)malloc(sizeof(ddtrace_shm_entry_t));
    if (!entry) {
        LOG(ERROR, "Failed to allocate memory for SHM entry");
        tsrm_mutex_unlock(shm_registry->mutex);
        zend_string_release(key);
        return NULL;
    }

    entry->service = strdup(service);
    entry->env = env ? strdup(env) : strdup("none");
    entry->ref_count = 1;

    // Allocate anonymous shared memory
    ddog_MaybeError maybe_error = ddog_alloc_anon_shm_handle(128 * 1024, &entry->handle); // 128KB default
    if (maybe_error.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice error = ddog_Error_message(&maybe_error.some);
        LOG(ERROR, "Failed to allocate anonymous SHM for %s:%s: %.*s",
            service, env, (int)error.len, error.ptr);
        ddog_MaybeError_drop(maybe_error);

        free(entry->service);
        free(entry->env);
        free(entry);
        tsrm_mutex_unlock(shm_registry->mutex);
        zend_string_release(key);
        return NULL;
    }

    handle = entry->handle;

    // Add to hash table
    zend_hash_add_new_ptr(shm_registry->shm_map, key, entry);

    LOG(DEBUG, "Created new SHM handle for %s:%s (ref_count=1)", service, env);

    tsrm_mutex_unlock(shm_registry->mutex);
    zend_string_release(key);

    return handle;
}

// Increment reference count for a SHM handle
void ddtrace_shm_addref(const char *service, const char *env) {
    if (!shm_registry || !service) {
        return;
    }

    zend_string *key = ddtrace_shm_make_key(service, env);

    tsrm_mutex_lock(shm_registry->mutex);

    ddtrace_shm_entry_t *entry = zend_hash_find_ptr(shm_registry->shm_map, key);
    if (entry) {
        entry->ref_count++;
        LOG(DEBUG, "SHM addref for %s:%s (ref_count=%d)", service, env, entry->ref_count);
    } else {
        LOG(WARN, "Attempted to addref non-existent SHM entry for %s:%s", service, env);
    }

    tsrm_mutex_unlock(shm_registry->mutex);
    zend_string_release(key);
}

// Decrement reference count and free if zero
void ddtrace_shm_release(const char *service, const char *env) {
    if (!shm_registry || !service) {
        return;
    }

    zend_string *key = ddtrace_shm_make_key(service, env);

    tsrm_mutex_lock(shm_registry->mutex);

    ddtrace_shm_entry_t *entry = zend_hash_find_ptr(shm_registry->shm_map, key);
    if (entry) {
        entry->ref_count--;
        LOG(DEBUG, "SHM release for %s:%s (ref_count=%d)", service, env, entry->ref_count);

        if (entry->ref_count <= 0) {
            // Free the entry
            LOG(DEBUG, "Freeing SHM handle for %s:%s", service, env);

            if (entry->handle) {
                ddog_drop_anon_shm_handle(entry->handle);
            }
            if (entry->service) {
                free(entry->service);
            }
            if (entry->env) {
                free(entry->env);
            }

            zend_hash_del(shm_registry->shm_map, key);
            free(entry);
        }
    } else {
        LOG(WARN, "Attempted to release non-existent SHM entry for %s:%s", service, env);
    }

    tsrm_mutex_unlock(shm_registry->mutex);
    zend_string_release(key);
}
