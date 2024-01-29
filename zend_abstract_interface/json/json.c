#include "json.h"

typedef unsigned char php_json_ctype;

typedef int php_json_error_code;

typedef struct _php_json_scanner {
    php_json_ctype *cursor;         /* cursor position */
    php_json_ctype *token;          /* token position */
    php_json_ctype *limit;          /* the last read character + 1 position */
    php_json_ctype *marker;         /* marker position for backtracking */
    php_json_ctype *ctxmarker;      /* marker position for context backtracking */
    php_json_ctype *str_start;      /* start position of the string */
    php_json_ctype *pstr;           /* string pointer for escapes conversion */
    zval value;                     /* value */
    int str_esc;                    /* number of extra characters for escaping */
    int state;                      /* condition state */
    int options;                    /* options */
    php_json_error_code errcode;    /* error type if there is an error */
#if PHP_VERSION_ID >= 70200
    int utf8_invalid;               /* whether utf8 is invalid */
    int utf8_invalid_count;         /* number of extra character for invalid utf8 */
#endif
} php_json_scanner;

typedef struct _php_json_parser php_json_parser;

typedef int (*php_json_parser_func_array_create_t)(
        php_json_parser *parser, zval *array);
typedef int (*php_json_parser_func_array_append_t)(
        php_json_parser *parser, zval *array, zval *zvalue);
typedef int (*php_json_parser_func_array_start_t)(
        php_json_parser *parser);
typedef int (*php_json_parser_func_array_end_t)(
        php_json_parser *parser, zval *object);
typedef int (*php_json_parser_func_object_create_t)(
        php_json_parser *parser, zval *object);
typedef int (*php_json_parser_func_object_update_t)(
        php_json_parser *parser, zval *object, zend_string *key, zval *zvalue);
typedef int (*php_json_parser_func_object_start_t)(
        php_json_parser *parser);
typedef int (*php_json_parser_func_object_end_t)(
        php_json_parser *parser, zval *object);

typedef struct _php_json_parser_methods {
    php_json_parser_func_array_create_t array_create;
    php_json_parser_func_array_append_t array_append;
    php_json_parser_func_array_start_t array_start;
    php_json_parser_func_array_end_t array_end;
    php_json_parser_func_object_create_t object_create;
    php_json_parser_func_object_update_t object_update;
    php_json_parser_func_object_start_t object_start;
    php_json_parser_func_object_end_t object_end;
} php_json_parser_methods;

struct _php_json_parser {
    php_json_scanner scanner;
    zval *return_value;
    int depth;
    int max_depth;
    php_json_parser_methods methods;
};

#if PHP_VERSION_ID < 70100
#define zai_json_encode_signature(name) void name(smart_str *buf, zval *val, int options)
#define zai_json_decode_ex_signature(name) void name(zval *return_value, char *str, int str_len, int options, long depth)

zai_json_decode_ex_signature((*zai_json_decode_ex));
#ifndef _WIN32
__attribute__((weak)) zai_json_decode_ex_signature(php_json_decode_ex);
#else
extern zai_json_decode_ex_signature(php_json_decode_ex);
#pragma comment(linker, "/alternatename:php_json_decode_ex=_php_json_decode_ex")
zai_json_decode_ex_signature((*_php_json_decode_ex)) = NULL;
#endif
#else
#define zai_json_encode_signature(name) int name(smart_str *buf, zval *val, int options)
#define zai_json_parser_init_signature(name) void name(php_json_parser *parser, zval *return_value, const char *str, size_t str_len, int options, int max_depth)
#define zai_json_parse_signature(name) int name(php_json_parser *parser)

zai_json_parser_init_signature((*zai_json_parser_init));
zai_json_parse_signature((*zai_json_parse));
#ifndef _WIN32
__attribute__((weak)) zai_json_parser_init_signature(php_json_parser_init);
__attribute__((weak)) zai_json_parse_signature(php_json_parse);
#else
extern zai_json_parser_init_signature(php_json_parser_init);
#pragma comment(linker, "/alternatename:php_json_parser_init=_php_json_parser_init")
zai_json_parser_init_signature((*_php_json_parser_init)) = NULL;

extern zai_json_parse_signature(php_json_parse);
#pragma comment(linker, "/alternatename:php_json_parse=_php_json_parse")
zai_json_parse_signature((*_php_json_parse)) = NULL;
#endif
#endif

zai_json_encode_signature((*zai_json_encode));
#ifndef _WIN32
__attribute__((weak)) zai_json_encode_signature(php_json_encode);
#else
extern zai_json_encode_signature(php_json_encode);
#pragma comment(linker, "/alternatename:php_json_encode=_php_json_encode")
zai_json_encode_signature((*_php_json_encode)) = NULL;

extern zend_class_entry *_php_json_serializable_ce = NULL;
#pragma comment(linker, "/alternatename:php_json_serializable_ce=_php_json_serializable_ce")
#endif

