#include "string.h"

#ifndef GC_FLAGS_SHIFT
# define GC_FLAGS_SHIFT 8
#endif

zai_string_t* zai_strings_known;

static zai_string_view zai_string_known_views[] = {
#define ZAI_STRINGS_KNOWN_VALUE(id, str) {sizeof(str) - 1, str},
    ZAI_STRINGS_KNOWN(ZAI_STRINGS_KNOWN_VALUE)
#undef ZAI_STRINGS_KNOWN_VALUE
        ZAI_STRING_EMPTY};

zai_string_t zai_string_intern_known(zai_string_view* known) {
#if PHP_VERSION_ID >= 70000
    zai_string_t interned = calloc(1, _ZSTR_STRUCT_SIZE(known->len));

    if (!interned) {
        return NULL;
    }

    GC_TYPE_INFO(interned) = IS_STRING | ((IS_STR_INTERNED | IS_STR_PERMANENT) << GC_FLAGS_SHIFT);

    memcpy(&ZSTR_VAL(interned)[0], known->ptr, known->len);

    ZSTR_LEN(interned) = known->len;
    ZSTR_VAL(interned)[ZSTR_LEN(interned)] = 0;
    ZSTR_H(interned) = zend_inline_hash_func(known->ptr, known->len);

    return interned;
#else
    return known;
#endif
}

bool zai_string_minit() {
    zai_strings_known = calloc(ZAI_STRINGS_KNOWN_LAST, sizeof(zai_string_t));

    if (!zai_strings_known) {
        return false;
    }

    for (uint32_t idx = 0; idx < ZAI_STRINGS_KNOWN_LAST; idx++) {
        zai_strings_known[idx] = zai_string_intern_known(&zai_string_known_views[idx]);
    }
    return true;
}

bool zai_string_rinit() { return true; }

bool zai_string_mshutdown() {
#if PHP_VERSION_ID >= 70000
    for (uint32_t idx = 0; idx < ZAI_STRINGS_KNOWN_LAST; idx++) {
        free(zai_strings_known[idx]);
    }
#endif

    free(zai_strings_known);

    return true;
}
