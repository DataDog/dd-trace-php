#ifndef ZAI_ALLOC_H
#define ZAI_ALLOC_H

#include <php.h>
#include <stdbool.h>
#include <stdio.h>
#include <string.h>

/* The fallible APIs may be backported without changing PHP's module ABI.
 * Resolve them at runtime, including when building with older PHP headers.
 * Older runtimes keep their normal (bailing out) allocation behavior.
 * On newer runtimes a failure queues a soft OOM; callers must release partial
 * results and return to PHP rather than continue allocating.
 */
#ifdef _WIN32
static inline FARPROC zai_alloc_symbol(const char* name, unsigned int arg_count) {
    HMODULE module;
    if (!GetModuleHandleExA(GET_MODULE_HANDLE_EX_FLAG_FROM_ADDRESS | GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT, (LPCSTR)_emalloc, &module)) {
        return NULL;
    }
    FARPROC fn = GetProcAddress(module, name);
    if (!fn) {
        /* MSVC's ZEND_FASTCALL exports use __vectorcall decoration. All these
         * arguments occupy one pointer-sized slot, including debug line numbers. */
        char decorated[64];
        unsigned int arg_bytes = arg_count * (unsigned int)sizeof(void*);
        snprintf(decorated, sizeof(decorated), "%s@@%u", name, arg_bytes);
        fn = GetProcAddress(module, decorated);
#ifndef _WIN64
        if (!fn) {
            /* Older 32-bit builds use __fastcall instead. */
            snprintf(decorated, sizeof(decorated), "@%s@%u", name, arg_bytes);
            fn = GetProcAddress(module, decorated);
        }
#endif
    }
    return fn;
}

