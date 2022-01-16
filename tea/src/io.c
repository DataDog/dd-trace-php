#include <private/io.h>

static inline size_t tea_io_write(int fd, const char *str, size_t len) {
    if (!fd || !str || len <= 0) {
        return 0;
    }

    ssize_t wrote_len = write(fd, str, len);

    if (wrote_len > 0) {
        return (size_t)wrote_len;
    }

    return 0;
}

size_t tea_io_write_stdout(const char *str, size_t len) { return tea_io_write(STDOUT_FILENO, str, len); }

size_t tea_io_write_stderr(const char *str, size_t len) { return tea_io_write(STDERR_FILENO, str, len); }

size_t tea_io_format_error_log(const char *message, char *buf, size_t buf_size) {
    if (!message || !buf || buf_size <= 0) return 0;
    size_t len = snprintf(buf, buf_size, "%s\n", message);
    return len < buf_size ? len : (buf_size - 1);
}
