// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef BACKTRACE_H
#define BACKTRACE_H

#include <SAPI.h>
#include <Zend/zend_builtin_functions.h>
#include <php.h>

#include <stdbool.h>

void dd_backtrace_startup();
void generate_backtrace(zend_string *id, zval *dd_backtrace);
bool report_backtrace(zend_string *id);

#endif // BACKTRACE_H
