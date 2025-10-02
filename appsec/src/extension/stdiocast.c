// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// clang-format off
#include <php.h> // (must come before php_streams.h)
// clang-format on
#include <main/php_streams.h>

#include "ddappsec.h"
#include <ext/compatibility.h> // NOLINT (must come before ddappsec_arginfo.h)

// When a PHP stream is given to curl (e.g. with CURLOPT_FILE), curl writes to
// a FILE* obtained by casting the stream. That FILE* has its own buffer, which
// must be flushed before the data written by curl can be seen by reading the
// stream (or a filter appended to it) from PHP.
PHP_FUNCTION(datadog_appsec_fflush_stdiocast)
{
    zval *stream_zv;

    ZEND_PARSE_PARAMETERS_START(1, 1) // NOLINT
    Z_PARAM_RESOURCE(stream_zv)
    ZEND_PARSE_PARAMETERS_END();

    php_stream *stream = NULL;
    php_stream_from_zval(stream, stream_zv);

    if (stream->stdiocast != NULL) {
        RETURN_BOOL(fflush(stream->stdiocast) == 0);
    }

    RETURN_TRUE;
}
