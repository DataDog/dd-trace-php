// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "dddefs.h"
#include <zend_API.h>
#include <zend_globals.h>
#include <zend_ini.h>

#define DD_APPSEC_NS "datadog\\appsec\\"
#define DD_TESTING_NS "datadog\\appsec\\testing\\"

void dd_phpobj_startup(int module_number);
dd_result dd_phpobj_reg_funcs(const zend_function_entry *entries);
void dd_phpobj_reg_long_const(
    const char *name, size_t name_len, zend_long value, int flags);
void dd_phpobj_shutdown(void);
