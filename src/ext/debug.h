#ifndef DD_DEBUG_H
#define DD_DEBUG_H

#ifdef DEBUG
#define __FILENAME__ (strrchr(__FILE__, '/') ? strrchr(__FILE__, '/') + 1 : __FILE__)
#define DD_PRINTF(fmt, ...)                                                                          \
    do {                                                                                             \
        fprintf(stderr, "%s:%d #%s " fmt "\n", __FILENAME__, __LINE__, __FUNCTION__, ##__VA_ARGS__); \
        fflush(stderr);                                                                              \
    } while (0)
#else
#define DD_PRINTF(...)
#endif  // DEBUG

#endif  // DD_DEBUG_H
