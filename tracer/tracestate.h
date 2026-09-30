#ifndef DDTRACE_TRACESTATE_H
#define DDTRACE_TRACESTATE_H

#include <stdbool.h>
#include <stddef.h>

static inline bool ddtrace_tracestate_member_is(const char *member, size_t member_len, const char key[2]) {
    while (member_len && (*member == ' ' || *member == '\t')) {
        ++member;
        --member_len;
    }
    return member_len >= 3 && member[0] == key[0] && member[1] == key[1] && member[2] == '=';
}

#endif  // DDTRACE_TRACESTATE_H
