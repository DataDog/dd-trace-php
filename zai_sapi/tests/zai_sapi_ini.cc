extern "C" {
#include "zai_sapi/zai_sapi_ini.h"
}

#include "zai_sapi/testing/catch2.hpp"
#include <cstring>

/************************ zai_sapi_ini_entries_alloc ************************/

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "alloc INI entries", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    REQUIRE(entries != NULL);
    REQUIRE(len == 8);
    REQUIRE(strcmp("foo=bar\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "alloc empty INI entries", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("", &entries);

    REQUIRE(entries != NULL);
    REQUIRE(len == 0);
    REQUIRE(strcmp("", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "alloc INI does not overwrite existing ptr", {
    char *entries = (char *)(void *)1;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    REQUIRE(entries == (char *)(void *)1);
    REQUIRE(len == -1);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "alloc NULL INI src entries", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc(NULL, &entries);

    REQUIRE(entries == NULL);
    REQUIRE(len == -1);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "alloc NULL INI dest", {
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", NULL);

    REQUIRE(len == -1);
})

/************************* zai_sapi_ini_entries_free *************************/

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "freeing entries sets NULL pointer", {
    char *entries = NULL;
    zai_sapi_ini_entries_alloc("foo=bar", &entries);

    REQUIRE(entries != NULL);

    zai_sapi_ini_entries_free(&entries);

    REQUIRE(entries == NULL);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "free NULL pointer", {
    char *entries = NULL;

    zai_sapi_ini_entries_free(&entries);
    zai_sapi_ini_entries_free(NULL);

    REQUIRE(entries == NULL);
})

/******************** zai_sapi_ini_entries_realloc_append ********************/

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "append INI entry", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == 16);
    REQUIRE(strcmp("foo=bar\nabc=123\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "append INI entry from empty starting point", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == 8);
    REQUIRE(strcmp("abc=123\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "append several INI entries", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\nabc=123\n", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "123");
    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "extension", "ddtrace.so");
    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "ddtrace.request_init_hook", "/path/to/init_hook.php");

    REQUIRE(entries != NULL);
    REQUIRE(len == 94);
    REQUIRE(strcmp("foo=bar\nabc=123\nabc=123\nextension=ddtrace.so\nddtrace.request_init_hook=/path/to/init_hook.php\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "append empty value", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "");

    REQUIRE(entries != NULL);
    REQUIRE(len == 5);
    REQUIRE(strcmp("abc=\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "append empty key", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "", "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == -1);
    REQUIRE(strcmp("", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "append entries pointing to NULL", {
    char *entries = NULL;
    ssize_t len = 42;

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", "123");

    REQUIRE(entries == NULL);
    REQUIRE(len == -1);

    zai_sapi_ini_entries_free(&entries);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "append NULL entries", {
    ssize_t len = 42;

    len = zai_sapi_ini_entries_realloc_append(NULL, (size_t)len, "abc", "123");

    REQUIRE(len == -1);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "append NULL key", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, NULL, "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == -1);
    REQUIRE(strcmp("foo=bar\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
})

ZAI_SAPI_TEST_CASE_BARE("zai_sapi/ini", "append NULL value", {
    char *entries = NULL;
    ssize_t len = zai_sapi_ini_entries_alloc("foo=bar\n", &entries);

    len = zai_sapi_ini_entries_realloc_append(&entries, (size_t)len, "abc", NULL);

    REQUIRE(entries != NULL);
    REQUIRE(len == -1);
    REQUIRE(strcmp("foo=bar\n", entries) == 0);

    zai_sapi_ini_entries_free(&entries);
})
