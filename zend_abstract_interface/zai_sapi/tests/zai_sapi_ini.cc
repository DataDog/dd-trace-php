extern "C" {
#include "zai_sapi/zai_sapi_ini.h"
}

#include <catch2/catch.hpp>
#include <cstring>

/************************ zai_sapi_ini_entries_alloc ************************/

TEST_CASE("alloc INI entries", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    REQUIRE(entries != NULL);
    REQUIRE(len == 8);
    REQUIRE(strcmp("foo=bar\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
}

TEST_CASE("alloc empty INI entries", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("", &entries);

    REQUIRE(entries != NULL);
    REQUIRE(len == 0);
    REQUIRE(strcmp("", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
}

TEST_CASE("alloc INI does not overwrite existing ptr", "[zai_sapi_ini]") {
    char *entries = (char *)(void *)1;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    REQUIRE(entries == (char *)(void *)1);
    REQUIRE(len == -1);
}

TEST_CASE("alloc NULL INI src entries", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc(NULL, &entries);

    REQUIRE(entries == NULL);
    REQUIRE(len == -1);
}

TEST_CASE("alloc NULL INI dest", "[zai_sapi_ini]") {
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", NULL);

    REQUIRE(len == -1);
}

/************************* zai_sapi_ini_entries_free *************************/

TEST_CASE("freeing entries sets NULL pointer", "[zai_sapi_ini]") {
    char *entries = NULL;
    zai_sapi_ini_entries_alloc("foo=bar", &entries);

    REQUIRE(entries != NULL);

    zai_sapi_ini_entries_free(&entries);

    REQUIRE(entries == NULL);
}

TEST_CASE("free NULL pointer", "[zai_sapi_ini]") {
    char *entries = NULL;

    zai_sapi_ini_entries_free(&entries);
    zai_sapi_ini_entries_free(NULL);

    REQUIRE(entries == NULL);
}

/******************** zai_sapi_ini_entries_realloc_append ********************/

TEST_CASE("append INI entry", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == 16);
    REQUIRE(strcmp("foo=bar\nabc=123\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
}

TEST_CASE("append INI entry from empty starting point", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == 8);
    REQUIRE(strcmp("abc=123\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
}

TEST_CASE("append several INI entries", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\nabc=123\n", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "123");
    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "extension", "ddtrace.so");
    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "ddtrace.request_init_hook", "/path/to/init_hook.php");

    REQUIRE(entries != NULL);
    REQUIRE(len == 94);
    REQUIRE(strcmp("foo=bar\nabc=123\nabc=123\nextension=ddtrace.so\nddtrace.request_init_hook=/path/to/init_hook.php\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
}

TEST_CASE("append empty value", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "");

    REQUIRE(entries != NULL);
    REQUIRE(len == 5);
    REQUIRE(strcmp("abc=\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
}

TEST_CASE("append empty key", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "", "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == -1);
    REQUIRE(strcmp("", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
}

TEST_CASE("append entries pointing to NULL", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = 42;

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "123");

    REQUIRE(entries == NULL);
    REQUIRE(len == -1);

    zai_sapi_ini_entries_free(&entries);
}

TEST_CASE("append NULL entries", "[zai_sapi_ini]") {
    ssize_t len = 42;

    len = zai_sapi_ini_entries_realloc_append(NULL, (size_t)len, "abc", "123");

    REQUIRE(len == -1);
}

TEST_CASE("append NULL key", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, NULL, "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == -1);
    REQUIRE(strcmp("foo=bar\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
}

TEST_CASE("append NULL value", "[zai_sapi_ini]") {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", NULL);

    REQUIRE(entries != NULL);
    REQUIRE(len == -1);
    REQUIRE(strcmp("foo=bar\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
}
