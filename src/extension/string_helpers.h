// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <stdlib.h>
#include "attributes.h"

#define STR_FOR_FMT(a) ((a) != NULL ? (a) : "(null)")

#define STR_CONS_EQ(str, len, cons) \
    (sizeof("" cons) - 1 == len && memcmp(str, cons, len) == 0)

#define STR_STARTS_WITH_CONS(str, len, cons) \
    (sizeof("" cons) - 1 <= len && memcmp(str, cons, sizeof(cons) - 1) == 0)

#define LSTRLEN(str) (sizeof(str "") - 1)
#define LSTRARG(str) (str ""), LSTRLEN(str)

static inline void dd_string_normalize_header(
    char *nonnull s, size_t len)
{
    // in and out can overlap
    const char *end = s + len;
    for (char *p = s; p != end; p++) {
        char c = *p;
        if (c >= 'A' && c <= 'Z') {
            *p = (char)(c - 'A' + 'a');
        } else if (c == '_') {
            *p = '-';
        }
    }
}
static inline void dd_string_normalize_header2(
    const char *nonnull in, char *nonnull out, size_t len)
{
    // in and out can overlap
    const char *end = in + len;
    for (const char *p = in; p != end; p++) {
        char c = *p;
        if (c >= 'A' && c <= 'Z') {
            *out++ = (char)(c - 'A' + 'a');
        } else if (c == '_') {
            *out++ = '-';
        } else {
            *out++ = c;
        }
    }
    *out = '\0';
}
