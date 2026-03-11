// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#ifdef TESTING
// NOLINTNEXTLINE(misc-header-include-cycle)
#    include <php.h>
#    include <string.h>
#    include <unistd.h>

#    include "attributes.h"
#    include "compatibility.h"
#    include "logging.h"
#    include "php_compat.h"
#    include "php_objects.h"
#    include "test_mock_transport.h"

/* In test mode the PHP script creates a socketpair and tells the extension
 * which fd to use via datadog\appsec\testing\set_mock_helper_fd().  The fd is
 * dup()'d so it remains valid even if PHP closes the original stream. */

bool dd_testing_send_active;

struct PACKED _mock_header {
    char code[4]; /* "dds\0" */
    uint32_t size;
};

static THREAD_LOCAL_ON_ZTS int _mock_fd = -1;

ddog_AppsecCResponse dd_testing_mock_send_appsec_message(
    ddog_CharSlice session_id, uint64_t client_id, ddog_CharSlice data)
{
    (void)session_id;
    (void)client_id;

    if (_mock_fd < 0) {
        mlog(dd_log_debug, "mock sidecar: no fd set");
        return (ddog_AppsecCResponse){0};
    }

    int fd = _mock_fd;

    /* Write the full request (data already contains the dds\0 header + body) */
    const char *buf = data.ptr;
    size_t remaining = data.len;
    while (remaining > 0) {
        ssize_t written = write(fd, buf, remaining);
        if (written <= 0) {
            mlog(dd_log_debug, "mock sidecar: write() failed");
            close(fd);
            _mock_fd = -1;
            return (ddog_AppsecCResponse){0};
        }
        buf += (size_t)written;
        remaining -= (size_t)written;
    }

    /* Read response header */
    struct _mock_header hdr;
    char *hbuf = (char *)&hdr;
    size_t hrem = sizeof(hdr);
    while (hrem > 0) {
        ssize_t r = read(fd, hbuf, hrem);
        if (r <= 0) {
            mlog(dd_log_debug,
                "mock sidecar: read header failed (EOF or error)");
            close(fd);
            _mock_fd = -1;
            return (ddog_AppsecCResponse){0};
        }
        hbuf += (size_t)r;
        hrem -= (size_t)r;
    }

    /* Read response body */
    size_t total_len = sizeof(hdr) + (size_t)hdr.size;
    uint8_t *response_buf = malloc(total_len);
    if (!response_buf) {
        return (ddog_AppsecCResponse){0};
    }

    memcpy(response_buf, &hdr, sizeof(hdr));
    char *rbuf = (char *)response_buf + sizeof(hdr);
    size_t rrem = (size_t)hdr.size;
    while (rrem > 0) {
        ssize_t r = read(fd, rbuf, rrem);
        if (r <= 0) {
            mlog(dd_log_debug, "mock sidecar: read body failed (EOF or error)");
            free(response_buf);
            close(fd);
            _mock_fd = -1;
            return (ddog_AppsecCResponse){0};
        }
        rbuf += (size_t)r;
        rrem -= (size_t)r;
    }

    return (ddog_AppsecCResponse){
        .ptr = response_buf,
        .len = total_len,
        .capacity = total_len,
        .disconnect = false,
    };
}

/* PHP function: datadog\appsec\testing\set_mock_helper_fd(resource $stream):
 * bool Registers the PHP stream as the fd the mock transport will use for
 * communicating with mock_helper.  The fd is dup()'d so the PHP-side stream
 * can be closed independently. */
static PHP_FUNCTION(datadog_appsec_testing_set_mock_helper_fd) // NOLINT
{
    zval *zstream;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "r", &zstream) == FAILURE) {
        RETURN_FALSE;
    }

    php_stream *stream;
    php_stream_from_zval_no_verify(stream, zstream);
    if (!stream) {
        mlog(dd_log_warning, "set_mock_helper_fd: invalid stream argument");
        RETURN_FALSE;
    }

    int fd = -1;
    if (php_stream_cast(stream, PHP_STREAM_AS_FD | PHP_STREAM_CAST_INTERNAL,
            (void **)&fd, 1) != SUCCESS ||
        fd < 0) {
        mlog(dd_log_warning,
            "set_mock_helper_fd: cannot extract fd from stream");
        RETURN_FALSE;
    }

    int new_fd = dup(fd); // NOLINT(android-cloexec-dup)
    if (new_fd < 0) {
        mlog_err(dd_log_warning, "set_mock_helper_fd: dup()");
        RETURN_FALSE;
    }

    if (_mock_fd >= 0) {
        close(_mock_fd);
    }
    _mock_fd = new_fd;
    RETURN_TRUE;
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(set_fd_arginfo, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_INFO(0, stream)
ZEND_END_ARG_INFO()
// clang-format on

// clang-format off
static const zend_function_entry _functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "set_mock_helper_fd",
        PHP_FN(datadog_appsec_testing_set_mock_helper_fd), set_fd_arginfo, 0,
        NULL, NULL)
    PHP_FE_END
};
// clang-format on

void dd_testing_setup_mock_transport(void)
{
    dd_testing_send_active = true;
    dd_phpobj_reg_funcs(_functions);
}
#endif /* TESTING */
