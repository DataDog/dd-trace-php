extern "C" {
#include "zai_sapi/zai_sapi_io.h"
}

#include <catch2/catch.hpp>
#include <cstring>

/************************* zai_sapi_io_write_stdout *************************/

TEST_CASE("basic write to stdout", "[zai_sapi_io]") {
    char out[] = "Hello world\n";
    size_t len = (sizeof out - 1);

    size_t wrote_len = zai_sapi_io_write_stdout(out, len);

    REQUIRE(wrote_len == len);
    // TODO Capture stdout into a buffer?
    //REQUIRE(strcmp("Hello world\n", buf) == 0);
}

TEST_CASE("write empty string stdout", "[zai_sapi_io]") {
    char out[] = "";
    size_t len = (sizeof out - 1);

    size_t wrote_len = zai_sapi_io_write_stdout(out, len);

    REQUIRE(wrote_len == 0);
}

TEST_CASE("write NULL to stdout", "[zai_sapi_io]") {
    size_t wrote_len = zai_sapi_io_write_stdout(NULL, 42);
    REQUIRE(wrote_len == 0);
}

TEST_CASE("write 0 len to stdout", "[zai_sapi_io]") {
    char out[] = "Hello world\n";
    size_t len = 0;

    size_t wrote_len = zai_sapi_io_write_stdout(out, len);

    REQUIRE(wrote_len == 0);
}

/************************* zai_sapi_io_write_stderr *************************/

TEST_CASE("basic write to stderr", "[zai_sapi_io]") {
    char err[] = "Hello error\n";
    size_t len = (sizeof err - 1);

    size_t wrote_len = zai_sapi_io_write_stderr(err, len);

    REQUIRE(wrote_len == len);
    // TODO Capture stderr into a buffer?
    //REQUIRE(strcmp("Hello error\n", buf) == 0);
}

TEST_CASE("write empty string to stderr", "[zai_sapi_io]") {
    char err[] = "";
    size_t len = (sizeof err - 1);

    size_t wrote_len = zai_sapi_io_write_stderr(err, len);

    REQUIRE(wrote_len == len);
}

TEST_CASE("write NULL to stderr", "[zai_sapi_io]") {
    size_t wrote_len = zai_sapi_io_write_stderr(NULL, 42);
    REQUIRE(wrote_len == 0);
}

TEST_CASE("write 0 len to stderr", "[zai_sapi_io]") {
    char err[] = "Hello error\n";
    size_t len = 0;

    size_t wrote_len = zai_sapi_io_write_stderr(err, len);

    REQUIRE(wrote_len == 0);
}

/*********************** zai_sapi_io_format_error_log ***********************/

TEST_CASE("error_log formatted message", "[zai_sapi_io]") {
    char buf[ZAI_SAPI_IO_ERROR_LOG_MAX_BUF_SIZE];
    char message[] = "Error: E_ERROR";

    size_t wrote_len = zai_sapi_io_format_error_log(message, buf, sizeof buf);

    REQUIRE(wrote_len == 15);
    REQUIRE(strcmp("Error: E_ERROR\n", buf) == 0);
}

TEST_CASE("error_log format with exact buffer size", "[zai_sapi_io]") {
    char buf[14 /* message len */ + 1 /* new line */ + 1 /* null terminator */];
    char message[] = "Error: E_ERROR";

    size_t wrote_len = zai_sapi_io_format_error_log(message, buf, sizeof buf);

    REQUIRE(wrote_len == 15);
    REQUIRE(strcmp("Error: E_ERROR\n", buf) == 0);
}

TEST_CASE("error_log format with one truncated character", "[zai_sapi_io]") {
    char buf[14 /* message len */ + 1 /* null terminator */ /* no room for new line */];
    char message[] = "Error: E_ERROR";

    size_t wrote_len = zai_sapi_io_format_error_log(message, buf, sizeof buf);

    REQUIRE(wrote_len == 14);
    REQUIRE(strcmp("Error: E_ERROR", buf) == 0);
}

TEST_CASE("error_log format truncated", "[zai_sapi_io]") {
    char buf[6];
    char message[] = "Error: E_ERROR";

    size_t wrote_len = zai_sapi_io_format_error_log(message, buf, sizeof buf);

    REQUIRE(wrote_len == 5);
    REQUIRE(strcmp("Error", buf) == 0);
}

TEST_CASE("error_log with NULL message", "[zai_sapi_io]") {
    char buf[256] = {0};

    size_t wrote_len = zai_sapi_io_format_error_log(NULL, buf, sizeof buf);

    REQUIRE(wrote_len == 0);
    REQUIRE(*buf == '\0');
}

TEST_CASE("error_log with NULL buffer", "[zai_sapi_io]") {
    char message[] = "Error: E_ERROR";

    size_t wrote_len = zai_sapi_io_format_error_log(message, NULL, 42);

    REQUIRE(wrote_len == 0);
}

TEST_CASE("error_log with zero buffer size", "[zai_sapi_io]") {
    char buf[256] = {0};
    char message[] = "Error: E_ERROR";

    size_t wrote_len = zai_sapi_io_format_error_log(message, buf, 0);

    REQUIRE(wrote_len == 0);
    REQUIRE(*buf == '\0');
}
