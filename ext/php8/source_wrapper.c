#include <php.h>
#include <php_streams.h>
#include <ext/standard/php_filestat.h>
#include "source_wrapper.h"
#include "compatibility.h"

static char generated_api[] = {
#include "../compiled_source_api.txt"
};

static char generated_internal[] = {
#include "../compiled_source_internal.txt"
};

typedef struct {
    const char *buf;
    size_t buflen;
    size_t pos;
} dd_stream_data;

static ssize_t ddtrace_stream_read(php_stream *stream, char *buf, size_t count)
{
    dd_stream_data *data = (dd_stream_data *) stream->abstract;

    size_t len = data->buflen - data->pos;
    if (count < len) {
        len = count;
    }

    memcpy(buf, data->buf + data->pos, len);

    data->pos += len;
    stream->eof = data->pos == data->buflen;

    return (ssize_t) len;
}

static int ddtrace_stream_close(php_stream *stream, int close_handle)
{
    UNUSED(close_handle);
    efree(stream->abstract);
    return 0;
}

static int ddtrace_stream_stat(php_stream *stream, php_stream_statbuf *ssb)
{
    UNUSED(stream, ssb);
    return 0;
}

const php_stream_ops ddtrace_stream_ops = {
        NULL, /* write */
        ddtrace_stream_read,  /* read */
        ddtrace_stream_close, /* close */
        NULL, /* flush */
        "ddtrace stream",
        NULL,  /* seek */
        NULL,              /* cast */
        ddtrace_stream_stat,  /* stat */
        NULL, /* set option */
};

static int ddtrace_stream_stat_url(php_stream_wrapper *wrapper, const char *url, int flags, php_stream_statbuf *ssb, php_stream_context *context)
{
    UNUSED(wrapper, context, ssb);
    return (flags & FS_EXISTS) && (strcmp(url, "ddtrace://api.php") == 0 || strcmp(url, "ddtrace://internal.php") == 0) ? SUCCESS : FAILURE;
}

static php_stream *ddtrace_stream_open_url(php_stream_wrapper *wrapper, const char *path, const char *mode, int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) {
    UNUSED(options, wrapper, context STREAMS_REL_CC);

    if (mode[0] != 'r') {
        return NULL;
    }

    const char *buf = NULL;
    size_t buflen;

    if (strcmp(path, "ddtrace://api.php") == 0) {
        buf = generated_api;
        buflen = sizeof(generated_api) - 1;
    }
    if (strcmp(path, "ddtrace://internal.php") == 0) {
        buf = generated_internal;
        buflen = sizeof(generated_internal) - 1;
    }

    if (!buf) {
        return NULL;
    }

    dd_stream_data *data = ecalloc(1, sizeof(*data));
    data->buf = buf;
    data->buflen = buflen;

    if (opened_path) {
        *opened_path = zend_string_init(path, strlen(path), 0);
    }

    return php_stream_alloc(&ddtrace_stream_ops, data, NULL, mode);
}

const php_stream_wrapper_ops ddtrace_stream_wops = {
        ddtrace_stream_open_url,
        NULL, /* stream_close */
        NULL, /* stat */
        ddtrace_stream_stat_url, /* stat_url */
        NULL, /* opendir */
        "ddtrace",
        NULL, /* unlink */
        NULL, /* rename */
        NULL, /* mkdir */
        NULL, /* rmdir */
        NULL
};

const php_stream_wrapper dd_stream_ddtrace_wrapper = {
        &ddtrace_stream_wops,
        NULL,
        0 /* is_url */
};

void ddtrace_register_source_stream_wrapper(void) {
    php_register_url_stream_wrapper("ddtrace", &dd_stream_ddtrace_wrapper);
}
