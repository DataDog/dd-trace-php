#include "context_discovery.h"

#include <string.h>

#ifdef _WIN32
#include <windows.h>
#else
#include <dlfcn.h>
#endif

#if !defined(__linux__)
typedef ddog_php_process_ctx_mapping (*ddog_php_process_ctx_fn)(void);
typedef void **(*ddog_php_thread_ctx_fn)(void);

static void *ddog_php_context_symbol(void *module_handle, const char *name) {
#ifdef _WIN32
    return module_handle ? (void *)GetProcAddress((HMODULE)module_handle, name) : NULL;
#else
    return module_handle ? dlsym(module_handle, name) : NULL;
#endif
}
#endif

void *ddog_php_context_discovery_resolve_tls(void *symbol) {
#ifdef __APPLE__
    typedef struct {
        void *(*thunk)(void *descriptor);
        unsigned long key;
        unsigned long offset;
    } ddog_php_tls_descriptor;

    ddog_php_tls_descriptor *descriptor = symbol;
    return descriptor && descriptor->thunk ? descriptor->thunk(descriptor) : NULL;
#else
    return symbol;
#endif
}

#if !defined(__linux__)
void ddog_php_context_discovery_reset(ddog_php_context_discovery *discovery) {
    if (discovery) {
        memset(discovery, 0, sizeof(*discovery));
    }
}

bool ddog_php_context_discovery_resolve_ddtrace(ddog_php_context_discovery *discovery, void *module_handle) {
    if (!discovery || !module_handle) {
        return false;
    }
    discovery->process_ctx = (ddog_php_process_ctx_fn)(uintptr_t)ddog_php_context_symbol(module_handle, "ddog_process_ctx_v1");
    discovery->thread_ctx = (ddog_php_thread_ctx_fn)(uintptr_t)ddog_php_context_symbol(module_handle, "ddog_thread_ctx_v1");
    return discovery->process_ctx || discovery->thread_ctx;
}

ddog_php_process_ctx_mapping ddog_php_context_discovery_process_mapping(const ddog_php_context_discovery *discovery) {
    ddog_php_process_ctx_mapping unavailable = {0};
    return discovery && discovery->process_ctx ? discovery->process_ctx() : unavailable;
}

void **ddog_php_context_discovery_thread_slot(const ddog_php_context_discovery *discovery) {
    return discovery && discovery->thread_ctx ? discovery->thread_ctx() : NULL;
}
#endif

void **ddog_php_context_discovery_otel_thread_slot(void) {
#if defined(__linux__) || defined(__APPLE__)
    return (void **)ddog_php_context_discovery_resolve_tls(dlsym(RTLD_DEFAULT, "otel_thread_ctx_v1"));
#else
    return NULL;
#endif
}
