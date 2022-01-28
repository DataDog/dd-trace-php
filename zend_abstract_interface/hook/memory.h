#ifndef HAVE_HOOK_MEMORY_H
#define HAVE_HOOK_MEMORY_H

/* TODO php5 */

/* {{{ */
static inline void zai_hook_memory_allocate(zai_hook_memory_t *memory) {
    if (!zai_hook_auxiliary_size && !zai_hook_dynamic_size) {
        memset(memory, 0, sizeof(zai_hook_memory_t));

        return;
    }

    size_t zai_hook_memory_size = ZEND_MM_ALIGNED_SIZE(zai_hook_auxiliary_size + zai_hook_dynamic_size);

    memory->auxiliary = emalloc(zai_hook_memory_size);

    memset(memory->auxiliary, 0, zai_hook_memory_size);

    memory->dynamic = (void *)(((char *)memory->auxiliary) + ZEND_MM_ALIGNED_SIZE(zai_hook_auxiliary_size));
}

static inline void *zai_hook_memory_auxiliary(zai_hook_memory_t *memory, zai_hook_t *hook) {
    if (hook->aux.type == ZAI_HOOK_UNUSED) {
        return NULL;
    }

    switch (hook->type) {
        case ZAI_HOOK_INTERNAL:
            return hook->aux.u.i;

        case ZAI_HOOK_USER:
            return (char *)(((char *)memory->auxiliary) + hook->offset.auxiliary);

        default: { /* unreachable */
        }
    }

    return NULL;
}

static inline void *zai_hook_memory_dynamic(zai_hook_memory_t *memory, zai_hook_t *hook) {
    if (hook->type != ZAI_HOOK_INTERNAL || hook->dynamic == 0) {
        return NULL;
    }

    return (char *)(((char *)memory->dynamic) + hook->offset.dynamic);
}

static inline void zai_hook_memory_free(zai_hook_memory_t *memory) {
    if (zai_hook_auxiliary_size) {
        for (size_t offset = 0; offset < (zai_hook_auxiliary_size / ZEND_MM_ALIGNED_SIZE(sizeof(zval))); offset++) {
            zval_dtor(((zval *)memory->auxiliary) + offset);
        }
    }

    if (memory->auxiliary) {
        efree(memory->auxiliary);
    }
} /* }}} */

#endif
