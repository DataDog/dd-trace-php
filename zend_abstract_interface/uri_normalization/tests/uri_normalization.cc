extern "C" {
#include "uri_normalization/uri_normalization.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

#if PHP_VERSION_ID >= 70000
typedef zend_string *zend_string_ptr;
#else
typedef zai_string_view zend_string_ptr;
static inline zai_string_view zend_string_init(const char *str, size_t len, zend_bool persistent) {
    return (zai_string_view){ .len = len, .ptr = pestrndup(str, len, persistent) };
}
static inline void zend_string_release(zai_string_view str) {
    efree((void *) str.ptr);
}
#define zend_string_equals_literal(str, literal) \
    (str.len == sizeof(literal) - 1 && strncmp(str.ptr, literal, sizeof(literal) - 1) == 0)
#endif

#define TEST_BODY(output, path, ...)                  \
{                                                     \
    zval fragment_regex, mapping;                     \
    array_init(&fragment_regex);                      \
    array_init(&mapping);                             \
                                                      \
    { __VA_ARGS__ }                                   \
    zend_string_ptr path_str =                        \
        zend_string_init(ZEND_STRL(path), 0);         \
    zend_string_ptr res =                             \
        zai_uri_normalize_path(                       \
            path_str,                                 \
            Z_ARRVAL(fragment_regex),                 \
            Z_ARRVAL(mapping));                       \
                                                      \
    REQUIRE(zend_string_equals_literal(res, output)); \
                                                      \
    zend_string_release(path_str);                    \
    zend_string_release(res);                         \
    zval_dtor(&mapping);                              \
    zval_dtor(&fragment_regex);                       \
}

#define TEST_URI_NORMALIZATION(description, path, output, ...) \
    TEA_TEST_CASE("uri_normalization", description,       \
        TEST_BODY(output, path, __VA_ARGS__))

TEST_URI_NORMALIZATION("default replacement test: trivial_path_unmodified", "/trivial/path", "/trivial/path", { })
TEST_URI_NORMALIZATION("default replacement test: empty", "", "/", {})
TEST_URI_NORMALIZATION("default replacement test: root", "/", "/", {})
TEST_URI_NORMALIZATION("default replacement test: slash_added", "foo/bar", "/foo/bar", {})
TEST_URI_NORMALIZATION("default replacement test: only_digits", "/123", "/?", {})
TEST_URI_NORMALIZATION("default replacement test: only_digits_with_trailing_slash", "/int/123/", "/int/?/", {})
TEST_URI_NORMALIZATION("default replacement test: query_string_removal", "int/123?foo=bar", "/int/?", {})
TEST_URI_NORMALIZATION("default replacement test: starts_with_digits", "/123/path", "/?/path", {})
TEST_URI_NORMALIZATION("default replacement test: ends_with_digits", "/path/123", "/path/?", {})
TEST_URI_NORMALIZATION("default replacement test: has_digits", "/before/123/path", "/before/?/path", {})
TEST_URI_NORMALIZATION("default replacement test: only_hex", "/0123456789abcdef", "/?", {})
TEST_URI_NORMALIZATION("default replacement test: starts_with_hex", "/0123456789abcdef/path", "/?/path", {})
TEST_URI_NORMALIZATION("default replacement test: ends_with_hex", "/path/0123456789abcdef", "/path/?", {})
TEST_URI_NORMALIZATION("default replacement test: has_hex", "/before/0123456789abcdef/path", "/before/?/path", {})
TEST_URI_NORMALIZATION("default replacement test: only_uuid", "/b968fb04-2be9-494b-8b26-efb8a816e7a5", "/?", {})
TEST_URI_NORMALIZATION("default replacement test: starts_with_uuid", "/b968fb04-2be9-494b-8b26-efb8a816e7a5/path", "/?/path", {})
TEST_URI_NORMALIZATION("default replacement test: ends_with_uuid", "/path/b968fb04-2be9-494b-8b26-efb8a816e7a5", "/path/?", {})
TEST_URI_NORMALIZATION("default replacement test: has_uuid", "/before/b968fb04-2be9-494b-8b26-efb8a816e7a5/path", "/before/?/path", {})
TEST_URI_NORMALIZATION("default replacement test: only_uuid_no_dash", "/b968fb042be9494b8b26efb8a816e7a5", "/?", {})
TEST_URI_NORMALIZATION("default replacement test: starts_with_uuid_no_dash", "/b968fb042be9494b8b26efb8a816e7a5/path", "/?/path", {})
TEST_URI_NORMALIZATION("default replacement test: ends_with_uuid_no_dash", "/path/b968fb042be9494b8b26efb8a816e7a5", "/path/?", {})
TEST_URI_NORMALIZATION("default replacement test: has_uuid_no_dash", "/before/b968fb042be9494b8b26efb8a816e7a5/path", "/before/?/path", {})
TEST_URI_NORMALIZATION("default replacement test: multiple_patterns", "/int/1/uuid/b968fb042be9494b8b26efb8a816e7a5/int/2", "/int/?/uuid/?/int/?", {})
TEST_URI_NORMALIZATION("default replacement test: hex_case_insensitive", "/some/path/b968Fb04-2bE9-494B-8b26-Efb8A816e7a5/after", "/some/path/?/after", {})
TEST_URI_NORMALIZATION("default replacement test: uuid_case_insensitive", "/some/path/0123456789AbCdEf/after", "/some/path/?/after", {})

TEST_URI_NORMALIZATION("fragment regex: additive to default fragment regexes", "/int/123/name/some_name", "/int/?/name/?", {
    add_assoc_null(&fragment_regex, "^some_name$");
})
TEST_URI_NORMALIZATION("fragment regex: leading and trailing slashes and whitespace are ignored", "/name/some_name", "/name/?", {
    add_assoc_null(&fragment_regex, "  /  ^some_name$  / \t");
})
TEST_URI_NORMALIZATION("fragment regex: invalid regex fragments are ignored", "/int/123/path/valid", "/int/?/path/?", {
    add_assoc_null(&fragment_regex, "(((((]]]]]]wrong_regex$");
    add_assoc_null(&fragment_regex, "valid");
})
TEST_URI_NORMALIZATION("fragment regex: partial match with trivial PCRE syntax usage", "/start/barfoooobaz/end", "/start/?/end", {
    add_assoc_null(&fragment_regex, "fo+?(*COMMIT)o");
})

TEST_URI_NORMALIZATION("pattern mapping: normalizing of single fragment", "/int/123/path/one-something/else", "/int/?/path/?-something/else", {
    add_assoc_null(&mapping, "*-something");
})
TEST_URI_NORMALIZATION("pattern mapping: pattern may be applied multiple times", "/int/123/path/one/int/456/path/two", "/int/?/path/?/int/?/path/?", {
    add_assoc_null(&mapping, "path/*");
})
TEST_URI_NORMALIZATION("pattern mapping: partial matching", "/int/123/path/one/two/then/something/else", "/int/?/path/?/?/then/something/?", {
    add_assoc_null(&mapping, "path/*/*/then/something/*");
})
TEST_URI_NORMALIZATION("pattern mapping: matching is case sensititve", "/int/123/nested/some", "/int/?/nested/some", {
    add_assoc_null(&mapping, "nEsTeD/*");
})

TEST_URI_NORMALIZATION("pattern mapping & fragment regexes: working with http URLs", "http://example.com/int/123/path/abc/nested/some", "http://example.com/int/?/path/?/nested/?", {
    add_assoc_null(&mapping, "nested/*");
    add_assoc_null(&fragment_regex, "^abc$");
})
TEST_URI_NORMALIZATION("pattern mapping & fragment regexes: working with https URLs", "https://example.com:8080/int/123/path/abc/nested/some", "https://example.com:8080/int/?/path/?/nested/?", {
    add_assoc_null(&mapping, "nested/*");
    add_assoc_null(&fragment_regex, "^abc$");
})
TEST_URI_NORMALIZATION("pattern mapping & fragment regexes: working with full URLs", "letter+1-2-3.CAPITAL.123://example.com/int/123/path/abc/nested/some", "letter+1-2-3.CAPITAL.123://example.com/int/?/path/?/nested/?", {
    add_assoc_null(&mapping, "nested/*");
    add_assoc_null(&fragment_regex, "^abc$");
})
