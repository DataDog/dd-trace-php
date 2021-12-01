// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <php.h>

// target the definitions of PHP 7.4

#if PHP_VERSION_ID < 70100
// for arginfo purposes
#    define IS_VOID IS_UNDEF
#    define HT_IS_PACKED(ht) 0
#    define HT_IS_WITHOUT_HOLES(ht) 0
#endif

#if PHP_VERSION_ID < 70200
#    undef ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX
#    define ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(                           \
        name, return_reference, required_num_args, type, allow_null)           \
        static const zend_internal_arg_info name[] = {                         \
            {(const char *)(zend_uintptr_t)(required_num_args), NULL, type,    \
                return_reference, allow_null, 0},

// persistent must be true iif we're on the startup phase
static zend_always_inline zend_string *zend_string_init_interned(
    const char *str, size_t len, int persistent)
{
    zend_string *ret = zend_string_init(str, len, persistent);
    return zend_new_interned_string(ret);
}
#endif

#if PHP_VERSION_ID < 70300
zend_bool zend_ini_parse_bool(zend_string *str);
#   define zend_string_efree zend_string_free
#endif

#if PHP_VERSION_ID < 70400
#define tsrm_env_lock()
#define tsrm_env_unlock()
#endif