#if !defined(_WIN32) && !defined(__APPLE__)
__attribute__((weak)) zend_class_entry *php_json_serializable_ce;
#endif

bool zai_json_setup_bindings(void) {
    if (php_json_encode && php_json_serializable_ce) {
        zai_json_encode = php_json_encode;
#if PHP_VERSION_ID < 70100
        zai_json_decode_ex = php_json_decode_ex;
#else
        zai_json_parse = php_json_parse;
        zai_json_parser_init = php_json_parser_init;
#endif
        return true;
    }

    zend_module_entry *json_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("json"));

    void *handle = NULL;
    if (json_me && json_me->handle) {
        handle = json_me->handle;
#ifdef _WIN32
    } else {
        // some well known function
        // We need the php.dll, not the php.exe,
        GetModuleHandleEx(GET_MODULE_HANDLE_EX_FLAG_FROM_ADDRESS | GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT, (LPCTSTR)php_write, (HMODULE *)&handle);
#endif
    }

    zai_json_encode = (zai_json_encode_signature((*))) DL_FETCH_SYMBOL(handle, "php_json_encode");
    if (zai_json_encode == NULL) {
        zai_json_encode = (zai_json_encode_signature((*))) DL_FETCH_SYMBOL(handle, "_php_json_encode");
    }

#if PHP_VERSION_ID < 70100
    zai_json_decode_ex = (zai_json_decode_ex_signature((*))) DL_FETCH_SYMBOL(handle, "php_json_decode_ex");
    if (zai_json_decode_ex == NULL) {
        zai_json_decode_ex = (zai_json_decode_ex_signature((*))) DL_FETCH_SYMBOL(handle, "_php_json_decode_ex");
    }
#else
    zai_json_parse = (zai_json_parse_signature((*))) DL_FETCH_SYMBOL(json_me->handle, "php_json_parse");
    if (zai_json_parse == NULL) {
        zai_json_parse = (zai_json_parse_signature((*))) DL_FETCH_SYMBOL(handle, "_php_json_parse");
    }

    zai_json_parser_init = (zai_json_parser_init_signature((*))) DL_FETCH_SYMBOL(handle, "php_json_parser_init");
    if (zai_json_parser_init == NULL) {
        zai_json_parser_init = (zai_json_parser_init_signature((*))) DL_FETCH_SYMBOL(handle, "_php_json_parser_init");
    }
#endif

    zend_class_entry **tmp_json_serializable_ce = (zend_class_entry **) DL_FETCH_SYMBOL(handle, "php_json_serializable_ce");
    if (tmp_json_serializable_ce == NULL) {
        tmp_json_serializable_ce = (zend_class_entry **) DL_FETCH_SYMBOL(handle, "_php_json_serializable_ce");
    }
    if (tmp_json_serializable_ce != NULL) {
        php_json_serializable_ce = *tmp_json_serializable_ce;
    }

    return zai_json_encode != NULL;
}

void zai_json_release_persistent_array(HashTable *ht) {
#if PHP_VERSION_ID < 70300
    if (--GC_REFCOUNT(ht) == 0)
#else
    if (GC_DELREF(ht) == 0)
#endif
    {
        zend_hash_destroy(ht);
        free(ht);
    }
}

void zai_json_dtor_pzval(zval *pval) {
    if (Z_TYPE_P(pval) == IS_ARRAY) {
        zai_json_release_persistent_array(Z_ARR_P(pval));
    } else {
        zval_internal_ptr_dtor(pval);
    }
    // Prevent an accidental use after free
    ZVAL_UNDEF(pval);
}

static inline zend_string *zai_json_persist_string(zend_string *str) {
    if (GC_FLAGS(str) & IS_STR_PERSISTENT) {
        return str;
    }

    zend_string *persistent = zend_string_init(ZSTR_VAL(str), ZSTR_LEN(str), true);
    zend_string_release(str);
    return persistent;
}

#if PHP_VERSION_ID < 70100
static zend_always_inline void zend_hash_release(zend_array *array) {
    if (!(GC_FLAGS(array) & IS_ARRAY_IMMUTABLE)) {
        if (--GC_REFCOUNT(array) == 0) {
            zend_hash_destroy(array);
            pefree(array, array->u.flags & HASH_FLAG_PERSISTENT);
        }
    }
}

static void zai_json_persist_zval(zval *in) {
    if (Z_TYPE_P(in) == IS_ARRAY) {
        zend_array *array = Z_ARR_P(in);
        ZVAL_NEW_PERSISTENT_ARR(in);
        zend_hash_init(Z_ARR_P(in), array->nTableSize, NULL, zai_json_dtor_pzval, 1);
        if (zend_hash_num_elements(array)) {
            Bucket *bucket;
            ZEND_HASH_FOREACH_BUCKET(array, bucket) {
                zai_json_persist_zval(&bucket->val);
                if (bucket->key) {
                    zend_hash_str_add_new(Z_ARR_P(in), ZSTR_VAL(bucket->key), ZSTR_LEN(bucket->key), &bucket->val);
                } else {
                    zend_hash_index_add_new(Z_ARR_P(in), bucket->h, &bucket->val);
                }
                ZVAL_NULL(&bucket->val);
            } ZEND_HASH_FOREACH_END();
        }
        zend_hash_release(array);
    } else if (Z_TYPE_P(in) == IS_STRING) {
        ZVAL_STR(in, zai_json_persist_string(Z_STR_P(in)));
    }
}
#else

