// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
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

dd_result dd_phpobj_reg_ini(const zend_ini_entry_def *entries)
{
    int res = zend_register_ini_entries(entries, _module_number);
    return res == FAILURE ? dd_error : dd_success;
}

#define NAME_PREFIX "ddappsec."
#define NAME_PREFIX_LEN (sizeof(NAME_PREFIX) - 1)
#define ENV_NAME_PREFIX "DD_APPSEC_"
#define ENV_NAME_PREFIX_LEN (sizeof(ENV_NAME_PREFIX) - 1)

static zend_string *nullable _fetch_from_env(const char *name, size_t name_len);
void dd_phpobj_reg_ini_env(const dd_ini_setting *sett)
{
    size_t name_len = NAME_PREFIX_LEN + sett->name_suff_len;
    char *name = emalloc(name_len + 1);
    memcpy(name, NAME_PREFIX, NAME_PREFIX_LEN);
    memcpy(name + NAME_PREFIX_LEN, sett->name_suff, sett->name_suff_len);
    name[name_len] = '\0';

    zend_string *env_def =
        _fetch_from_env(sett->name_suff, sett->name_suff_len);

    const zend_ini_entry_def defs[] = {
        {
            .name = name,
            .name_length = (uint16_t)name_len,
            .modifiable = sett->modifiable,
            .value = env_def ? ZSTR_VAL(env_def) : sett->default_value,
            .value_length = env_def ? ZSTR_LEN(env_def)
                                    : (uint32_t)sett->default_value_len,

            .on_modify = sett->on_modify,
            .mh_arg1 = (void *)(uintptr_t)sett->field_offset,
            .mh_arg2 = sett->global_variable,
        },
        {0}};

    dd_phpobj_reg_ini(defs);

    if (env_def) {
        zend_string_efree(env_def);
    }
    efree(name);
}

static zend_string *nullable _fetch_from_env(const char *name, size_t name_len)
{
    size_t env_name_len = ENV_NAME_PREFIX_LEN + name_len;
    char *env_name = emalloc(env_name_len + 1);
    memcpy(env_name, ENV_NAME_PREFIX, ENV_NAME_PREFIX_LEN);

    const char *r = name;
    const char *rend = &name[name_len];
    char *w = &env_name[ENV_NAME_PREFIX_LEN];
    for (; r < rend; r++) {
        char c = *r;
        if (c >= 'a' && c <= 'z') {
            c -= 'a' - 'A';
        }
        *w++ = c;
    }
    *w = '\0';

    tsrm_env_lock();
    const char *res = getenv(env_name); //NOLINT
    tsrm_env_unlock();
    efree(env_name);

    if (!res || *res == '\0') {
        return NULL;
    }
    return zend_string_init(res, strlen(res), 0);
}

void dd_phpobj_reg_long_const(
    const char *name, size_t name_len, zend_long value, int flags)
{
    zend_register_long_constant(name, name_len, value, flags, _module_number);
}

void dd_phpobj_shutdown()
{
    zend_llist_destroy(&_function_entry_arrays);
    zend_unregister_ini_entries(_module_number);
}

static void _unregister_functions(void *zfe_arr_vp)
{
    const zend_function_entry **zfe_arr = zfe_arr_vp;
    int count = 0;
    for (const zend_function_entry *p = *zfe_arr; p->fname != NULL;
         p++, count++) {}
    zend_unregister_functions(*zfe_arr, count, NULL);
}
