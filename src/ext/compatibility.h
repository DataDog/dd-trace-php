#ifndef DD_COMPATIBILITY_H
#define DD_COMPATIBILITY_H

#define UNUSED_1(x) (void)(x)
#define UNUSED_2(x, y) \
    do {               \
        UNUSED_1(x);   \
        UNUSED_1(y);   \
    } while (0)
#define UNUSED_3(x, y, z) \
    do {                  \
        UNUSED_1(x);      \
        UNUSED_1(y);      \
        UNUSED_1(z);      \
    } while (0)
#define UNUSED_4(x, y, z, q) \
    do {                     \
        UNUSED_1(x);         \
        UNUSED_1(y);         \
        UNUSED_1(z);         \
        UNUSED_1(q);         \
    } while (0)
#define UNUSED_5(x, y, z, q, w) \
    do {                        \
        UNUSED_1(x);            \
        UNUSED_1(y);            \
        UNUSED_1(z);            \
        UNUSED_1(q);            \
        UNUSED_1(w);            \
    } while (0)
#define _GET_UNUSED_MACRO_OF_ARITY(_1, _2, _3, _4, _5, ARITY, ...) UNUSED_##ARITY
#define UNUSED(...) _GET_UNUSED_MACRO_OF_ARITY(__VA_ARGS__, 5, 4, 3, 2, 1)(__VA_ARGS__)

#if PHP_VERSION_ID < 70000
#define PHP5_UNUSED(...) UNUSED(__VA_ARGS__)
#define PHP7_UNUSED(...) /* unused unused */
#else
#define PHP5_UNUSED(...) /* unused unused */
#define PHP7_UNUSED(...) UNUSED(__VA_ARGS__)
#endif

#if PHP_VERSION_ID < 70000
typedef int32_t zend_long;
#endif

#endif  // DD_COMPATIBILITY_H
