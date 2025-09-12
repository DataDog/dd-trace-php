#include "compat_string.h"
#include "ddtrace.h"

#include <Zend/zend_API.h>
#include <php.h>
#include <php_version.h>

#include "compatibility.h"

size_t ddtrace_spprintf(char **message, size_t max_len, char *format, ...) {
    va_list arg;
    size_t len;

    va_start(arg, format);
    len = vspprintf(message, max_len, format, arg);
    va_end(arg);
    return len;
}

void ddtrace_downcase_zval(zval *src) {
    if (!src || Z_TYPE_P(src) != IS_STRING) {
        return;
    }
    zend_string *str = Z_STR_P(src);

    ZVAL_STR(src, zend_string_tolower(str));
    zend_string_release(str);
}

zend_string *ddtrace_convert_to_str(const zval *op) {
try_again:
    switch (Z_TYPE_P(op)) {
        case IS_UNDEF:
            return zend_string_init("undef", sizeof("undef") - 1, 0);

        case IS_NULL:
#if PHP_VERSION_ID < 80000
            return zend_string_init("null", sizeof("null") - 1, 0);
#else
            return ZSTR_KNOWN(ZEND_STR_NULL_LOWERCASE);
#endif

        case IS_FALSE:
#if PHP_VERSION_ID < 80000
            return zend_string_init("false", sizeof("false") - 1, 0);
#else
            return ZSTR_KNOWN(ZEND_STR_FALSE);
#endif

        case IS_TRUE:
#if PHP_VERSION_ID < 80200
            return zend_string_init("true", sizeof("true") - 1, 0);
#else
            return ZSTR_KNOWN(ZEND_STR_TRUE);
#endif

        case IS_RESOURCE:
            return strpprintf(0, "Resource id #" ZEND_LONG_FMT, (zend_long)Z_RES_HANDLE_P(op));

        case IS_LONG:
            return zend_long_to_str(Z_LVAL_P(op));

        case IS_DOUBLE:
            return strpprintf(0, "%.*G", (int)EG(precision), Z_DVAL_P(op));

        case IS_ARRAY:
#if PHP_VERSION_ID < 70400
            return zend_string_init("Array", sizeof("Array") - 1, 0);
#else
            return ZSTR_KNOWN(ZEND_STR_ARRAY_CAPITALIZED);
#endif

        case IS_OBJECT: {
            zend_string *class_name = Z_OBJ_HANDLER_P(op, get_class_name)(Z_OBJ_P(op));
            zend_string *message = strpprintf(0, "object(%s)#%d", ZSTR_VAL(class_name), Z_OBJ_HANDLE_P(op));
            zend_string_release(class_name);
            return message;
        }

        case IS_REFERENCE:
            op = Z_REFVAL_P(op);
            goto try_again;

        case IS_STRING:
            return zend_string_copy(Z_STR_P(op));

            EMPTY_SWITCH_DEFAULT_CASE()
    }
}

void ddtrace_convert_to_string(zval *dst, zval *src) {
    zend_string *str = ddtrace_convert_to_str(src);
    ZVAL_STR(dst, str);
}

#if PHP_VERSION_ID < 80200
static zend_always_inline unsigned char dd_tolower_ascii(unsigned char c) {
    return (c >= 'A' && c <= 'Z') ? (unsigned char)(c + ('a' - 'A')) : c;
}

static zend_always_inline unsigned char dd_toupper_ascii(unsigned char c) {
    return (c >= 'a' && c <= 'z') ? (unsigned char)(c - ('a' - 'A')) : c;
}

static zend_always_inline bool zend_strnieq(const char *ptr1, const char *ptr2, size_t num)
{
	const char *end = ptr1 + num;
	while (ptr1 < end) {
		if (dd_tolower_ascii(*ptr1++) != dd_tolower_ascii(*ptr2++)) {
			return 0;
		}
	}
	return 1;
}

const char *zend_memnistr(const char *haystack, const char *needle, size_t needle_len, const char *end)
{
	ZEND_ASSERT(end >= haystack);

	if (UNEXPECTED(needle_len == 0)) {
		return haystack;
	}

	if (UNEXPECTED(needle_len > (size_t)(end - haystack))) {
		return NULL;
	}

	const char first_lower = dd_tolower_ascii(*needle);
	const char first_upper = dd_toupper_ascii(*needle);
	const char *p_lower = (const char *)memchr(haystack, first_lower, end - haystack);
	const char *p_upper = NULL;
	if (first_lower != first_upper) {
		// If the needle length is 1 we don't need to look beyond p_lower as it is a guaranteed match
		size_t upper_search_length = needle_len == 1 && p_lower != NULL ? p_lower - haystack : end - haystack;
		p_upper = (const char *)memchr(haystack, first_upper, upper_search_length);
	}
	const char *p = !p_upper || (p_lower && p_lower < p_upper) ? p_lower : p_upper;

	if (needle_len == 1) {
		return p;
	}

	const char needle_end_lower = dd_tolower_ascii(needle[needle_len - 1]);
	const char needle_end_upper = dd_toupper_ascii(needle[needle_len - 1]);
	end -= needle_len;

	while (p && p <= end) {
		if (needle_end_lower == p[needle_len - 1] || needle_end_upper == p[needle_len - 1]) {
			if (zend_strnieq(needle + 1, p + 1, needle_len - 2)) {
				return p;
			}
		}
		if (p_lower == p) {
			p_lower = (const char *)memchr(p_lower + 1, first_lower, end - p_lower);
		}
		if (p_upper == p) {
			p_upper = (const char *)memchr(p_upper + 1, first_upper, end - p_upper);
		}
		p = !p_upper || (p_lower && p_lower < p_upper) ? p_lower : p_upper;
	}

	return NULL;
}

#endif
