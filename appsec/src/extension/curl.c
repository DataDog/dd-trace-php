// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// clang-format off
#include <php.h>  // (must come before php_streams.h)
// clang-format on
#include <main/php_streams.h>

#include "compatibility.h"
#include "php_compat.h"
#include "php_objects.h"

static PHP_FUNCTION(datadog_appsec_fflush_stdiocast)
{
    zval *stream_zv;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_RESOURCE(stream_zv)
    ZEND_PARSE_PARAMETERS_END();

    php_stream *stream = NULL;
    php_stream_from_zval(stream, stream_zv);

    if (stream->stdiocast != NULL) {
        int result = fflush(stream->stdiocast);
        RETURN_BOOL(result == 0);
    }

    RETURN_TRUE;
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(fflush_stdiocast_arginfo, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_INFO(0, stream)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_APPSEC_NS "fflush_stdiocast", PHP_FN(datadog_appsec_fflush_stdiocast), fflush_stdiocast_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

void dd_curl_register_functions(void) { dd_phpobj_reg_funcs(functions); }
