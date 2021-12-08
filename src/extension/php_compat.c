// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "php_compat.h"

#if PHP_VERSION_ID < 70300
zend_bool zend_ini_parse_bool(zend_string *str)
{
    if ((ZSTR_LEN(str) == 4 && strcasecmp(ZSTR_VAL(str), "true") == 0) ||
        (ZSTR_LEN(str) == 3 && strcasecmp(ZSTR_VAL(str), "yes") == 0) ||
        (ZSTR_LEN(str) == 2 && strcasecmp(ZSTR_VAL(str), "on") == 0)) {
        return 1;
    }
    return atoi(ZSTR_VAL(str)) != 0; // NOLINT
}
#endif
