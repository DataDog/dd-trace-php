// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// application/x-www-form-urlencoded bodies of downstream requests, parsed
// into the array parse_str() would give, without going through PHP's
// treat_data: that one stops at max_input_vars with a warning (which the
// app's error handler would get, possibly from inside a libcurl callback),
// and passes the values to the SAPI's input filter. Here the body is split
// into fields and url-decoded here, and each field is registered with PHP's
// own php_register_variable_ex(), so that names are mangled and nested as
// PHP does (including the version specific details). The number of fields is
// only bounded by the size of the body, and the input filter is not involved.
//
// One thing php_register_variable_ex() does cannot be left to it: a name
// nested deeper than max_input_nesting_level makes it raise an E_WARNING
// when display_errors is off. Such a name is detected beforehand by a
// predictor of its loop, and then handled without the call: the (mangled)
// base name is dropped, as PHP does.
//
// Differences from parse_str(), deliberate: the separator is always '&'
// (arg_separator.input is about the app's own input, not about what a
// downstream server does). The number of fields is bounded by the memory left:
// the splitting stops, keeping what was parsed, when the estimated memory of
// the array would not fit in the memory limit.

#include <php.h>

#include "../attributes.h"

// *out: a new array
void dd_form_urlencoded_parse(
    const char *nonnull data, size_t len, zval *nonnull out);
