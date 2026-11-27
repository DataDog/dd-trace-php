// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// The options of a PHP curl handle that matter to the downstream-request
// analysis, as set through curl_init(), curl_setopt(), curl_setopt_array(),
// curl_reset() and curl_copy_handle(). libcurl has no getters for most of
// them, and ext/curl keeps them in structs whose layout varies, so we record
// them as the app sets them (G4.6). Not recorded: credentials, VERBOSE,
// HEADER_OUT, DEBUGFUNCTION and the app's write and header callables.
//
// The option numbers are looked up from ext/curl's constants at startup;
// options whose constant does not exist (e.g. CURLOPT_PREREQFUNCTION before
// PHP 8.4) are never matched.

#include <php.h>
#include <stdbool.h>

#include "../attributes.h"

enum dd_curl_method {
    DD_CURL_METHOD_GET = 0,
    DD_CURL_METHOD_POST,
    DD_CURL_METHOD_PUT,
    DD_CURL_METHOD_HEAD,
};

struct dd_curl_opts {
    // NULL if not set, or not known: set to an object (ext/curl converted it
    // with __toString(), which must not be called again)
    zend_string *nullable url;
    zend_string *nullable default_protocol; // CURLOPT_DEFAULT_PROTOCOL
    zend_string *nullable customrequest;
    zend_string *nullable cookie;
    // Only values are kept, never an object passed to curl_setopt() (it
    // would delay the app's destructors and could make reference cycles):
    // httpheader is an array of strings, postfields a string or scalar, or
    // an array of the text fields (the snapshot of an object's properties
    // on PHP 7). IS_UNDEF if not set.
    zval httpheader;
    zval postfields;
    // libcurl's base method, from POST/PUT/UPLOAD/NOBODY/HTTPGET/POSTFIELDS
    // (its set.method), and its opt_no_body: NOBODY makes the request a HEAD
    // without a body even if POSTFIELDS set the method to POST afterwards;
    // POST, PUT/UPLOAD and HTTPGET (set) reset it. See dd_curl_opts_method().
    enum dd_curl_method method;
    bool nobody;
    // It is not known whether libcurl sends postfields: CURLOPT_POST came
    // after non-string ones (an array, or an object on PHP 7; libcurl then
    // sends a string POSTFIELDS kept from before, or the READFUNCTION's
    // data), or a curl_setopt() that threw may have changed the body or the
    // method since.
    bool postfields_unsure;
    // likewise, the method is not known (it is only known again once an
    // option sets both libcurl's method and opt_no_body)
    bool method_unsure;
    // libcurl's set.upload before 8.1.0: CURLOPT_UPLOAD/PUT, until either
    // is set to 0 or HTTPGET to 1
    bool upload;
    // the request body may come from the app's CURLOPT_READFUNCTION or from
    // a CURLOPT_INFILE stream (presence only)
    bool readfunction;
    bool infile;
    bool prereqfunction;
    bool header; // CURLOPT_HEADER
    bool followlocation;
    // ext/curl applied an option whose number we could not tell (a weak
    // mode conversion we do not know of): not observed until curl_reset()
    bool unknown_option;
};

// zend extension startup (after ext/curl's MINIT)
void dd_curl_opts_startup(void);

void dd_curl_opts_init(struct dd_curl_opts *nonnull opts);
void dd_curl_opts_dtor(struct dd_curl_opts *nonnull opts);
// curl_reset()
void dd_curl_opts_clear(struct dd_curl_opts *nonnull opts);
// curl_copy_handle(); dst must be initialized
void dd_curl_opts_copy(
    struct dd_curl_opts *nonnull dst, const struct dd_curl_opts *nonnull src);
// curl_init($url)
void dd_curl_opts_set_url(struct dd_curl_opts *nonnull opts, zval *nonnull url);
// curl_setopt() applied an option whose number is not known
void dd_curl_opts_set_unknown(struct dd_curl_opts *nonnull opts);
// Whether the curl_setopt() hook converts an object value of this option to a
// string itself (with __toString(), once), instead of ext/curl: the URL, and
// POSTFIELDS on PHP 8 (where ext/curl does the same conversion)
bool dd_curl_opts_converts_object(zend_long option);
// An option ext/curl accepted (curl_setopt() returned true; for each element
// of curl_setopt_array() too, which runs as curl_setopt() calls). An object
// value that cannot be recorded as a value (see G4.10) makes the handle
// unknown_option.
void dd_curl_opts_set(
    struct dd_curl_opts *nonnull opts, zend_long option, zval *nonnull value);
// The method of the request libcurl sends (unless CURLOPT_CUSTOMREQUEST)
enum dd_curl_method dd_curl_opts_method(
    const struct dd_curl_opts *nonnull opts);
// Whether libcurl sends CURLOPT_POSTFIELDS (if set) as the request body:
// the method is POST (not changed since by CURLOPT_UPLOAD/PUT, HTTPGET,
// NOBODY or POST=0) and nothing made it or the method unsure since.
bool dd_curl_opts_postfields_sent(const struct dd_curl_opts *nonnull opts);
// Why a handle with these options must not be observed; NULL if it can be.
const char *nullable dd_curl_opts_skip_reason(
    const struct dd_curl_opts *nonnull opts);

#ifdef TESTING
void dd_curl_opts_to_array(
    const struct dd_curl_opts *nonnull opts, zval *nonnull arr);
#endif
