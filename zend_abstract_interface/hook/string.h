#ifndef HAVE_HOOK_STRING_H
#define HAVE_HOOK_STRING_H
// clang-format off
/* {{{ atomic string junk */
typedef struct {
    size_t   len;
    char    *data;
    uint32_t refs;
} zai_hook_string_t;

static inline zai_hook_string_t* zai_hook_string_from(zai_string_view *view) {
    if (!view->len) {
        return NULL;
    }

    zai_hook_string_t *result = pemalloc(ZEND_MM_ALIGNED_SIZE(sizeof(zai_hook_string_t) + view->len), 1);

    result->refs = 1;
    result->data = (char*) (((char*) result) + sizeof(zai_hook_string_t));
    result->len  = view->len;

    memcpy(result->data, view->ptr, view->len);

    return result;
}

static inline zai_hook_string_t* zai_hook_string_copy(zai_hook_string_t *string) {
    __atomic_add_fetch(&string->refs, 1, __ATOMIC_ACQ_REL);

    return string;
}

static inline zai_string_view* zai_hook_string_cast(zai_hook_string_t *string) {
    return (zai_string_view*) string;
}

static inline void zai_hook_string_release(zai_hook_string_t *string) {
    if (__atomic_sub_fetch(&string->refs, 1, __ATOMIC_ACQ_REL) == 0) {
        pefree(string, 1);
    }
} /* }}} */
#endif
