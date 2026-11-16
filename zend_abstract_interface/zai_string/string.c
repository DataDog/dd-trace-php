#include "string.h"

#ifndef _WIN32
#include <Zend/zend_smart_str.h>
#include <dlfcn.h>

typedef bool (ZEND_FASTCALL *zai_smart_str_frealloc_fn)(smart_str *, size_t, bool);

/* New headers route ordinary smart_str calls through these entry points.
 * Keep them usable on older PHP patch releases. As with zai_femalloc,
 * the fallback may bail out when the runtime has no fallible API. */
__attribute__((weak)) bool ZEND_FASTCALL smart_str_ferealloc(smart_str *str, size_t len, bool fallible) {
    zai_smart_str_frealloc_fn fn = (zai_smart_str_frealloc_fn)dlsym(RTLD_DEFAULT, "smart_str_ferealloc");
    if (fn && fn != smart_str_ferealloc) {
        return fn(str, len, fallible);
    }
    smart_str_erealloc(str, len);
    return true;
}

__attribute__((weak)) bool ZEND_FASTCALL smart_str_frealloc(smart_str *str, size_t len, bool fallible) {
    zai_smart_str_frealloc_fn fn = (zai_smart_str_frealloc_fn)dlsym(RTLD_DEFAULT, "smart_str_frealloc");
    if (fn && fn != smart_str_frealloc) {
        return fn(str, len, fallible);
    }
    smart_str_realloc(str, len);
    return true;
}
#endif

char *ZAI_STRING_EMPTY_PTR = "";

extern inline zai_str zai_str_from_zstr(zend_string *zstr);

extern inline zai_string zai_string_concat3(zai_str first, zai_str second, zai_str third);
