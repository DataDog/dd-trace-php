#ifndef HAVE_HOOK_MEMORY_H
#define HAVE_HOOK_MEMORY_H

/* {{{ */
static inline void zai_hook_memory_allocate(zai_hook_memory_t *memory) {
    if (!zai_hook_dynamic_size) {
        memset(memory, 0, sizeof(zai_hook_memory_t));

        return;
    }

    memory->dynamic = ecalloc(1, zai_hook_dynamic_size);
}

static inline void *zai_hook_memory_auxiliary(zai_hook_memory_t *memory, zai_hook_t *hook) {
    return hook->aux.data;
}

static inline void *zai_hook_memory_dynamic(zai_hook_memory_t *memory, zai_hook_t *hook) {
    if (hook->dynamic == 0) {
        return NULL;
    }

    return (char *)(((char *)memory->dynamic) + hook->dynamic_offset);
}

static inline void zai_hook_memory_reserve(zai_hook_t *hook) {
    if (hook->dynamic) {
        /* internal install may require dynamic reservation */
        hook->dynamic_offset = zai_hook_dynamic_size;

        zai_hook_dynamic_size += ZEND_MM_ALIGNED_SIZE(hook->dynamic);
    }
}

static inline void zai_hook_memory_free(zai_hook_memory_t *memory) {
    if (memory->dynamic) {
        efree(memory->dynamic);
    }
} /* }}} */

#endif
