#ifndef DD_DEBUG_H
#define DD_DEBUG_H

#define __DD_FILENAME__ (strrchr(__FILE__, '/') ? strrchr(__FILE__, '/') + 1 : __FILE__)
#define __DD_PRINTF(fmt, ...)                                                        \
    do {                                                                             \
        const char* blacklist = getenv("DEBUG_BLACKLIST");                           \
        char file_line[100];                                                         \
        snprintf(file_line, sizeof(file_line), "%s:%d", __DD_FILENAME__, __LINE__);  \
        if (blacklist && strstr(blacklist, file_line) != NULL) {                     \
            continue;                                                                \
        }                                                                            \
        fprintf(stderr, "%s #%s " fmt "\n", file_line, __FUNCTION__, ##__VA_ARGS__); \
        fflush(stderr);                                                              \
    } while (0)

#ifdef DEBUG
#define DD_PRINTF(fmt, ...) __DD_PRINTF(fmt, ##__VA_ARGS__)
#define DD_PRINT_HASH(ht)                                                              \
    do {                                                                               \
        Bucket* p;                                                                     \
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

#define DD_PRINTF(...)

#define DD_PRINT_HASH(...)

#endif  // DEBUG

#endif  // DD_DEBUG_H
