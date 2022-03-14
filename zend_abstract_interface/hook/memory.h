#ifndef HAVE_HOOK_MEMORY_H
#define HAVE_HOOK_MEMORY_H

static inline void zai_hook_memory_allocate(zai_hook_memory_t *memory, size_t dynamic_size) {
    if (!dynamic_size) {
        memset(memory, 0, sizeof(zai_hook_memory_t));

        return;
    }

    memory->dynamic = ecalloc(1, dynamic_size);
}

static inline void *zai_hook_memory_dynamic(zai_hook_memory_t *memory, zai_hook_t *hook) {
    if (hook->dynamic == 0) {
        return NULL;
    }

    return (char *)(((char *)memory->dynamic) + hook->dynamic_offset);
}

static inline void zai_hook_memory_free(zai_hook_memory_t *memory) {
    if (memory->dynamic) {
        efree(memory->dynamic);
    }
}

#endif
