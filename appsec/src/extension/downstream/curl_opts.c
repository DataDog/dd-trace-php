// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "curl_opts.h"

#include <Zend/zend_closures.h>

#include "curl_linkage.h"

// libcurl 8.1.0: CURLOPT_UPLOAD no longer makes every request a PUT (its
// set.upload, kept by CURLOPT_POST, POSTFIELDS and NOBODY; CVE-2023-28322)
enum { DD_CURL_UPLOAD_SEPARATE_SINCE = 0x080100 };

// -1: the constant does not exist in this ext/curl
#define DD_CURL_OPTS(X)                                                        \
    X(url, "CURLOPT_URL")                                                      \
    X(default_protocol, "CURLOPT_DEFAULT_PROTOCOL")                            \
    X(customrequest, "CURLOPT_CUSTOMREQUEST")                                  \
    X(post, "CURLOPT_POST")                                                    \
    X(put, "CURLOPT_PUT")                                                      \
    X(upload, "CURLOPT_UPLOAD")                                                \
    X(nobody, "CURLOPT_NOBODY")                                                \
    X(httpget, "CURLOPT_HTTPGET")                                              \
    X(httpheader, "CURLOPT_HTTPHEADER")                                        \
    X(cookie, "CURLOPT_COOKIE")                                                \
    X(header, "CURLOPT_HEADER")                                                \
    X(readfunction, "CURLOPT_READFUNCTION")                                    \
    X(infile, "CURLOPT_INFILE")                                                \
    X(postfields, "CURLOPT_POSTFIELDS")                                        \
    X(prereqfunction, "CURLOPT_PREREQFUNCTION")                                \
    X(followlocation, "CURLOPT_FOLLOWLOCATION")

// The option numbers: written only by dd_curl_opts_startup(), which runs once
// per process before any request thread (the engine serialises startup)
static struct {
#define DD_CURL_OPT_FIELD(field, name) zend_long field;
    DD_CURL_OPTS(DD_CURL_OPT_FIELD)
#undef DD_CURL_OPT_FIELD
} _opt;

static bool _string_option(zend_long option);
static bool _is_curl_file(const zval *nonnull value);
static bool _copy_lines(const zend_array *nonnull src, zval *nonnull dst);
static bool _copy_fields(const zend_array *nonnull src, zval *nonnull dst);
static bool _postfields_value(
    zval *nonnull value, zval *nonnull out, bool *nonnull multipart);
static void _set_owned(zval *nonnull dst, zval *nonnull value);
static zend_string *nullable _str_or_null(zval *nonnull value);
static zend_string *nullable _applied_str(zval *nonnull value);
static void _replace_str(
    zend_string * nullable * nonnull dst, zval *nullable value);
static void _replace_applied_str(
    zend_string * nullable * nonnull dst, zval *nonnull value);

void dd_curl_opts_startup(void)
{
#define DD_CURL_OPT_LOOKUP(field, name)                                        \
    do {                                                                       \
        zval *c = zend_get_constant_str(ZEND_STRL(name));                      \
        _opt.field = (c && Z_TYPE_P(c) == IS_LONG) ? Z_LVAL_P(c) : -1;         \
    } while (0);
    DD_CURL_OPTS(DD_CURL_OPT_LOOKUP)
#undef DD_CURL_OPT_LOOKUP
}

void dd_curl_opts_init(struct dd_curl_opts *nonnull opts)
{
    memset(opts, 0, sizeof(*opts));
    ZVAL_UNDEF(&opts->httpheader);
    ZVAL_UNDEF(&opts->postfields);
}

void dd_curl_opts_dtor(struct dd_curl_opts *nonnull opts)
{
    if (opts->url) {
        zend_string_release(opts->url);
    }
    if (opts->default_protocol) {
        zend_string_release(opts->default_protocol);
    }
    if (opts->customrequest) {
        zend_string_release(opts->customrequest);
    }
    if (opts->cookie) {
        zend_string_release(opts->cookie);
    }
    zval_ptr_dtor(&opts->httpheader);
    zval_ptr_dtor(&opts->postfields);
    dd_curl_opts_init(opts);
}

void dd_curl_opts_clear(struct dd_curl_opts *nonnull opts)
{
    dd_curl_opts_dtor(opts);
}

