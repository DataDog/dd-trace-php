#ifndef ZAI_STRING_H
#define ZAI_STRING_H

#include <php.h>
#include <stdbool.h>
#include <stdint.h>
#include <zai_compat.h>

typedef struct zai_string_view_s {
    size_t len;
    const char* ptr;
} zai_string_view;

#define ZAI_STRL_VIEW(cstr) \
    (zai_string_view) { .len = sizeof(cstr) - 1, .ptr = (cstr) }

#define ZAI_STRING_EMPTY \
    (zai_string_view) { .len = 0, .ptr = "" }

static inline bool zai_string_stuffed(zai_string_view s) { return s.ptr && s.len; }

#if PHP_VERSION_ID < 70000
typedef zai_string_view* zai_string_t;
#else
typedef zend_string* zai_string_t;
#endif

#define ZAI_STRINGS_KNOWN(_)         \
    _(ZAI_STRING_KNOWN_E_ERROR, "E_ERROR") \
    _(ZAI_STRING_KNOWN_E_CORE_ERROR, "E_CORE_ERROR") \
    _(ZAI_STRING_KNOWN_E_COMPILE_ERROR, "E_COMPILE_ERROR") \
    _(ZAI_STRING_KNOWN_E_USER_ERROR, "E_USER_ERROR") \
    _(ZAI_STRING_KNOWN_E_UNKNOWN_ERROR, "{unknown error}") \
    _(ZAI_STRING_KNOWN_SERVER, "_SERVER") \
    _(ZAI_STRING_KNOWN_REQUEST_URI, "REQUEST_URI") \
    _(ZAI_STRING_KNOWN_HTTPS, "HTTPS") \
    _(ZAI_STRING_KNOWN_HTTP_HOST, "HTTP_HOST") \
    _(ZAI_STRING_KNOWN_SERVER_NAME, "SERVER_NAME") \
    _(ZAI_STRING_KNOWN_META, "meta") \
    _(ZAI_STRING_KNOWN_METRICS, "metrics") \
    _(ZAI_STRING_KNOWN_NAME, "name") \
    _(ZAI_STRING_KNOWN_DEFAULT, "default") \
    _(ZAI_STRING_KNOWN_ERROR_TYPE, "error.type") \
    _(ZAI_STRING_KNOWN_ERROR_MSG, "error.msg") \
    _(ZAI_STRING_KNOWN_ERROR_STACK, "error.stack") \
    _(ZAI_STRING_KNOWN_HTTP_METHOD, "http.method") \
    _(ZAI_STRING_KNOWN_HTTP_URL, "http.url") \
    _(ZAI_STRING_KNOWN_CLI, "cli") \
    _(ZAI_STRING_KNOWN_CLI_COMMAND, "cli.command") \
    _(ZAI_STRING_KNOWN_WEB, "web") \
    _(ZAI_STRING_KNOWN_WEB_REQUEST, "web.request")

typedef enum _zai_strings_known_id {
#define ZAI_STRINGS_KNOWN_ID(id, str) id,
    ZAI_STRINGS_KNOWN(ZAI_STRINGS_KNOWN_ID)
#undef ZAI_STRINGS_KNOWN_ID
        ZAI_STRINGS_KNOWN_LAST
} zend_strings_known_id;

bool zai_string_minit();
bool zai_string_rinit();
bool zai_string_mshutdown();

extern zai_string_t* zai_strings_known;

#define ZAI_STRING_KNOWN(id) zai_strings_known[id]

#if PHP_VERSION_ID < 70000
#define ZAI_STRING_KNOWN_VAL(id) zai_strings_known[id]->ptr
#define ZAI_STRING_KNOWN_LEN(id) zai_strings_known[id]->len
#else
#define ZAI_STRING_KNOWN_VAL(id) ZSTR_VAL(zai_strings_known[id])
#define ZAI_STRING_KNOWN_LEN(id) ZSTR_LEN(zai_strings_known[id])
#endif

#endif  // ZAI_STRING_H
