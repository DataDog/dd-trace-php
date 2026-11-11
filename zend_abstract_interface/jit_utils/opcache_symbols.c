#include "../tsrmls_cache.h"
#include "opcache_symbols.h"

#include "zend_extensions.h"
#include <Zend/zend_portability.h>
#include <Zend/zend_types.h>
#include <stdio.h>
#include <string.h>
#ifndef _WIN32
#include <dlfcn.h>
#endif

static void *opcache_handle;
static bool opcache_loaded;
static void zai_jit_find_opcache_handle(void *ext) {
    zend_extension *extension = (zend_extension *)ext;
    if (strcmp(extension->name, "Zend OPcache") == 0) {
        opcache_loaded = true;
        opcache_handle = extension->handle;
    }
}

/* Built-in OPcache registers an extension without a library handle. */
bool zai_jit_opcache_loaded(void) { return opcache_loaded; }

/* Save the handle in MINIT before OPcache startup clears it. */
void zai_jit_minit(void) {
    zend_llist_apply(&zend_extensions, zai_jit_find_opcache_handle);
}

/* Fall back to process exports when no library handle is available. */
void *zai_jit_fetch_opcache_symbol(const char *name) {
    void *handle = opcache_handle;
    if (!handle) {
#ifdef _WIN32
        /* Shared OPcache exports from its own DLL; built-in OPcache exports from the PHP DLL containing Zend. */
        handle = GetModuleHandle("php_opcache");
        if (!handle) {
            HMODULE php_handle = NULL;
            if (!GetModuleHandleExA(GET_MODULE_HANDLE_EX_FLAG_FROM_ADDRESS | GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT,
                                   (LPCSTR)zend_get_extension, &php_handle)) {
                return NULL;
            }
            handle = php_handle;
        }
#else
        handle = RTLD_DEFAULT;
#endif
    }
    void *sym = (void *)DL_FETCH_SYMBOL(handle, name);
    if (!sym) {
        char underscored[64];
        snprintf(underscored, sizeof underscored, "_%s", name);
        sym = (void *)DL_FETCH_SYMBOL(handle, underscored);
    }
    return sym;
}
