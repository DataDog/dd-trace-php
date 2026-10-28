// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.
#pragma once

// The parsers referenced by configuration_table.h. Only included where the
// configuration table is instantiated (ext/configuration.c).

#include <limits.h>
#include <stdlib.h>

#include "logging.h"
#include "user_tracking.h"

static bool _parse_uint(
    zai_str value, zval *nonnull decoded_value, long long max);

static bool _parse_uint32(
    zai_str value, zval *nonnull decoded_value, bool persistent)
{
    UNUSED(persistent);
    return _parse_uint(value, decoded_value, UINT32_MAX);
}

static bool _parse_uint64(
    zai_str value, zval *nonnull decoded_value, bool persistent)
{
    UNUSED(persistent);
    return _parse_uint(value, decoded_value, LONG_MAX);
}

static bool _parse_uint(
    zai_str value, zval *nonnull decoded_value, long long max)
{
    char *endptr = NULL;
    const int base = 10;
    long long ini_value = strtoll(value.ptr, &endptr, base);

    if (endptr == value.ptr || *endptr != '\0') {
        return false;
    }

    if (ini_value < 0) {
        ini_value = 0;
    } else if (ini_value > max) {
        ini_value = max;
    }

    ZVAL_LONG(decoded_value, ini_value);
    return true;
}