void dd_curl_opts_copy(
    struct dd_curl_opts *nonnull dst, const struct dd_curl_opts *nonnull src)
{
    dd_curl_opts_dtor(dst);
    *dst = *src;
    if (dst->url) {
        zend_string_addref(dst->url);
    }
    if (dst->default_protocol) {
        zend_string_addref(dst->default_protocol);
    }
    if (dst->customrequest) {
        zend_string_addref(dst->customrequest);
    }
    if (dst->cookie) {
        zend_string_addref(dst->cookie);
    }
    Z_TRY_ADDREF(dst->httpheader);
    Z_TRY_ADDREF(dst->postfields);
}

void dd_curl_opts_set_url(struct dd_curl_opts *nonnull opts, zval *nonnull url)
{
    _replace_str(&opts->url, url);
}

void dd_curl_opts_set_unknown(struct dd_curl_opts *nonnull opts)
{
    opts->unknown_option = true;
}

bool dd_curl_opts_converts_object(zend_long option)
{
    return option >= 0 && (option == _opt.url
#if PHP_VERSION_ID >= 80000
                              || option == _opt.postfields
#endif
                          );
}

// The effect on libcurl's method follows lib/setopt.c: POST, HTTPGET, NOBODY
// and UPLOAD (PUT is an alias) each set or reset it; POSTFIELDS implies POST.
// NOLINTNEXTLINE(readability-function-cognitive-complexity)
void dd_curl_opts_set(
    struct dd_curl_opts *nonnull opts, zend_long option, zval *nonnull value)
{
    if (option < 0) {
        return;
    }
    ZVAL_DEREF(value);
    bool truthy = zend_is_true(value);
    bool non_null = Z_TYPE_P(value) != IS_NULL;

    if (_string_option(option) && Z_TYPE_P(value) == IS_OBJECT) {
        // ext/curl converts it (with __toString(), or fails): what it
        // applied is not known, and converting it here would run the app's
        // code a second time (G4.10)
        opts->unknown_option = true;
        return;
    }

    if (option == _opt.url) {
        _replace_str(&opts->url, value);
    } else if (option == _opt.default_protocol) {
        _replace_applied_str(&opts->default_protocol, value);
    } else if (option == _opt.customrequest) {
        _replace_applied_str(&opts->customrequest, value);
    } else if (option == _opt.cookie) {
        _replace_applied_str(&opts->cookie, value);
    } else if (option == _opt.header) {
        opts->header = truthy;
    } else if (option == _opt.httpheader) {
        zval lines = {0}; // IS_UNDEF, the payload zeroed too
        if (Z_TYPE_P(value) == IS_ARRAY &&
            !_copy_lines(Z_ARRVAL_P(value), &lines)) {
            opts->unknown_option = true; // an object element (G4.10)
            return;
        }
        _set_owned(&opts->httpheader, &lines);
    } else if (option == _opt.postfields) {
        zval body;
        bool multipart;
        if (!_postfields_value(value, &body, &multipart)) {
            // an object element (G4.10), or an object on PHP 8 that the
            // hook did not convert
            opts->unknown_option = true;
            return;
        }
        _set_owned(&opts->postfields, &body);
        opts->method = DD_CURL_METHOD_POST;
        opts->postfields_unsure = false;
        // ext/curl sets a multipart body with CURLOPT_MIMEPOST (or, before
        // 7.3, CURLOPT_HTTPPOST), which implies !NOBODY; a string with
        // CURLOPT_COPYPOSTFIELDS, which does not
        if (multipart) {
            opts->nobody = false;
            opts->method_unsure = false;
        }
    } else if (option == _opt.post) {
        opts->method = truthy ? DD_CURL_METHOD_POST : DD_CURL_METHOD_GET;
        opts->nobody = opts->nobody && !truthy;
        opts->method_unsure = opts->method_unsure && !truthy;
        if (truthy && Z_TYPE(opts->postfields) != IS_UNDEF &&
            Z_TYPE(opts->postfields) != IS_STRING) {
            opts->postfields_unsure = true;
        }
    } else if (option == _opt.httpget) {
        if (truthy) {
            opts->method = DD_CURL_METHOD_GET;
            opts->nobody = false;
            opts->upload = false;
            opts->method_unsure = false;
        }
    } else if (option == _opt.put || option == _opt.upload) {
        // the opposite of an upload is a GET, whatever the method was
        opts->method = truthy ? DD_CURL_METHOD_PUT : DD_CURL_METHOD_GET;
        opts->upload = truthy;
        opts->nobody = opts->nobody && !truthy;
        opts->method_unsure = opts->method_unsure && !truthy;
    } else if (option == _opt.nobody) {
        opts->nobody = truthy;
        if (truthy) {
            opts->method = DD_CURL_METHOD_HEAD;
            opts->method_unsure = false;
        } else if (opts->method == DD_CURL_METHOD_HEAD) {
            opts->method = DD_CURL_METHOD_GET;
        }
    } else if (option == _opt.readfunction) {
        opts->readfunction = non_null;
    } else if (option == _opt.infile) {
        opts->infile = non_null;
    } else if (option == _opt.prereqfunction) {
        opts->prereqfunction = non_null;
    } else if (option == _opt.followlocation) {
        opts->followlocation = truthy;
    }
    if (opts->upload && (opts->method != DD_CURL_METHOD_PUT || opts->nobody) &&
        dd_curl_linkage_version_num() < DD_CURL_UPLOAD_SEPARATE_SINCE) {
        // Before 8.1.0, libcurl sends a PUT (without POSTFIELDS) once
        // CURLOPT_UPLOAD is set, whatever POST, POSTFIELDS or NOBODY set
        // since, until UPLOAD=0 or HTTPGET; from 8.1.0 on, the last of them
        // decides. Distributions backported the change (e.g. Debian's
        // 7.88.1), so the version does not tell which.
        opts->method_unsure = true;
    }
}

