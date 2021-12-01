// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include "dddefs.h"
#include <zend_API.h>
#include <zend_globals.h>
#include <zend_ini.h>

#define DD_TESTING_NS "datadog\\appsec\\testing\\"

typedef struct _dd_ini_setting {
    const char *name_suff; // the part after 'ddappsec'
    const char *default_value;
    uint16_t name_suff_len;
    uint16_t default_value_len;
    int modifiable;
    ZEND_INI_MH((*on_modify));

    /* optional */
    // either a pointer to the read global variable,
    // or a pointer to int representing the tsrm offset
    void *global_variable;
    size_t field_offset;
} dd_ini_setting;

#define DD_INI_ENV(name_suff, default_value, modifiable, on_modify)            \
    ((dd_ini_setting){(name_suff), (default_value), sizeof(name_suff "") - 1,  \
        sizeof(default_value "") - 1, (modifiable), (on_modify), NULL, 0})

#ifdef ZTS
#    define DD_INI_ENV_GLOB(name_suff, default_value, modifiable, on_modify,   \
        field_name, glob_type, glob_name)                                      \
        ((dd_ini_setting){(name_suff), (default_value),                        \
            sizeof(name_suff "") - 1, sizeof(default_value "") - 1,            \
            (modifiable), (on_modify), &(glob_name##_id),                      \
            offsetof(glob_type, field_name)})
#else
#    define DD_INI_ENV_GLOB(name_suff, default_value, modifiable, on_modify,   \
        field_name, glob_type, glob_name)                                      \
        ((dd_ini_setting){(name_suff), (default_value),                        \
            sizeof(name_suff "") - 1, sizeof(default_value "") - 1,            \
            modifiable, (on_modify), &(glob_name),                             \
            offsetof(glob_type, field_name)})
#endif

void dd_phpobj_startup(int module_number);
dd_result dd_phpobj_reg_funcs(const zend_function_entry *entries);
dd_result dd_phpobj_reg_ini(const zend_ini_entry_def *entries);
void dd_phpobj_reg_ini_env(const dd_ini_setting *sett);
static inline void dd_phpobj_reg_ini_envs(const dd_ini_setting *setts)
{
    for (__auto_type s = setts; s->name_suff; s++) {
        dd_phpobj_reg_ini_env(s);
    }
}
void dd_phpobj_reg_long_const(
    const char *name, size_t name_len, zend_long value, int flags);
void dd_phpobj_shutdown(void);

