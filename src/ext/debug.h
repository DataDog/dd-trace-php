#ifndef DD_DEBUG_H
#define DD_DEBUG_H

#ifdef DEBUG
#define __FILENAME__ (strrchr(__FILE__, '/') ? strrchr(__FILE__, '/') + 1 : __FILE__)
#define DD_PRINTF(fmt, ...)                                                                          \
    do {                                                                                             \
        fprintf(stderr, "%s:%d #%s " fmt "\n", __FILENAME__, __LINE__, __FUNCTION__, ##__VA_ARGS__); \
        fflush(stderr);                                                                              \
    } while (0)
#if PHP_VERSION_ID < 70000
#define DD_PRINT_HASH(table)                                                           \
    do {                                                                               \
        const HashTable *ht = table;                                                   \
        Bucket *p;                                                                     \
        uint i;                                                                        \
        if (ht->nNumOfElements == 0) {                                                 \
            DD_PRINTF("The hash is empty");                                            \
            break;                                                                     \
        }                                                                              \
        for (i = 0; i < ht->nTableSize; i++) {                                         \
            p = ht->arBuckets[i];                                                      \
            while (p != NULL) {                                                        \
                DD_PRINTF("%s (len: %d) <==> 0x%lX\n", p->arKey, p->nKeyLength, p->h); \
                p = p->pNext;                                                          \
            }                                                                          \
        }                                                                              \
        p = ht->pListTail;                                                             \
        while (p != NULL) {                                                            \
            DD_PRINTF("%s (len: %d) <==> 0x%lX\n", p->arKey, p->nKeyLength, p->h);     \
            p = p->pListLast;                                                          \
        }                                                                              \
    } while (0)
#else
#define DD_PRINT_HASH(table)                             \
    do {                                                 \
        const HashTable *ht = table;                     \
                                                         \
        zend_ulong index;                                \
        zend_string *key;                                \
        zval *val;                                       \
        int first = 1;                                   \
                                                         \
        ZEND_HASH_FOREACH_KEY_VAL(ht, index, key, val) { \
            if (first) {                                 \
                first = 0;                               \
            } else {                                     \
                DD_PRINTF(", ");                         \
            }                                            \
            if (key) {                                   \
                DD_PRINTF("\"%s\"", ZSTR_VAL(key));      \
            } else {                                     \
                DD_PRINTF(ZEND_LONG_FMT, index);         \
            }                                            \
            DD_PRINTF(" =>");                            \
            zend_dump_const(val);                        \
        }                                                \
        ZEND_HASH_FOREACH_END();                         \
    } while (0)
#endif  // PHP < 70000
#else

#define DD_PRINTF(...)

#define DD_PRINT_HASH(...)

#endif  // DEBUG

#endif  // DD_DEBUG_H
