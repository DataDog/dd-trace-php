#ifndef DDTRACE_SPANDATA_HH
#define DDTRACE_SPANDATA_HH

#include <Zend/zend.h>
#include <stdbool.h>

extern zend_class_entry *ddtrace_spandata_ce;

void ddtrace_spandata_register_ce(void);

struct ddtrace_spandata {
    struct ddtrace_span_t *backptr;

    /* In PHP 7, the standard PHP object is placed last and named `std`.
     * This is because of a memory optimization that places the common
     * properties directly after this struct. The struct becomes variable sized,
     * so be cautious about stack allocating it or storing it directly in an
     * array (use an array of pointers instead).
     */
    zend_object std;
};
typedef struct ddtrace_spandata ddtrace_spandata;

// Converts pointer to ddtrace_spandata's std member to a pointer of span itself.
inline ddtrace_spandata *ddtrace_spandata_from_obj(zend_object *object) {
    return (ddtrace_spandata *)((char *)(object)-XtOffsetOf(ddtrace_spandata, std));
}

inline ddtrace_spandata *ddtrace_spandata_from_zval(zval *zv) { return ddtrace_spandata_from_obj(Z_OBJ_P(zv)); }

bool ddtrace_spandata_is_top(zval *obj);

#endif  // DDTRACE_SPANDATA_HH