enum dd_curl_method dd_curl_opts_method(const struct dd_curl_opts *nonnull opts)
{
    return opts->nobody ? DD_CURL_METHOD_HEAD : opts->method;
}

bool dd_curl_opts_postfields_sent(const struct dd_curl_opts *nonnull opts)
{
    return dd_curl_opts_method(opts) == DD_CURL_METHOD_POST &&
           !opts->postfields_unsure && !opts->method_unsure;
}

const char *nullable dd_curl_opts_skip_reason(
    const struct dd_curl_opts *nonnull opts)
{
    if (opts->unknown_option) {
        return "unknown_option";
    }
    if (opts->prereqfunction) {
        // the hop structure must come from our own PREREQ callback (G5.13)
        return "prereqfunction";
    }
    if (opts->customrequest &&
        zend_string_equals_literal(opts->customrequest, "CONNECT")) {
        // libcurl sends CURLOPT_CUSTOMREQUEST as the method, as is; the
        // transfer is a tunnel request, not one to analyse (G5.2 area, G3.9)
        return "connect_method";
    }
    return NULL;
}

// The options whose value ext/curl turns into a string (an object only with
// __toString()) that we record as one, other than the URL and POSTFIELDS
static bool _string_option(zend_long option)
{
    return option == _opt.customrequest || option == _opt.cookie ||
           option == _opt.default_protocol;
}

// CURLFile and CURLStringFile (or a subclass): not converted to strings by
// ext/curl, they are parts of a multipart body; dropped from the state (G7.7)
static bool _is_curl_file(const zval *nonnull value)
{
    static const char *const names[] = {"curlfile", "curlstringfile"};
    for (size_t i = 0; i < sizeof(names) / sizeof(names[0]); i++) {
        zend_class_entry *ce =
            zend_hash_str_find_ptr(CG(class_table), names[i], strlen(names[i]));
        if (ce && instanceof_function(Z_OBJCE_P(value), ce)) {
            return true;
        }
    }
    return false;
}

// What ext/curl applies for a string option (G4.10, G4.13): the value
// converted as by zval_get_string(): "Array" for an array (without the
// diagnostic, which must not be raised a second time), "Resource id #n" for a
// resource, and NULL for null, which resets the option (verified on PHP 7.0
// to 8.5: libcurl sends its default again). Objects are not given here.
static zend_string *nullable _applied_str(zval *nonnull value)
{
    ZVAL_DEREF(value);
    switch (Z_TYPE_P(value)) {
    case IS_ARRAY:
        return zend_string_init("Array", sizeof("Array") - 1, 0);
    case IS_NULL:
        return NULL;
    default:
        return zval_get_string(value);
    }
}

