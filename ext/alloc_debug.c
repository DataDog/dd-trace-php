#include <main/php_version.h>
#include <zend.h>
#include <sys/mman.h>
#include <stdatomic.h>

#include "alloc_debug.h"
#include "components/log/log.h"

// Make it compile under macos, but not recommended for large programs
#ifndef MAP_FIXED_NOREPLACE
#define MAP_FIXED_NOREPLACE MAP_FIXED
#endif

#if PHP_VERSION_ID < 80400
#undef ZEND_FILE_LINE_RELAY_CC
#undef ZEND_FILE_LINE_ORIG_RELAY_CC
#undef ZEND_FILE_LINE_DC
#undef ZEND_FILE_LINE_ORIG_DC
#define ZEND_FILE_LINE_RELAY_CC
#define ZEND_FILE_LINE_ORIG_RELAY_CC
#define ZEND_FILE_LINE_DC
#define ZEND_FILE_LINE_ORIG_DC
#endif

#pragma GCC diagnostic ignored "-Wunused-parameter"

static bool dd_mmap_alloc_disabled = false;
static _Atomic(uintptr_t) base_addr = 0x10000; // the highest typical value of /proc/sys/vm/mmap_min_addr is 65536.

static void *dd_alloc_debug_malloc(size_t size ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC) {
    size += 16;

    size = ((size - 1) & ~0xfff) + 0x1000;
    size_t offset_multiplier = 1;
    void *addr = (void *)atomic_fetch_add(&base_addr, size);
    int flags = MAP_FIXED_NOREPLACE | MAP_ANON | MAP_PRIVATE;
retry: ;
    void *ptr = mmap(addr, size, PROT_READ | PROT_WRITE, flags, -1, 0);
    if (ptr != MAP_FAILED) {
        *(size_t *)ptr = size;
        return (char *)ptr + 16;
    }

    if (errno == EEXIST) {
        if (offset_multiplier < 0x1000000000) {
            addr = (void *)atomic_fetch_add(&base_addr, size * offset_multiplier);
            offset_multiplier *= 2;
        } else if (addr != NULL) {
            addr = NULL;
            flags &= ~MAP_FIXED_NOREPLACE;
            goto retry;
        }
    }

    fprintf(stderr, "Failed to allocate memory: %s (%d)", strerror(errno), errno);
    abort();
}

static void dd_alloc_debug_free(void *ptr ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC) {
    if (ptr) {
        size_t size = *(size_t *) ((char *) ptr - 16);
        munmap(ptr, size);
    }
}

static void *dd_alloc_debug_realloc(void *ptr, size_t size ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC) {
    if (!ptr) {
        return dd_alloc_debug_malloc(size ZEND_FILE_LINE_RELAY_CC ZEND_FILE_LINE_ORIG_RELAY_CC);
    }

    size_t current_size = *(size_t *)((char*)ptr - 16);
    if (current_size >= size + 16) {
        return ptr;
    }

    void *new_ptr = dd_alloc_debug_malloc(size ZEND_FILE_LINE_RELAY_CC ZEND_FILE_LINE_ORIG_RELAY_CC);
    memcpy(new_ptr, ptr, current_size);
    dd_alloc_debug_free(ptr ZEND_FILE_LINE_RELAY_CC ZEND_FILE_LINE_ORIG_RELAY_CC);
    return new_ptr;
}

void ddtrace_set_debug_memory_handler() {
    if (!dd_mmap_alloc_disabled) {
        zend_mm_set_custom_handlers(zend_mm_get_heap(), &dd_alloc_debug_malloc, &dd_alloc_debug_free, &dd_alloc_debug_realloc);
    }
}

void ddtrace_verify_max_count_get_limit() {
    FILE *fp = fopen("/proc/sys/vm/max_map_count", "r");
    if (!fp) {
        return;
    }

    // reading from that file returns a
    long long max_maps;
    if (!fscanf(fp, "%llu", &max_maps)) {
        fclose(fp);
        return;
    }

    fclose(fp);

    LOG(STARTUP, "Using mmap-based allocation. /proc/sys/vm/max_map_count is %lld. Beware of crashes due to too many allocations.", max_maps);
    if (max_maps < 250000) {
        LOG(ERROR, "A low limit of maps (less than 250k) was detected. Skipping mmap-based allocation.");
        dd_mmap_alloc_disabled = true;
    }
}
