// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include "attributes.h"
#include <stdbool.h>
#include <stdlib.h>

#define STR_FOR_FMT(a) ((a) != NULL ? (a) : "(null)")

#define STR_CONS_EQ(str, len, cons) \
    (sizeof("" cons) - 1 == len && memcmp(str, cons, len) == 0)

#define STR_STARTS_WITH_CONS(str, len, cons) \
    (sizeof("" cons) - 1 <= len && memcmp(str, cons, sizeof(cons) - 1) == 0)

#define LSTRLEN(str) (sizeof(str "") - 1)
#define LSTRARG(str) (str ""), LSTRLEN(str)
#define LSTRLEN_MAX(a, b) (LSTRLEN(a) > LSTRLEN(b) ? LSTRLEN(a) : LSTRLEN(b))

bool dd_string_starts_with_lc(const char *nonnull s, size_t len,
    const char *nonnull cmp_lc, size_t cmp_lc_len);
bool dd_string_equals_lc(const char *nonnull s, size_t len,
    const char *nonnull cmp_lc, size_t cmp_lc_len);
void dd_string_normalize_header(char *nonnull s, size_t len);
void dd_string_normalize_header2(
    const char *nonnull in, char *nonnull out, size_t len);