// We need to avoid having the json parser release our array on failure, it'll use zend_array_destroy() which strongly dislikes persistent arrays.
// Hence refcount it, and keep track of not-inserted arrays
struct HashTablePtr {
    HashTable ht;
    struct HashTablePtr *next;
};

ZEND_TLS struct HashTablePtr *zai_json_persistent_stack;

static int zai_json_parser_array_create(php_json_parser *parser, zval *array) {
    (void)parser;

    struct HashTablePtr *ht = malloc(sizeof(*ht));
    zend_hash_init(&ht->ht, 8, NULL, zai_json_dtor_pzval, 1);
    ZVAL_ARR(array, &ht->ht);
    Z_ADDREF_P(array);

    ht->next = zai_json_persistent_stack;
    zai_json_persistent_stack = ht;

    return SUCCESS;
}

static int zai_json_parser_array_end(php_json_parser *parser, zval *array) {
    (void)parser;

    Z_DELREF_P(array);
#if PHP_VERSION_ID >= 70200 && ZEND_DEBUG
    Z_ARR_P(array)->u.flags &= ~HASH_FLAG_ALLOW_COW_VIOLATION;
#endif
    zai_json_persistent_stack = ((struct HashTablePtr *)Z_ARR_P(array))->next;

    return SUCCESS;
}

static int zai_json_parser_array_append(php_json_parser *parser, zval *array, zval *zvalue) {
    (void)parser;

    if (Z_TYPE_P(zvalue) == IS_STRING) {
        Z_STR_P(zvalue) = zai_json_persist_string(Z_STR_P(zvalue));
    }

#if PHP_VERSION_ID >= 70200
    HT_ALLOW_COW_VIOLATION(Z_ARRVAL_P(array)); // due to ADDREF
#endif

    zend_hash_next_index_insert(Z_ARRVAL_P(array), zvalue);
    return SUCCESS;
}

static int zai_json_parser_object_update(php_json_parser *parser, zval *object, zend_string *key, zval *zvalue) {
    (void)parser;

    if (Z_TYPE_P(zvalue) == IS_STRING) {
        Z_STR_P(zvalue) = zai_json_persist_string(Z_STR_P(zvalue));
    }

#if PHP_VERSION_ID >= 70200
    HT_ALLOW_COW_VIOLATION(Z_ARRVAL_P(object)); // due to ADDREF
#endif

    zend_ulong idx;
    if (ZEND_HANDLE_NUMERIC(key, idx)) {
        zend_hash_index_update(Z_ARR_P(object), idx, zvalue);
    } else {
        key = zai_json_persist_string(key);
        zend_hash_update(Z_ARR_P(object), key, zvalue);
        zend_string_release(key);
    }
    return SUCCESS;
}
#endif

int zai_json_decode_assoc_safe(zval *return_value, const char *str, int str_len, long depth, bool persistent) {
#if PHP_VERSION_ID < 70100
    ZVAL_UNDEF(return_value);
    zai_json_decode_ex(return_value, (char *)str, str_len, PHP_JSON_OBJECT_AS_ARRAY, depth);
    if (persistent) {
        zai_json_persist_zval(return_value);
    }
    return Z_ISUNDEF_P(return_value) ? FAILURE : SUCCESS;
#else
    php_json_parser parser;
    zai_json_parser_init(&parser, return_value, str, str_len, PHP_JSON_OBJECT_AS_ARRAY, (int) depth);

    if (persistent) {
        parser.methods.array_create = zai_json_parser_array_create;
        parser.methods.array_append = zai_json_parser_array_append;
        parser.methods.array_end = zai_json_parser_array_end;
        parser.methods.object_create = zai_json_parser_array_create;
        parser.methods.object_update = zai_json_parser_object_update;
        parser.methods.object_end = zai_json_parser_array_end;
    }

    if (zai_json_parse(&parser)) {
        while (zai_json_persistent_stack) {
            struct HashTablePtr *ht = zai_json_persistent_stack, *next = ht->next;
            zai_json_release_persistent_array(&ht->ht);
            zai_json_persistent_stack = next;
        }

        return FAILURE;
    }

    if (persistent && Z_TYPE_P(return_value) == IS_STRING) {
        ZVAL_STR(return_value, zai_json_persist_string(Z_STR_P(return_value)));
    }

    return SUCCESS;
#endif
}