#define ZAI_OPTIONAL_ALLOC_API(ret, name, args, arg_count)            \
    typedef ret(ZEND_FASTCALL* zai_##name##_fn) args;                 \
    static inline zai_##name##_fn zai_resolve_##name(void) {          \
        ZEND_TLS bool resolved;                                       \
        ZEND_TLS zai_##name##_fn fn;                                  \
        if (!resolved) {                                              \
            fn = (zai_##name##_fn)zai_alloc_symbol(#name, arg_count); \
            resolved = true;                                          \
        }                                                             \
        return fn;                                                    \
    }
#else
#ifdef __APPLE__
#define ZAI_ALLOC_WEAK __attribute__((weak_import))
#else
#define ZAI_ALLOC_WEAK __attribute__((weak))
#endif
#define ZAI_OPTIONAL_ALLOC_API(ret, name, args, arg_count) \
    typedef ret(ZEND_FASTCALL* zai_##name##_fn) args;      \
    ZEND_API ret ZEND_FASTCALL name args ZAI_ALLOC_WEAK;   \
    static inline zai_##name##_fn zai_resolve_##name(void) { return name; }
#endif

ZAI_OPTIONAL_ALLOC_API(void*, _femalloc, (size_t size ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC), 1 + 4 * ZEND_DEBUG)
ZAI_OPTIONAL_ALLOC_API(void*, _safe_femalloc, (size_t nmemb, size_t size, size_t offset ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC), 3 + 4 * ZEND_DEBUG)
ZAI_OPTIONAL_ALLOC_API(void*, _safe_ferealloc, (void* ptr, size_t nmemb, size_t size, size_t offset ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC),
                       4 + 4 * ZEND_DEBUG)
ZAI_OPTIONAL_ALLOC_API(zval*, zend_hash_fupdate, (HashTable * ht, zend_string* key, zval* value), 3)
ZAI_OPTIONAL_ALLOC_API(zval*, zend_hash_index_fupdate, (HashTable * ht, zend_ulong key, zval* value), 3)
ZAI_OPTIONAL_ALLOC_API(zval*, zend_hash_next_index_finsert, (HashTable * ht, zval* value), 2)

/* The minimal fix for the EOL branches (PHP-7.4/8.0/8.1, php-src 1d7dec178a)
 * exports zend_mm_safe_error_ex() and none of the fallible APIs above. It is
 * variadic and not ZEND_FASTCALL, so it is only probed for, never called.
 * cdecl variadic exports are undecorated on Windows. */
#ifdef _WIN32
static inline bool zai_has_zend_mm_safe_error_ex(void) {
    return zai_alloc_symbol("zend_mm_safe_error_ex", 0) != NULL;
}
#else
ZEND_API ZEND_NORETURN void zend_mm_safe_error_ex(const char* format, ...) ZAI_ALLOC_WEAK;
static inline bool zai_has_zend_mm_safe_error_ex(void) { return zend_mm_safe_error_ex != NULL; }
#endif

#undef ZAI_OPTIONAL_ALLOC_API
#undef ZAI_ALLOC_WEAK

static inline void* zai_femalloc(size_t size) {
    zai__femalloc_fn fn = zai_resolve__femalloc();
    return (fn ? fn : _emalloc)(size ZEND_FILE_LINE_CC ZEND_FILE_LINE_EMPTY_CC);
}

static inline void* zai_safe_femalloc(size_t nmemb, size_t size, size_t offset) {
    zai__safe_femalloc_fn fn = zai_resolve__safe_femalloc();
    return (fn ? fn : _safe_emalloc)(nmemb, size, offset ZEND_FILE_LINE_CC ZEND_FILE_LINE_EMPTY_CC);
}

/* On failure, ptr remains owned by the caller. */
static inline void* zai_safe_ferealloc(void* ptr, size_t nmemb, size_t size, size_t offset) {
    zai__safe_ferealloc_fn fn = zai_resolve__safe_ferealloc();
    return (fn ? fn : _safe_erealloc)(ptr, nmemb, size, offset ZEND_FILE_LINE_CC ZEND_FILE_LINE_EMPTY_CC);
}

static inline zend_string* zai_string_falloc(size_t len) {
    zend_string* str = (zend_string*)zai_safe_femalloc(1, len, ZEND_MM_ALIGNED_SIZE(_ZSTR_STRUCT_SIZE(0)));
    if (!str) {
        return NULL;
    }
#if PHP_VERSION_ID < 70300
    GC_REFCOUNT(str) = 1;
#else
    GC_SET_REFCOUNT(str, 1);
#endif
#if PHP_VERSION_ID < 80000
    GC_TYPE_INFO(str) = IS_STRING;
#else
    GC_TYPE_INFO(str) = GC_STRING;
#endif
    ZSTR_H(str) = 0;
    ZSTR_LEN(str) = len;
    return str;
}

static inline zend_string* zai_string_finit(const char* value, size_t len) {
    zend_string* str = zai_string_falloc(len);
    if (str) {
        memcpy(ZSTR_VAL(str), value, len);
        ZSTR_VAL(str)[len] = '\0';
    }
    return str;
}

static inline bool zai_array_finit(zval* value) {
    HashTable* ht = (HashTable*)zai_femalloc(sizeof(HashTable));
    if (!ht) {
        ZVAL_UNDEF(value);
        return false;
    }
    /* Initialization is lazy and does not allocate buckets. */
    zend_hash_init(ht, 0, NULL, ZVAL_PTR_DTOR, 0);
    ZVAL_ARR(value, ht);
    return true;
}

/* Match HASH_NOMEM even when compiling against headers that predate it.
 * NULL still means an insertion failed for a reason other than allocation.
 * Neither failure transfers ownership of value to the hash table.
 */
#define ZAI_HASH_NOMEM ((zval*)-1)

static inline zval* zai_symtable_str_fupdate(HashTable* ht, const char* key, size_t len, zval* value) {
    zend_ulong index;
    if (ZEND_HANDLE_NUMERIC_STR(key, len, index)) {
        zai_zend_hash_index_fupdate_fn fn = zai_resolve_zend_hash_index_fupdate();
        return fn ? fn(ht, index, value) : zend_hash_index_update(ht, index, value);
    }
    zend_string* str = zai_string_finit(key, len);
    if (!str) {
        return ZAI_HASH_NOMEM;
    }
    zai_zend_hash_fupdate_fn fn = zai_resolve_zend_hash_fupdate();
    zval* result = fn ? fn(ht, str, value) : zend_hash_update(ht, str, value);
    zend_string_release(str);
    return result;
}

static inline zval* zai_hash_next_index_finsert(HashTable* ht, zval* value) {
    zai_zend_hash_next_index_finsert_fn fn = zai_resolve_zend_hash_next_index_finsert();
    return fn ? fn(ht, value) : zend_hash_next_index_insert(ht, value);
}

#endif /* ZAI_ALLOC_H */
