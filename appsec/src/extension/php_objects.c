// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <SAPI.h>
#include <zend_API.h>
#include <zend_alloc.h>

#include "attributes.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "php_compat.h"
#include "php_objects.h"

static int _module_number;
static zend_llist _function_entry_arrays;

static void _unregister_functions(void *zfe_arr_vp);

void dd_phpobj_startup(int module_number)
{
    _module_number = module_number;
    zend_llist_init(&_function_entry_arrays,
        sizeof(const zend_function_entry *), _unregister_functions,
        1 /* persistent */);
}

dd_result dd_phpobj_reg_funcs(const zend_function_entry *entries)
{
    int res = zend_register_functions(NULL, entries, NULL, MODULE_PERSISTENT);
    if (res == FAILURE) {
        return dd_error;
    }
    zend_llist_add_element(&_function_entry_arrays, &entries);
    return dd_success;
}

void dd_phpobj_reg_long_const(
    const char *name, size_t name_len, zend_long value, int flags)
{
    zend_register_long_constant(name, name_len, value, flags, _module_number);
}

void dd_phpobj_shutdown() { zend_llist_destroy(&_function_entry_arrays); }

static void _unregister_functions(void *zfe_arr_vp)
{
    const zend_function_entry **zfe_arr = zfe_arr_vp;
    int count = 0;
    for (const zend_function_entry *p = *zfe_arr; p->fname != NULL;
         p++, count++) {}
    zend_unregister_functions(*zfe_arr, count, NULL);
}
