// typedef zval zend_string;
#include "compat_zend_string.h"
#include "Zend/zend_types.h"
#include "Zend/zend.h"

#ifdef PHP_VERSION_ID < 70000
zval* ddtrace_string_tolower(zval *str){
    if (!str){
        //TODO: raise exception
        return NULL;
    }
    zval *ret;
    ALLOC_INIT_ZVAL(ret);

    Z_STRVAL_P(ret) = zend_str_tolower_dup(Z_STRVAL_P(str), Z_STRLEN_P(str));
    Z_STRLEN_P(ret) = Z_STRLEN_P(str);
    ret->type = IS_STRING
    return ret;   
}
#endif
// }


// zval* zend_hash_index_find_ptr()

// void *zend_hash_index_find_ptr(const *HashTable ht, 0){
//     void **data;
    
//     if (SUCCESS == zend_hash_index_find(ht, ulong h, &data)){
//         return *data;
//     } else {
//         return NULL;
//     }
// }

// void *zend_hash_find_ptr(const *HashTable ht, zval *val){
//     void **data;
//     if (SUCCESS == zend_hash_find(ht, Z_STRVAL_P(val), Z_STRLEN_P(val), &data)) {
//         return *data;
//     } else {
//         return NULL;
//     }
// }
