#ifndef DDTRACE_STRING_H
#define DDTRACE_STRING_H

#include <php_version.h>
#include <stdbool.h>
#include <stddef.h>
#include <string.h>

typedef size_t ddtrace_zppstrlen_t;

struct ddtrace_string {
    char *ptr;
    ddtrace_zppstrlen_t len;
};
typedef struct ddtrace_string ddtrace_string;

#define DDTRACE_STRING_LITERAL(str) \
    (ddtrace_string) { .ptr = str, .len = sizeof(str) - 1 }

#define DDTRACE_STRING_ZVAL_L(zval_ptr, str) ZVAL_STRINGL(zval_ptr, str.ptr, str.len)

static inline ddtrace_string ddtrace_string_cstring_ctor(char *ptr) {
    ddtrace_string string = {
        .ptr = ptr,
        .len = ptr ? strlen(ptr) : 0,
    };
    return string;
}

#endif  // DDTRACE_STRING_H
