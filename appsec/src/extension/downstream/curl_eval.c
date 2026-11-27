// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "curl_eval.h"

#include "../php_helpers.h"

// Calls into libcurl in progress from our hooks (curl_exec,
// curl_multi_exec, ...): while there is one, the app's code may be running
// inside a libcurl callback, and a curl hook entered then does nothing
// (G4.3, G9.9). One depth for the thread: a fiber suspended inside libcurl
// makes the others' hooks nested too.
static THREAD_LOCAL_ON_ZTS unsigned _libcurl_depth;

static void _header_add(
    zend_array *headers, struct dd_http_slice name, struct dd_http_slice value);

void dd_curl_eval_rinit(void) { _libcurl_depth = 0; }

void dd_curl_eval_rshutdown(void) { _libcurl_depth = 0; }

void dd_curl_eval_libcurl_enter(void) { _libcurl_depth++; }

void dd_curl_eval_libcurl_leave(void)
{
    if (_libcurl_depth > 0) {
        _libcurl_depth--;
    }
}

bool dd_curl_eval_in_libcurl(void) { return _libcurl_depth > 0; }

void dd_curl_headers_to_array(
    const char *nullable head, size_t len, zval *nonnull arr)
{
    array_init(arr);
    if (!head) {
        return;
    }
    struct dd_http_header_iter it;
    struct dd_http_slice name;
    struct dd_http_slice value;
    dd_http_header_iter_init(&it, head, len);
    while (dd_http_header_iter_next(&it, &name, &value)) {
        _header_add(Z_ARRVAL_P(arr), name, value);
    }
}

static void _header_add(
    zend_array *headers, struct dd_http_slice name, struct dd_http_slice value)
{
    if (name.len == 0 || value.len == 0) {
        return;
    }
    zend_string *lname = zend_string_init(name.ptr, name.len, 0);
    zend_str_tolower(ZSTR_VAL(lname), ZSTR_LEN(lname));
    zval *list = zend_symtable_find(headers, lname);
    if (!list) {
        zval new_list;
        array_init(&new_list);
        list = zend_symtable_update(headers, lname, &new_list);
    }
    add_next_index_stringl(list, value.ptr, value.len);
    zend_string_release(lname);
}