static void _replace_applied_str(
    zend_string * nullable * nonnull dst, zval *nonnull value)
{
    zend_string *s = _applied_str(value);
    if (*dst) {
        zend_string_release(*dst);
    }
    *dst = s;
}

static zend_string *nullable _str_or_null(zval *nonnull value)
{
    ZVAL_DEREF(value);
    switch (Z_TYPE_P(value)) {
    case IS_STRING:
        return zend_string_copy(Z_STR_P(value));
    case IS_LONG:
    case IS_DOUBLE:
    case IS_TRUE:
    case IS_FALSE:
        return zval_get_string(value);
    default:
        // null, or something we won't convert ourselves (an object's
        // __toString could have side effects)
        return NULL;
    }
}

static void _replace_str(
    zend_string * nullable * nonnull dst, zval *nullable value)
{
    zend_string *s = value ? _str_or_null(value) : NULL;
    if (*dst) {
        zend_string_release(*dst);
    }
    *dst = s;
}

// The lines of CURLOPT_HTTPHEADER as ext/curl sends them (strings); false if
// one is an object
static bool _copy_lines(const zend_array *nonnull src, zval *nonnull dst)
{
    array_init_size(dst, zend_hash_num_elements(src));
    zval *line;
    ZEND_HASH_FOREACH_VAL((zend_array *)src, line)
    {
        ZVAL_DEREF(line);
        switch (Z_TYPE_P(line)) {
        case IS_OBJECT:
            zval_ptr_dtor(dst);
            ZVAL_UNDEF(dst);
            return false;
        case IS_ARRAY:
            add_next_index_string(dst, "Array");
            break;
        default:
            add_next_index_str(dst, zval_get_string(line));
        }
    }
    ZEND_HASH_FOREACH_END();
    return true;
}

// The text fields of an array body, under the names ext/curl uses: it takes
// the C string of the key, so a mangled name (protected and private
// properties) is an empty name. A field with one of the names already there
// replaces it. CURLFile objects are dropped (see _is_curl_file); false if any
// other object is found.
// NOLINTNEXTLINE(readability-function-cognitive-complexity)
static bool _copy_fields(const zend_array *nonnull src, zval *nonnull dst)
{
    array_init_size(dst, zend_hash_num_elements(src));
    zend_ulong idx;
    zend_string *key;
    zval *value;
    ZEND_HASH_FOREACH_KEY_VAL_IND((zend_array *)src, idx, key, value)
    {
        ZVAL_DEREF(value);
        zval copy;
        if (Z_TYPE_P(value) == IS_OBJECT) {
            if (!_is_curl_file(value)) {
                zval_ptr_dtor(dst);
                ZVAL_UNDEF(dst);
                return false;
            }
            continue;
        }
        if (Z_TYPE_P(value) == IS_ARRAY) {
#if PHP_VERSION_ID < 80200
            // ext/curl stringifies the field (and raises the notice, which
            // the hook does not): the text field "Array". Its elements are
            // not looked at, so an object among them does not skip (G7.7)
            ZVAL_STRINGL(&copy, "Array", sizeof("Array") - 1);
#else
            // the values ext/curl sends for a field, one part per element
            // under the outer name (_array_body)
            array_init(&copy);
            zval *elem;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), elem)
            {
                ZVAL_DEREF(elem);
                if (Z_TYPE_P(elem) == IS_OBJECT) {
                    zval_ptr_dtor(&copy);
                    zval_ptr_dtor(dst);
                    ZVAL_UNDEF(dst);
                    return false;
                }
                if (Z_TYPE_P(elem) == IS_RESOURCE) {
                    // the text ext/curl sends; no reference to the app's
                    add_next_index_str(&copy, zval_get_string(elem));
                } else if (Z_TYPE_P(elem) == IS_ARRAY) {
                    // stringified, with a notice of ext/curl's own (checked
                    // on the wire on 8.3 and 8.5)
                    add_next_index_stringl(&copy, "Array", sizeof("Array") - 1);
                } else {
                    Z_TRY_ADDREF_P(elem);
                    add_next_index_zval(&copy, elem);
                }
            }
            ZEND_HASH_FOREACH_END();
#endif
        } else if (Z_TYPE_P(value) == IS_RESOURCE) {
            // the text ext/curl sends; no reference to the app's resource
            ZVAL_STR(&copy, zval_get_string(value));
        } else {
            ZVAL_COPY(&copy, value);
        }
        if (key) {
            zend_string *name = zend_string_init(
                ZSTR_VAL(key), strlen(ZSTR_VAL(key)), 0); // NOLINT
            zend_hash_update(Z_ARRVAL_P(dst), name, &copy);
            zend_string_release(name);
        } else {
            zend_hash_index_update(Z_ARRVAL_P(dst), idx, &copy);
        }
    }
    ZEND_HASH_FOREACH_END();
    return true;
}

