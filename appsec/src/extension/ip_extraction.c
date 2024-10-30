// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#include "ip_extraction.h"
#include "compatibility.h"
#include "configuration.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_objects.h"

static void _register_testing_objects(void);

void dd_ip_extraction_startup() { _register_testing_objects(); }

static PHP_FUNCTION(datadog_appsec_testing_extract_ip_addr)
{
    zval *arr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &arr) == FAILURE) {
        return;
    }

    zend_string *res = dd_ip_extraction_find(arr);
    if (!res) {
        return;
    }

    RETURN_STR(res);
}

#include "php_compat.h"

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(extract_ip_addr, 0, 1, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(1, , IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "extract_ip_addr", PHP_FN(datadog_appsec_testing_extract_ip_addr), extract_ip_addr, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects()
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }

    dd_phpobj_reg_funcs(functions);
}
