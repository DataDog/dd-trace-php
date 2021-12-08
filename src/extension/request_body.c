// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "request_body.h"
#include "ddappsec.h"
#include "logging.h"
#include <SAPI.h>

zend_string *nonnull dd_request_body_buffered(size_t limit)
{
    php_stream *stream = SG(request_info).request_body;

    if (!stream) {
        return ZSTR_EMPTY_ALLOC();
    }

    zend_off_t prev_pos = php_stream_tell(stream);

    mlog(dd_log_debug, "Copying request body from stream");
    php_stream_rewind(stream);
    zend_string *body_data =
        php_stream_copy_to_mem(stream, limit, 0 /* not persistent */);

    if (prev_pos != php_stream_tell(stream)) {
        mlog(dd_log_debug, "Restoring stream to position %" PRIi64,
            (int64_t)prev_pos);
        int ret = php_stream_seek(stream, prev_pos, SEEK_SET);
        if (ret == -1) {
            mlog(dd_log_warning, "php_stream_seek failed");
        }
    }

    if (body_data == NULL) {
        mlog(dd_log_info, "Could not read any data from body stream");
        body_data = ZSTR_EMPTY_ALLOC();
    }

    return body_data;
}
