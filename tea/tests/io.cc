extern "C" {
#include <private/io.h>
}

#include <include/testing/catch2.hpp>
#include <cstring>

/************************* tea_io_write_stdout *************************/

TEA_TEST_CASE_BARE("tea/io", "basic write to stdout", {
    char out[] = "Hello world\n";
    size_t len = (sizeof out - 1);

    size_t wrote_len = tea_io_write_stdout(out, len);

    REQUIRE(wrote_len == len);
    // TODO Capture stdout into a buffer?
    //REQUIRE(strcmp("Hello world\n", buf) == 0);
})

TEA_TEST_CASE_BARE("tea/io", "write empty string stdout", {
    char out[] = "";
    size_t len = (sizeof out - 1);

    size_t wrote_len = tea_io_write_stdout(out, len);

    REQUIRE(wrote_len == 0);
})

TEA_TEST_CASE_BARE("tea/io", "write NULL to stdout", {
    size_t wrote_len = tea_io_write_stdout(NULL, 42);
    REQUIRE(wrote_len == 0);
})

TEA_TEST_CASE_BARE("tea/io", "write 0 len to stdout", {
    char out[] = "Hello world\n";
    size_t len = 0;

    size_t wrote_len = tea_io_write_stdout(out, len);

    REQUIRE(wrote_len == 0);
})

/************************* tea_io_write_stderr *************************/

TEA_TEST_CASE_BARE("tea/io", "basic write to stderr", {
    char err[] = "Hello error\n";
    size_t len = (sizeof err - 1);

    size_t wrote_len = tea_io_write_stderr(err, len);

    REQUIRE(wrote_len == len);
    // TODO Capture stderr into a buffer?
    //REQUIRE(strcmp("Hello error\n", buf) == 0);
})

TEA_TEST_CASE_BARE("tea/io", "write empty string to stderr", {
    char err[] = "";
    size_t len = (sizeof err - 1);

    size_t wrote_len = tea_io_write_stderr(err, len);

    REQUIRE(wrote_len == len);
})

TEA_TEST_CASE_BARE("tea/io", "write NULL to stderr", {
    size_t wrote_len = tea_io_write_stderr(NULL, 42);
    REQUIRE(wrote_len == 0);
})

TEA_TEST_CASE_BARE("tea/io", "write 0 len to stderr", {
    char err[] = "Hello error\n";
    size_t len = 0;

    size_t wrote_len = tea_io_write_stderr(err, len);

    REQUIRE(wrote_len == 0);
})

/*********************** tea_io_format_error_log ***********************/

TEA_TEST_CASE_BARE("tea/io", "error_log formatted message", {
    char buf[TEA_IO_ERROR_LOG_MAX_BUF_SIZE];
    char message[] = "Error: E_ERROR";

    size_t wrote_len = tea_io_format_error_log(message, buf, sizeof buf);

    REQUIRE(wrote_len == 15);
    REQUIRE(strcmp("Error: E_ERROR\n", buf) == 0);
})

TEA_TEST_CASE_BARE("tea/io", "error_log format with exact buffer size", {
    char buf[14 /* message len */ + 1 /* new line */ + 1 /* null terminator */];
    char message[] = "Error: E_ERROR";

    size_t wrote_len = tea_io_format_error_log(message, buf, sizeof buf);

    REQUIRE(wrote_len == 15);
    REQUIRE(strcmp("Error: E_ERROR\n", buf) == 0);
})

TEA_TEST_CASE_BARE("tea/io", "error_log format with one truncated character", {
    char buf[14 /* message len */ + 1 /* null terminator */ /* no room for new line */];
    char message[] = "Error: E_ERROR";

    size_t wrote_len = tea_io_format_error_log(message, buf, sizeof buf);

    REQUIRE(wrote_len == 14);
    REQUIRE(strcmp("Error: E_ERROR", buf) == 0);
})

TEA_TEST_CASE_BARE("tea/io", "error_log format truncated", {
    char buf[6];
    char message[] = "Error: E_ERROR";

    size_t wrote_len = tea_io_format_error_log(message, buf, sizeof buf);

    REQUIRE(wrote_len == 5);
    REQUIRE(strcmp("Error", buf) == 0);
})

TEA_TEST_CASE_BARE("tea/io", "error_log with NULL message", {
    char buf[256] = {0};

    size_t wrote_len = tea_io_format_error_log(NULL, buf, sizeof buf);

    REQUIRE(wrote_len == 0);
    REQUIRE(*buf == '\0');
})

TEA_TEST_CASE_BARE("tea/io", "error_log with NULL buffer", {
    char message[] = "Error: E_ERROR";

    size_t wrote_len = tea_io_format_error_log(message, NULL, 42);

    REQUIRE(wrote_len == 0);
})

TEA_TEST_CASE_BARE("tea/io", "error_log with zero buffer size", {
    char buf[256] = {0};
    char message[] = "Error: E_ERROR";

    size_t wrote_len = tea_io_format_error_log(message, buf, 0);

    REQUIRE(wrote_len == 0);
    REQUIRE(*buf == '\0');
})