// The body CURLOPT_POSTFIELDS sets, as a value of our own: out is IS_UNDEF
// for null, the scalar itself, an array of the text fields for an array (or,
// before PHP 8, the snapshot of the properties of an object, which is what
// ext/curl sends). Returns false if an object element, or an object that has
// no such meaning, makes it not recordable.
static bool _postfields_value(
    zval *nonnull value, zval *nonnull out, bool *nonnull multipart)
{
    *out = (zval){0}; // IS_UNDEF, the payload zeroed too
    *multipart = false;
    switch (Z_TYPE_P(value)) {
    case IS_NULL:
        return true;
    case IS_ARRAY:
        // ext/curl builds a multipart form, whatever the parts are
        *multipart = zend_hash_num_elements(Z_ARRVAL_P(value)) > 0;
        return _copy_fields(Z_ARRVAL_P(value), out);
    case IS_OBJECT:
#if PHP_VERSION_ID < 80000
    {
        const zend_array *props = Z_OBJPROP_P(value);
        *multipart = props && zend_hash_num_elements(props) > 0;
        return props && _copy_fields(props, out);
    }
#endif
    // from PHP 8 on an object is not converted either
    case IS_RESOURCE:
        return false;
    default:
        ZVAL_COPY(out, value);
        return true;
    }
}

// dst takes the value (IS_UNDEF clears it)
// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static void _set_owned(zval *nonnull dst, zval *nonnull value)
{
    zval old;
    ZVAL_COPY_VALUE(&old, dst);
    if (Z_TYPE_P(value) == IS_UNDEF) {
        ZVAL_UNDEF(dst); // the payload of an IS_UNDEF zval is not defined
    } else {
        ZVAL_COPY_VALUE(dst, value);
    }
    zval_ptr_dtor(&old);
}

#ifdef TESTING
static void _add_str_or_null(zval *arr, const char *key, zend_string *s)
{
    if (s) {
        add_assoc_str(arr, key, zend_string_copy(s));
    } else {
        add_assoc_null(arr, key);
    }
}

static void _add_zval_or_null(zval *arr, const char *key, const zval *zv)
{
    if (Z_TYPE_P(zv) == IS_UNDEF) {
        add_assoc_null(arr, key);
    } else {
        zval copy;
        ZVAL_COPY(&copy, zv);
        add_assoc_zval(arr, key, &copy);
    }
}

void dd_curl_opts_to_array(
    const struct dd_curl_opts *nonnull opts, zval *nonnull arr)
{
    static const char *const methods[] = {"GET", "POST", "PUT", "HEAD"};
    array_init(arr);
    _add_str_or_null(arr, "url", opts->url);
    _add_str_or_null(arr, "default_protocol", opts->default_protocol);
    add_assoc_string(arr, "method", (char *)methods[dd_curl_opts_method(opts)]);
    _add_str_or_null(arr, "customrequest", opts->customrequest);
    _add_zval_or_null(arr, "httpheader", &opts->httpheader);
    _add_str_or_null(arr, "cookie", opts->cookie);
    _add_zval_or_null(arr, "postfields", &opts->postfields);
    add_assoc_bool(arr, "header", opts->header);
    add_assoc_bool(arr, "readfunction", opts->readfunction);
    add_assoc_bool(arr, "infile", opts->infile);
    add_assoc_bool(arr, "prereqfunction", opts->prereqfunction);
    add_assoc_bool(arr, "followlocation", opts->followlocation);
}
#endif
