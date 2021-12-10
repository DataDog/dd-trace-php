#ifndef ZAI_SAPI_IO_H
#define ZAI_SAPI_IO_H

#include <stddef.h>

/* Writes to stdout (unbuffered).
 *
 * Output buffering is handled by PHP. Once PHP is ready to flush it sends the
 * output to the SAPI's unbuffered write operation. We do not buffer when
 * writing to stdout as to not conflict with the PHP output buffer.
 */
size_t zai_sapi_io_write_stdout(const char *str, size_t len);

/* Writes to stderr (unbuffered).
 *
 * As PHP does not buffer stderr, it is not required to have an unbuffered
 * write operation for stderr at the SAPI level. But we still perform
 * unbuffered write operations to stderr for consistency with stdout.
 */
size_t zai_sapi_io_write_stderr(const char *str, size_t len);

/* Flushes stdout (NOOP).
 *
 * ZAI SAPI only supports unbuffered write operations.
 */
static inline void zai_sapi_io_flush(void *server_context) { (void)server_context; }

/* The max stack-allocated buffer size to store the formatted error message in
 * the SAPI error logger.
 *
 * This is the same buffer size that the PHP-FPM SAPI uses.
 * https://github.com/php/php-src/blob/0bc6a66/sapi/fpm/fpm/zlog.c#L20
 */
#define ZAI_SAPI_IO_ERROR_LOG_MAX_BUF_SIZE 2048

/* Formats the error message for the SAPI error logger and copy the formatted
 * message into the buffer. Returns the length of the string written into the
 * buffer excluding the null terminator.
 *
 * E_ERROR and friends go to the SAPI error logger if the 'error_log' INI
 * setting is not set.
 * https://www.php.net/manual/en/errorfunc.configuration.php#ini.error-log
 */
size_t zai_sapi_io_format_error_log(const char *message, char *buf, size_t buf_size);

#endif  // ZAI_SAPI_IO_H
