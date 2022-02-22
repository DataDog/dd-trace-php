extern "C" {
#include <private/ini.h>
}

#include <include/testing/catch2.hpp>
#include <cstring>

/************************ tea_ini_alloc ************************/

TEA_TEST_CASE_BARE("tea/ini", "alloc INI entries", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc("foo=bar\n", &entries);

    REQUIRE(entries != NULL);
    REQUIRE(len == 8);
    REQUIRE(strcmp("foo=bar\n", entries) == 0);

    tea_ini_free(&entries);
})

TEA_TEST_CASE_BARE("tea/ini", "alloc empty INI entries", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc("", &entries);

    REQUIRE(entries != NULL);
    REQUIRE(len == 0);
    REQUIRE(strcmp("", entries) == 0);

    tea_ini_free(&entries);
})

TEA_TEST_CASE_BARE("tea/ini", "alloc INI does not overwrite existing ptr", {
    char *entries = (char *)(void *)1;
    ssize_t len = tea_ini_alloc("foo=bar\n", &entries);

    REQUIRE(entries == (char *)(void *)1);
    REQUIRE(len == -1);
})

TEA_TEST_CASE_BARE("tea/ini", "alloc NULL INI src entries", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc(NULL, &entries);

    REQUIRE(entries == NULL);
    REQUIRE(len == -1);
})

TEA_TEST_CASE_BARE("tea/ini", "alloc NULL INI dest", {
    ssize_t len = tea_ini_alloc("foo=bar\n", NULL);

    REQUIRE(len == -1);
})

/************************* tea_ini_free *************************/

TEA_TEST_CASE_BARE("tea/ini", "freeing entries sets NULL pointer", {
    char *entries = NULL;
    tea_ini_alloc("foo=bar", &entries);

    REQUIRE(entries != NULL);

    tea_ini_free(&entries);

    REQUIRE(entries == NULL);
})

TEA_TEST_CASE_BARE("tea/ini", "free NULL pointer", {
    char *entries = NULL;

    tea_ini_free(&entries);
    tea_ini_free(NULL);

    REQUIRE(entries == NULL);
})

/******************** tea_ini_realloc_append ********************/

TEA_TEST_CASE_BARE("tea/ini", "append INI entry", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc("foo=bar\n", &entries);

    len = tea_ini_realloc_append(&entries, (size_t)len, "abc", "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == 16);
    REQUIRE(strcmp("foo=bar\nabc=123\n", entries) == 0);

    tea_ini_free(&entries);
})

TEA_TEST_CASE_BARE("tea/ini", "append INI entry from empty starting point", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc("", &entries);

    len = tea_ini_realloc_append(&entries, (size_t)len, "abc", "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == 8);
    REQUIRE(strcmp("abc=123\n", entries) == 0);

    tea_ini_free(&entries);
})

TEA_TEST_CASE_BARE("tea/ini", "append several INI entries", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc("foo=bar\nabc=123\n", &entries);

    len = tea_ini_realloc_append(&entries, (size_t)len, "abc", "123");
    len = tea_ini_realloc_append(&entries, (size_t)len, "extension", "ddtrace.so");
    len = tea_ini_realloc_append(&entries, (size_t)len, "ddtrace.request_init_hook", "/path/to/init_hook.php");

    REQUIRE(entries != NULL);
    REQUIRE(len == 94);
    REQUIRE(strcmp("foo=bar\nabc=123\nabc=123\nextension=ddtrace.so\nddtrace.request_init_hook=/path/to/init_hook.php\n", entries) == 0);

    tea_ini_free(&entries);
})

TEA_TEST_CASE_BARE("tea/ini", "append empty value", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc("", &entries);

    len = tea_ini_realloc_append(&entries, (size_t)len, "abc", "");

    REQUIRE(entries != NULL);
    REQUIRE(len == 5);
    REQUIRE(strcmp("abc=\n", entries) == 0);

    tea_ini_free(&entries);
})

TEA_TEST_CASE_BARE("tea/ini", "append empty key", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc("", &entries);

    len = tea_ini_realloc_append(&entries, (size_t)len, "", "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == -1);
    REQUIRE(strcmp("", entries) == 0);

    tea_ini_free(&entries);
})

TEA_TEST_CASE_BARE("tea/ini", "append entries pointing to NULL", {
    char *entries = NULL;
    ssize_t len = 42;

    len = tea_ini_realloc_append(&entries, (size_t)len, "abc", "123");

    REQUIRE(entries == NULL);
    REQUIRE(len == -1);

    tea_ini_free(&entries);
})

TEA_TEST_CASE_BARE("tea/ini", "append NULL entries", {
    ssize_t len = 42;

    len = tea_ini_realloc_append(NULL, (size_t)len, "abc", "123");

    REQUIRE(len == -1);
})

TEA_TEST_CASE_BARE("tea/ini", "append NULL key", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc("foo=bar\n", &entries);

    len = tea_ini_realloc_append(&entries, (size_t)len, NULL, "123");

    REQUIRE(entries != NULL);
    REQUIRE(len == -1);
    REQUIRE(strcmp("foo=bar\n", entries) == 0);

    tea_ini_free(&entries);
})

TEA_TEST_CASE_BARE("tea/ini", "append NULL value", {
    char *entries = NULL;
    ssize_t len = tea_ini_alloc("foo=bar\n", &entries);

    len = tea_ini_realloc_append(&entries, (size_t)len, "abc", NULL);

    REQUIRE(entries != NULL);
    REQUIRE(len == -1);
    REQUIRE(strcmp("foo=bar\n", entries) == 0);

    tea_ini_free(&entries);
})
