// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "form_urlencoded.h"

#include <ctype.h>

#include <ext/standard/url.h>
#include <main/php_globals.h>
#include <main/php_variables.h>

#include "../php_helpers.h"

static void _pair(zend_array *arr, const char *pair, size_t len);
static void _register(zend_array *arr, char *name, zval *val);

// Like php_default_treat_data(PARSE_STRING): "name=value" pairs separated by
// '&' (empty ones skipped), both url-decoded, a pair without '=' having an
// empty value. The input is already capped by the caller (G7.12)
void dd_form_urlencoded_parse(
    const char *nonnull data, size_t len, zval *nonnull out)
{
    array_init(out);
    const char *p = data;
    const char *end = data + len;
    while (p < end) {
        const char *amp = memchr(p, '&', (size_t)(end - p));
        const char *pair_end = amp ? amp : end;
        if (pair_end > p) {
            _pair(Z_ARRVAL_P(out), p, (size_t)(pair_end - p));
        }
        if (!amp) {
            break;
        }
        p = amp + 1;
    }
}

static void _pair(zend_array *arr, const char *pair, size_t len)
{
    const char *eq = memchr(pair, '=', len);
    size_t name_len = eq ? (size_t)(eq - pair) : len;
    // like PHP, the name is a C string: it ends at a (decoded) NUL
    char *name = estrndup(pair, name_len);
    php_url_decode(name, name_len);
    zval val;
    if (eq) {
        size_t vlen = len - name_len - 1;
        zend_string *v = zend_string_init(eq + 1, vlen, 0);
        ZSTR_LEN(v) = php_url_decode(ZSTR_VAL(v), vlen);
        ZVAL_STR(&val, v);
    } else {
        ZVAL_EMPTY_STRING(&val);
    }
    _register(arr, name, &val);
    efree(name);
}

static bool _nesting_exceeded(const char *name);
static void _drop_base_name(zend_array *arr, const char *name);

// php_register_variable_ex() into the array, val consumed
static void _register(zend_array *arr, char *name, zval *val)
{
    if (_nesting_exceeded(name)) {
        _drop_base_name(arr, name);
        zval_ptr_dtor(val);
        return;
    }
    zval tracker;
    ZVAL_ARR(&tracker, arr);
    php_register_variable_ex(name, val, &tracker);
}

// Whether php_register_variable_ex() would give up on name for exceeding
// max_input_nesting_level: its loop over the "[...]" groups, without the side
// effects. A group is counted when it is entered, so the limit is reached by
// the group number max + 1, whether it is terminated or not.
static bool _nesting_exceeded(const char *name)
{
    while (*name == ' ') { name++; }
    const char *ip = strchr(name, '[');
    if (!ip || ip == name) {
        // not an array, or no base name (nothing is registered)
        return false;
    }
    zend_long max = PG(max_input_nesting_level);
    for (zend_long level = 1;; level++) {
        if (level > max) {
            return true;
        }
        ip++;
        if (isspace((unsigned char)*ip)) {
            ip++;
        }
        if (*ip != ']') {
            ip = strchr(ip, ']');
            if (!ip) {
                return false;
            }
        }
        ip++;
        if (*ip != '[') {
            return false;
        }
    }
}

// What php_register_variable_ex() does before giving up: the base name (up
// to the first '[', with ' ' and '.' mangled to '_') is removed
static void _drop_base_name(zend_array *arr, const char *name)
{
    while (*name == ' ') { name++; }
    size_t len = (size_t)(strchr(name, '[') - name);
    char *base = estrndup(name, len);
    for (size_t i = 0; i < len; i++) {
        if (base[i] == ' ' || base[i] == '.') {
            base[i] = '_';
        }
    }
    zend_symtable_str_del(arr, base, len);
    efree(base);
}
