// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "attributes.h"
#include <php.h>

#ifdef ZTS
#    define THREAD_LOCAL_ON_ZTS __thread
#else
#    define THREAD_LOCAL_ON_ZTS
#endif

#ifdef ZTS
#    include <TSRM.h>
#    define TSRM_MUTEX_LOCK(x) tsrm_mutex_lock(x);
#    define TSRM_MUTEX_UNLOCK(x) tsrm_mutex_unlock(x);
#else
#    define TSRM_MUTEX_LOCK(x)
#    define TSRM_MUTEX_UNLOCK(x)
#endif

typedef enum {
    php_array_type_sequential = 1,
    php_array_type_associative,
} dd_php_array_type;

dd_php_array_type dd_php_determine_array_type(const zend_array *nonnull);

#define ZEND_INI_MH_UNUSED()                                                   \
    do {                                                                       \
        (void)entry;                                                           \
        (void)mh_arg1;                                                         \
        (void)mh_arg2;                                                         \
        (void)mh_arg3;                                                         \
        (void)stage;                                                           \
    } while (0)

zval *nullable dd_php_get_autoglobal(
    int track_var, const char *nonnull name, size_t len);
const zend_array *nonnull dd_get_superglob_or_equiv(const char *nonnull name,
    size_t name_len, int track, zend_array *nullable equiv);
zend_string *nullable dd_php_get_string_elem(
    const zend_array *nullable arr, zend_string *nonnull zstr);
zend_string *nullable dd_php_get_string_elem_cstr(
    const zend_array *nullable arr, const char *nonnull name, size_t len);
