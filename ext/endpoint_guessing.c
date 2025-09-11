#include "endpoint_guessing.h"

#include <Zend/zend_smart_str.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <zend_hash.h>
#include <zend_string.h>
#include <zend_types.h>
#include "configuration.h"
#include "ddtrace.h"
#include "span.h"
#include <php.h>
#include <ext/standard/url.h>

#define MAX_COMPONENTS 8

static inline bool is_digit(char c) { return c >= '0' && c <= '9'; }

static inline bool is_nonzero_digit(char c) { return c >= '1' && c <= '9'; }

static inline bool is_hex_alpha(char c) { return (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F'); }

static inline bool is_delim(char c) { return c == '.' || c == '_' || c == '-'; }

static inline bool is_str_special(char c) {
    return c == '%' || c == '&' || c == '\'' || c == '(' || c == ')' || c == '*' || c == '+' || c == ',' || c == ':' || c == '=' || c == '@';
}

/*
{param:int}     [1-9][0-9]+                   len≥2, digits only, first 1–9
{param:int_id}  (?=.*[0-9])[0-9._-]{3,}       len≥3, [0-9._-], must contain digit
{param:hex}     (?=.*[0-9])[A-Fa-f0-9]{6,}    len≥6, hex digits, must contain decimal digit
{param:hex_id}  (?=.*[0-9])[A-Fa-f0-9._-]{6,} len≥6, hex+._-, must contain decimal digit
{param:str}     .{20,}|.*[%&'()*+,:=@].*      any chars, valid if len≥20 or contains special
*/

typedef enum {
    COMPONENT_NONE = 0,
    COMPONENT_IS_INT = 1 << 0,
    COMPONENT_IS_INT_ID = 1 << 1,
    COMPONENT_IS_HEX = 1 << 2,
    COMPONENT_IS_HEX_ID = 1 << 3,
    COMPONENT_IS_STR = 1 << 4,
} component_type_t;

static const char* component_type_to_string(component_type_t type) {
    switch (type) {
        case COMPONENT_IS_INT:
            return "{param:int}";
        case COMPONENT_IS_INT_ID:
            return "{param:int_id}";
        case COMPONENT_IS_HEX:
            return "{param:hex}";
        case COMPONENT_IS_HEX_ID:
            return "{param:hex_id}";
        case COMPONENT_IS_STR:
            return "{param:str}";
        default:
            return "";
    }
}

static inline uint8_t bool_to_mask(bool x) {
    return (uint8_t)(-(int)x);  // 0 -> 0x00, 1 -> 0xFF
}

static component_type_t component_replacement(const char* path, size_t len) {
    uint8_t viable_components = 0x1F;  // (COMPONENT_IS_STR << 1) - 1
    bool found_special_char = false;
    bool found_digit = false;

    if (len < 2) {
        viable_components &= ~(COMPONENT_IS_INT | COMPONENT_IS_INT_ID | COMPONENT_IS_HEX | COMPONENT_IS_HEX_ID);
    } else if (len < 3) {
        viable_components &= ~(COMPONENT_IS_INT_ID | COMPONENT_IS_HEX | COMPONENT_IS_HEX_ID);
    } else if (len < 6) {
        viable_components &= ~(COMPONENT_IS_HEX | COMPONENT_IS_HEX_ID);
    }

    // handle the first char: is_int does not allow a leading 0
    if (len > 0) {
        char c = path[0];
        found_special_char = found_special_char || is_str_special(c);
        found_digit = found_digit || is_digit(c);

        uint8_t digit_mask = bool_to_mask(is_digit(c)) & (COMPONENT_IS_INT_ID | COMPONENT_IS_HEX | COMPONENT_IS_HEX_ID);

        // first char for is_int must be 1–9
        uint8_t is_int_mask = bool_to_mask(is_nonzero_digit(c)) & COMPONENT_IS_INT;

        uint8_t hex_alpha_mask = bool_to_mask(is_hex_alpha(c)) & (COMPONENT_IS_HEX | COMPONENT_IS_HEX_ID);

        uint8_t delimiter_mask = bool_to_mask(is_delim(c)) & (COMPONENT_IS_INT_ID | COMPONENT_IS_HEX_ID);

        viable_components &= (digit_mask | is_int_mask | hex_alpha_mask | delimiter_mask | COMPONENT_IS_STR);
    }

    // Process remaining characters
    for (size_t i = 1; i < len; ++i) {
        char c = path[i];
        found_special_char = found_special_char || is_str_special(c);
        found_digit = found_digit || is_digit(c);

        uint8_t digit_mask = bool_to_mask(is_digit(c)) & (COMPONENT_IS_INT | COMPONENT_IS_INT_ID | COMPONENT_IS_HEX | COMPONENT_IS_HEX_ID);

        uint8_t hex_alpha_mask = bool_to_mask(is_hex_alpha(c)) & (COMPONENT_IS_HEX | COMPONENT_IS_HEX_ID);

        uint8_t delimiter_mask = bool_to_mask(is_delim(c)) & (COMPONENT_IS_INT_ID | COMPONENT_IS_HEX_ID);

        viable_components &= (digit_mask | hex_alpha_mask | delimiter_mask | COMPONENT_IS_STR);
    }

    // is_str requires a special char or a size >= 20
    viable_components &= ~COMPONENT_IS_STR | bool_to_mask(found_special_char || (len >= 20));
    // hex, and hex_id require a digit
    viable_components &= ~(COMPONENT_IS_HEX | COMPONENT_IS_HEX_ID) | bool_to_mask(found_digit);

    if (viable_components == 0) {
        return COMPONENT_NONE;
    }

    // Find least significant bit (equivalent to std::countr_zero)
    uint8_t lsb = viable_components & (uint8_t)(-(int8_t)viable_components);
    return (component_type_t)lsb;
}

static zend_string* guess_endpoint(const char* orig_url, size_t orig_url_len) {
    smart_str result = {0};

    if (!orig_url || orig_url_len == 0) {
        smart_str_appendc(&result, '/');
        return smart_str_extract(&result);
    }

    php_url *parsed = php_url_parse(orig_url);

    const char *path = NULL;
    size_t path_len = 0;

    if (parsed && parsed->path) {
#if PHP_VERSION_ID >= 70300
        path = ZSTR_VAL(parsed->path);
        path_len = ZSTR_LEN(parsed->path);
#else
        path = parsed->path;
        path_len = strlen(parsed->path);
#endif
    }

    if (!path || path_len == 0 || path[0] != '/') {
        if (parsed) {
            php_url_free(parsed);
        }
        smart_str_appendc(&result, '/');
        return smart_str_extract(&result);
    }

    size_t component_count = 0;
    size_t path_pos = 1;  // Skip initial '/'

    while (path_pos < path_len) {
        size_t component_end = path_pos;
        while (component_end < path_len && path[component_end] != '/') {
            component_end++;
        }

        size_t component_len = component_end - path_pos;

        if (component_len > 0) {
            smart_str_appendc(&result, '/');

            component_type_t type = component_replacement(path + path_pos, component_len);
            if (type == COMPONENT_NONE) {
                // append the original component
                smart_str_appendl(&result, &path[path_pos], component_len);
            } else {
                const char* param_str = component_type_to_string(type);
                smart_str_appends(&result, param_str);
            }

            if (++component_count >= MAX_COMPONENTS) {
                break;
            }
        }

        path_pos = component_end + 1;  // Skip the slash
    }

    if (smart_str_get_len(&result) == 0) {
        smart_str_appendc(&result, '/');
    }

    if (parsed) {
        php_url_free(parsed);
    }

    return smart_str_extract(&result);
}

void ddtrace_maybe_add_guessed_endpoint_tag(ddtrace_root_span_data *span)
{
    zval *span_type = &span->property_type;

    // require span.type == web
    if (Z_TYPE_P(span_type) != IS_STRING || !zend_string_equals_literal(Z_STR_P(span_type), "web")) {
        return;
    }

    zend_array* meta = ddtrace_property_array(&span->property_meta);

    if (!get_DD_TRACE_RESOURCE_RENAMING_ALWAYS_SIMPLIFIED_ENDPOINT()) {
        zval* route = zend_hash_str_find(meta, ZEND_STRL("http.route"));
        // unless we have always_simplified_endpoiont set,
        // we skip the calculation if http.route is set
        if (route && Z_TYPE_P(route) == IS_STRING) {
            return;
        }
    }

    zval endpoint_zv;

    zval *url = zend_hash_str_find(meta, ZEND_STRL("http.url"));
    if (!url || Z_TYPE_P(url) != IS_STRING) {
        // "In case the url is not available, a default value of / should be used for the endpoint tag."
        ZVAL_STRING(&endpoint_zv, "/");
    } else {
        zend_string* endpoint = guess_endpoint(Z_STRVAL_P(url), Z_STRLEN_P(url));
        ZVAL_STR(&endpoint_zv, endpoint);
    }

    if (zend_hash_str_add(meta, ZEND_STRL("http.endpoint"), &endpoint_zv) == NULL) {
        zval_dtor(&endpoint_zv);
        return;
    }
}
