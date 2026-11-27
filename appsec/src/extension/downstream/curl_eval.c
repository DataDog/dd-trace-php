// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "curl_eval.h"

#include <Zend/zend_exceptions.h>
#include <ctype.h>

#include "../commands/request_exec.h"
#include "../configuration.h"
#include "../ddappsec.h"
#include "../duration_acc.h"
#include "../entity_body.h"
#include "../helper_process.h"
#include "../logging.h"
#include "../msgpack_helpers.h"
#include "../php_helpers.h"
#include "../php_objects.h"
#include "../request_lifecycle.h"
#include "../string_helpers.h"
#include "../telemetry.h"
#include "curl_linkage.h"
#include "form_urlencoded.h"

// libcurl constants (curl.h; stable public ABI): CURLINFO_STRING = 0x100000,
// CURLINFO_EFFECTIVE_URL = CURLINFO_STRING + 1, CURLINFO_EFFECTIVE_METHOD =
// CURLINFO_STRING + 58 (since 7.72.0, so always there when the PREREQ
// callback, 7.80.0+, asks for it); CURLE_OK = 0.
// CURLINFO_CONTENT_LENGTH_UPLOAD_T = CURLINFO_OFF_T (0x600000) + 16 (since
// 7.55.0), CURLINFO_CONTENT_LENGTH_UPLOAD = CURLINFO_DOUBLE (0x300000) + 16
// before; CURL_READFUNC_ABORT = 0x10000000, CURL_READFUNC_PAUSE = 0x10000001.
enum {
    DD_CURLINFO_EFFECTIVE_URL = 0x100001,
    DD_CURLINFO_EFFECTIVE_METHOD = 0x100000 + 58,
    DD_CURLINFO_CONTENT_LENGTH_UPLOAD = 0x300000 + 16,
    DD_CURLINFO_CONTENT_LENGTH_UPLOAD_T = 0x600000 + 16,
    DD_CURL_UPLOAD_T_MIN_VERSION = 0x073700, // 7.55.0
    DD_CURLE_OK = 0,
};
#define DD_CURL_READFUNC_ABORT ((size_t)0x10000000)
#define DD_CURL_READFUNC_PAUSE ((size_t)0x10000001)

enum {
    HTTP_FINAL_MIN = 200,
    HTTP_MOVED_PERMANENTLY = 301,
    HTTP_FOUND = 302,
    HTTP_SEE_OTHER = 303,
    HTTP_TEMPORARY_REDIRECT = 307,
    HTTP_PERMANENT_REDIRECT = 308,
};

// libcurl's MAX_SCHEME_LEN (lib/urlapi-int.h)
enum { DD_CURL_MAX_SCHEME_LEN = 40 };

// the scheme libcurl uses for a URL
enum dd_url_scheme {
    DD_URL_SCHEME_HTTP = 0, // http or https
    DD_URL_SCHEME_OTHER,
};

// the content types whose bodies are parsed: JSON and XML for requests and
// responses, urlencoded for requests only (G7.6, G7.8); nothing else, raw
// bodies are never sent
enum dd_body_kind {
    DD_BODY_NONE = 0,
    DD_BODY_JSON_XML,
    DD_BODY_URLENCODED,
};

// A JSON or XML body as the entity-body converter produced it, MessagePack
// that goes to the helper as it is (G7.10). msgpack.ptr is NULL if there is
// none; the buffer is owned by this struct until _send_raw() takes it.
struct dd_raw_body {
    const char *key;
    ddog_AppsecBodyMessagePack msgpack;
};

// transfers started in the current request (subcontext ids)
static THREAD_LOCAL_ON_ZTS uint64_t _seq;

// Calls into libcurl in progress from our hooks (curl_exec,
// curl_multi_exec, ...): while there is one, the app's code may be running
// inside a libcurl callback, and a curl hook entered then does nothing
// (G4.3, G9.9). One depth for the thread: a fiber suspended inside libcurl
// makes the others' hooks nested too.
static THREAD_LOCAL_ON_ZTS unsigned _libcurl_depth;
// A bailout was caught in one of our libcurl callbacks and not re-raised
// yet: it is re-raised by the hook that called libcurl, once back from it
// (G9.3), and by nothing else
static THREAD_LOCAL_ON_ZTS bool _fatal_pending;
// the request's own data_truncated flag, while a downstream WAF call runs
// (its truncation is not the request's, G6.18): put back when the call is
// done, or when a bailout cut it short
static THREAD_LOCAL_ON_ZTS bool _truncation_saved;
static THREAD_LOCAL_ON_ZTS bool _truncation_before;
// the pending exception of a callback that threw, saved while a downstream
// WAF call runs (G9.12): put back when the call is done, or when a bailout
// cut it short
static THREAD_LOCAL_ON_ZTS bool _exception_saved;
static THREAD_LOCAL_ON_ZTS zend_object *_saved_exception;
#ifdef TESTING
// curl_eval_fatal_after(): WAF calls left until one raises a fatal error
static THREAD_LOCAL_ON_ZTS zend_long _fatal_after;
static THREAD_LOCAL_ON_ZTS bool _fatal_after_armed;
// the fatal error is raised once the WAF call is done, not before it
static THREAD_LOCAL_ON_ZTS bool _fatal_after_late;
// curl_eval_gate_fatal_after_alloc(): read-function gate allocations left
// until one raises a fatal error
static THREAD_LOCAL_ON_ZTS zend_long _gate_fatal_alloc;
// curl_eval_pending_exception_calls(): WAF calls made with an exception pending
static THREAD_LOCAL_ON_ZTS zend_long _calls_with_exception;
#endif

static void _caught_bailout(struct dd_curl_eval *ev);
static void _restore_truncation(void);
static void _restore_exception(void);
static ZEND_NORETURN void _reraise(void);

static void _flush(struct dd_curl_eval *ev, const struct dd_curl_collect *c);
static void _hop_over(struct dd_curl_eval *ev, const struct dd_curl_collect *c);
static bool _hop_start(struct dd_curl_eval *ev, dd_curl_easy *cp);
static void _finish_hop(
    struct dd_curl_eval *ev, const struct dd_curl_collect *c, bool last_hop);
static void _close_hop(struct dd_curl_eval *ev);
static void _decide_sampling(struct dd_curl_eval *ev);
static void _release_slot(struct dd_curl_eval *ev);
static void _note_body_added(struct dd_curl_eval *ev);
static bool _sampled(const struct dd_curl_eval *ev);
static enum dd_url_scheme _url_scheme(zend_string *url,
    zend_string *nullable default_protocol,
    zend_string * nullable * nonnull http_url);
static bool _scheme_is_http(const char *scheme, size_t len);
static bool _prereq_call(struct dd_curl_eval *ev, dd_curl_easy *cp);
static void _advance(struct dd_curl_eval *ev, size_t hop);
static void _resp_call(
    struct dd_curl_eval *ev, const struct dd_curl_collect *c, bool last_hop);
static void _send(
    struct dd_curl_eval *ev, zend_array *data, bool last, bool may_block);
static void _send_raw(struct dd_curl_eval *ev, zend_array *data,
    struct dd_raw_body *nullable raw, bool last, bool may_block);
static void _add_body(struct dd_curl_eval *ev, zend_array *data,
    struct dd_raw_body *raw, const char *key, const struct dd_http_buf *buf,
    zend_string *ct);
static bool _resp_body_analysed(
    const struct dd_curl_eval *ev, const struct dd_curl_collect *c);
static void _presend_headers(
    const struct dd_curl_opts *opts, zend_string *url, zval *headers);
static void _presend_body(struct dd_curl_eval *ev,
    const struct dd_curl_opts *opts, const zend_array *headers,
    zend_array *data, struct dd_raw_body *raw);
static bool _postfields_sent(const struct dd_curl_opts *opts);
static void _array_body(const zend_array *fields, size_t cap, zval *out);
static zend_string *_scalar_str(zval *zv);
static void _add_host(zend_array *headers, zend_string *url);
static void _header_line(zend_array *headers, const char *line, size_t len);
static void _header_add(
    zend_array *headers, struct dd_http_slice name, struct dd_http_slice value);
static zend_string *_last_value(const zend_array *headers, const char *name);
static zend_string *_head_content_type(const struct dd_http_buf *head);
static struct dd_http_slice _head_content_type_slice(
    const struct dd_http_buf *head);
static enum dd_body_kind _body_kind(zend_string *nullable ct, bool response);
static enum dd_body_kind _body_kind_of(
    const char *ct, size_t len, bool response);
static bool _parse_content(const char *data, size_t len,
    zend_string *nullable ct, bool response, zval *out,
    ddog_AppsecBodyMessagePack *raw);
static void _gate_arm(struct dd_curl_eval *ev, const struct dd_curl_opts *opts,
    const zend_array *headers);
static void _gate_off(struct dd_curl_eval *ev);
static void *_gate_realloc(void *ptr, size_t size);
static void _gate_free(void *ptr);
static void _gate_hold(struct dd_curl_gate *g, const char *ptr, size_t n);
static void _gate_call(struct dd_curl_eval *ev);
static int64_t _upload_size(dd_curl_easy *cp);
static zend_string *_getinfo_str(dd_curl_easy *cp, int info);
static void _add_str(zend_array *data, const char *key, zend_string *s);
static struct dd_http_slice _trim(const char *p, const char *end);

static zend_string *_ssrf_zstr;
static zend_string *_request_zstr;
static zend_string *_response_zstr;

void dd_curl_eval_rinit(void)
{
    _seq = 0;
    _libcurl_depth = 0;
    _fatal_pending = false;
#ifdef TESTING
    _fatal_after = 0;
    _fatal_after_armed = false;
    _fatal_after_late = false;
    _gate_fatal_alloc = 0;
#endif
    _truncation_saved = false;
    _exception_saved = false;
}

void dd_curl_eval_rshutdown(void)
{
    // a fatal error that cut a downstream WAF call short
    _restore_truncation();
    // a fatal outside zend_try can leave the flag set. The saved exception
    // is not restored: raising during shutdown after a fatal is riskier
    // than dropping the flag
    _exception_saved = false;
    _libcurl_depth = 0;
    _fatal_pending = false;
}

void dd_curl_eval_libcurl_enter(void)
{
    // a fatal error swallowed by a sandbox may have skipped the restore
    _restore_truncation();
    _restore_exception();
    _libcurl_depth++;
}

void dd_curl_eval_hook_enter(void)
{
    _restore_truncation();
    _restore_exception();
}

void dd_curl_eval_libcurl_leave(void)
{
    if (_libcurl_depth > 0) {
        _libcurl_depth--;
    }
}

bool dd_curl_eval_in_libcurl(void) { return _libcurl_depth > 0; }

void dd_curl_eval_reraise_pending(void)
{
    // only from hooks that do something, so never inside libcurl (G4.3)
    if (_fatal_pending) {
        _reraise();
    }
}

void dd_curl_eval_init(struct dd_curl_eval *nonnull ev)
{
    memset(ev, 0, sizeof(*ev));
}

void dd_curl_eval_destroy(struct dd_curl_eval *nonnull ev)
{
    _gate_off(ev);
    _release_slot(ev);
    _advance(ev, 0);
    if (ev->url) {
        zend_string_release(ev->url);
    }
    dd_request_abort_destroy_block_params(&ev->block_params);
    dd_curl_eval_init(ev);
}

const char *nullable dd_curl_eval_skip_reason(
    const struct dd_curl_opts *nonnull opts)
{
    if (!DDAPPSEC_G(active)) {
        return "appsec_inactive";
    }
    zend_string *url = NULL;
    bool other = opts->url && _url_scheme(opts->url, opts->default_protocol,
                                  &url) == DD_URL_SCHEME_OTHER;
    if (url) {
        zend_string_release(url);
    }
    if (other) {
        return "scheme";
    }
    if (!opts->url) {
        // not set (curl_exec() fails before sending anything); a URL that
        // could not be recorded exactly is the unknown_option reason, from
        // dd_curl_opts_skip_reason. Nothing is read back from libcurl.
        return "not_evaluated";
    }
    if (!dd_req_lifecycle_is_active()) {
        // as push_addresses does for a RASP call out of a request
        dd_telemetry_add_rasp_rule_skipped(_ssrf_zstr, _request_zstr);
        return "not_evaluated";
    }
    return NULL;
}

void dd_curl_eval_start(struct dd_curl_eval *nonnull ev,
    const struct dd_curl_opts *nonnull opts, size_t body_cap)
{
    dd_curl_eval_destroy(ev);
    if (!DDAPPSEC_G(active) || !dd_req_lifecycle_is_active()) {
        return;
    }
    // only http(s) URLs are analysed, and only if the URL is known
    zend_string *url = NULL;
    if (!opts->url || _url_scheme(opts->url, opts->default_protocol, &url) ==
                          DD_URL_SCHEME_OTHER) {
        return;
    }
    ev->active = true;
    ev->url = url;
    ev->seq = ++_seq;
    ev->body_cap = body_cap;
}

// The decision whether the bodies of the transfer are sent (G8.9): taken once,
// when the transfer starts (W1), and kept for both bodies, the request body of
// hop 0 and the response body of the last hop. A sampled transfer holds a slot
// of the per-request limit, given back by _release_slot() if it ends up
// sending no body (G8.11).
static void _decide_sampling(struct dd_curl_eval *ev)
{
    if (dd_should_send_downstream_bodies()) {
        ev->sampling = DD_CURL_SAMPLING_YES;
        ev->slot = true;
    } else {
        ev->sampling = DD_CURL_SAMPLING_NO;
    }
}

// Records that a body was added to a WAF call; the metric counts the transfer
// once, however many of its bodies are added
static void _note_body_added(struct dd_curl_eval *ev)
{
    if (!ev->body_added) {
        ev->body_added = true;
        dd_downstream_body_analysed();
    }
}

// Gives the slot back unless a body was added to a WAF call
static void _release_slot(struct dd_curl_eval *ev)
{
    if (ev->slot && !ev->body_added) {
        dd_downstream_release_slot();
    }
    ev->slot = false;
}

// Whether the bodies of the transfer are sent; never decides
static bool _sampled(const struct dd_curl_eval *ev)
{
    return ev->sampling == DD_CURL_SAMPLING_YES;
}

// The scheme libcurl uses for url: the one before ":/", compared
// case-insensitively, if made of a letter and then letters, digits, "+", "-"
// or "." (at most DD_CURL_MAX_SCHEME_LEN); for a URL without one,
// CURLOPT_DEFAULT_PROTOCOL or, without that, "ftp", "dict", "ldap", "imap",
// "smtp" or "pop3" if the host name starts with that and a dot
// (case-insensitively), "http" otherwise. That is Curl_is_absolute_url() in
// guessing mode and the guess in parseurl() of lib/urlapi.c in 8.2.0-8.23.0.
// The differences with older versions (a digit first in 7.63-8.1, the
// sscanf() of parseurlandfillconn() in 7.29-7.61) are for URLs that libcurl
// rejects either way. For http(s), *http_url is the URL with its scheme, as
// libcurl requests it (CURLINFO_EFFECTIVE_URL).
static enum dd_url_scheme _url_scheme(zend_string *url,
    zend_string *nullable default_protocol,
    zend_string * nullable * nonnull http_url)
{
    *http_url = NULL;
    const char *s = ZSTR_VAL(url);
    size_t i = 0;
    // ASCII only, like libcurl's ISALPHA/ISALNUM (whatever the locale)
#define IS_ALPHA(ch)                                                           \
    (((ch) >= 'a' && (ch) <= 'z') || ((ch) >= 'A' && (ch) <= 'Z'))
#define IS_DIGIT(ch) ((ch) >= '0' && (ch) <= '9')
    if (IS_ALPHA(s[0])) {
        for (i = 1; i < DD_CURL_MAX_SCHEME_LEN; i++) {
            char ch = s[i];
            if (!IS_ALPHA(ch) && !IS_DIGIT(ch) && ch != '+' && ch != '-' &&
                ch != '.') {
                break;
            }
        }
    }
#undef IS_ALPHA
#undef IS_DIGIT
    if (i > 0 && s[i] == ':' && s[i + 1] == '/') {
        if (!_scheme_is_http(s, i)) {
            return DD_URL_SCHEME_OTHER;
        }
        *http_url = zend_string_copy(url);
        return DD_URL_SCHEME_HTTP;
    }

    // no scheme
    const char *scheme = "http";
    size_t scheme_len = LSTRLEN("http");
    if (default_protocol) {
        scheme = ZSTR_VAL(default_protocol);
        scheme_len = ZSTR_LEN(default_protocol);
    } else {
        // the host name: after any user info, up to the path, query or
        // fragment
        const char *host = s;
        const char *end = s + strcspn(s, "/?#");
        for (const char *p = end; p > s; p--) {
            if (p[-1] == '@') {
                host = p;
                break;
            }
        }
        static const char *const guessed[] = {
            "ftp.", "dict.", "ldap.", "imap.", "smtp.", "pop3."};
        for (size_t g = 0; g < sizeof(guessed) / sizeof(guessed[0]); g++) {
            size_t len = strlen(guessed[g]);
            if ((size_t)(end - host) >= len &&
                strncasecmp(host, guessed[g], len) == 0) {
                return DD_URL_SCHEME_OTHER;
            }
        }
    }
    if (!_scheme_is_http(scheme, scheme_len)) {
        return DD_URL_SCHEME_OTHER;
    }
    *http_url = strpprintf(0, "%.*s://%s", (int)scheme_len, scheme, s);
    return DD_URL_SCHEME_HTTP;
}

static bool _scheme_is_http(const char *scheme, size_t len)
{
    return (len == LSTRLEN("http") &&
               strncasecmp(scheme, LSTRARG("http")) == 0) ||
           (len == LSTRLEN("https") &&
               strncasecmp(scheme, LSTRARG("https")) == 0);
}

bool dd_curl_eval_presend(
    struct dd_curl_eval *nonnull ev, const struct dd_curl_opts *nonnull opts)
{
    if (!ev->active) {
        return false;
    }
    zend_array *data = zend_new_array(4);
    struct dd_raw_body raw = {0};
    _decide_sampling(ev);
    _add_str(data, "server.io.net.url", zend_string_copy(ev->url));
    zend_string *method = NULL;
    if (opts->customrequest) {
        // libcurl sends it as it is: no case change, and "" is a method
        method = zend_string_copy(opts->customrequest);
    } else if (!opts->method_unsure) {
        static const char *const methods[] = {"GET", "POST", "PUT", "HEAD"};
        const char *m = methods[dd_curl_opts_method(opts)];
        method = zend_string_init(m, strlen(m), 0);
    }
    if (method) {
        _add_str(data, "server.io.net.request.method", method);
    } // else: not sure, so not claimed

    zval headers;
    _presend_headers(opts, ev->url, &headers);
    _presend_body(ev, opts, Z_ARRVAL(headers), data, &raw);
    _gate_arm(ev, opts, Z_ARRVAL(headers));
    if (zend_hash_num_elements(Z_ARRVAL(headers)) > 0) {
        zend_hash_str_update(
            data, LSTRARG("server.io.net.request.headers"), &headers);
    } else {
        zval_ptr_dtor(&headers);
    }

    _send_raw(ev, data, &raw, false, true);
    return ev->blocked;
}

void dd_curl_eval_caught_bailout(struct dd_curl_eval *nonnull ev)
{
    _caught_bailout(ev);
}

void dd_curl_eval_prereq_hop_over(
    struct dd_curl_eval *nonnull ev, const struct dd_curl_collect *nonnull coll)
{
    if (!ev->active || ev->blocked || ev->bailout) {
        return;
    }
    // zend_bailout() clears it: the frame libcurl was called from stays
    zend_execute_data *volatile saved_frame = EG(current_execute_data);
    zend_try { _hop_over(ev, coll); }
    zend_catch
    {
        EG(current_execute_data) = saved_frame;
        _caught_bailout(ev);
    }
    zend_end_try();
}

bool dd_curl_eval_prereq_hop_start(
    struct dd_curl_eval *nonnull ev, dd_curl_easy *nonnull cp)
{
    if (ev->blocked || ev->bailout) {
        return true;
    }
    if (!ev->active) {
        return false;
    }
    volatile bool abort = false;
    zend_execute_data *volatile saved_frame = EG(current_execute_data);
    zend_try { abort = _hop_start(ev, cp); }
    zend_catch
    {
        EG(current_execute_data) = saved_frame;
        _caught_bailout(ev);
        abort = true;
    }
    zend_end_try();
    return abort;
}

void dd_curl_eval_end(
    struct dd_curl_eval *nonnull ev, const struct dd_curl_collect *nonnull coll)
{
    _gate_off(ev);
    if (ev->active && !ev->blocked && !ev->bailout) {
        // a fatal error in the closing WAF call must not skip the release
        // below: the hook raises it once back (G9.3)
        zend_execute_data *volatile saved_frame = EG(current_execute_data);
        zend_try { _flush(ev, coll); }
        zend_catch
        {
            EG(current_execute_data) = saved_frame;
            _caught_bailout(ev);
        }
        zend_end_try();
    }
    _release_slot(ev);
    ev->active = false;
}

size_t dd_curl_eval_gate_ask(
    struct dd_curl_eval *nonnull ev, dd_curl_easy *nonnull cp, size_t len)
{
    struct dd_curl_gate *g = &ev->gate;
    if (!g->total_read) {
        // known once libcurl prepared the request, before the first read
        g->total_read = true;
        g->total = _upload_size(cp);
    }
    if (g->total < 0) {
        return len; // chunked: the body ends at EOF
    }
    // libcurl reads no more than the size it sends (8.7+ asks for at most
    // what is left; up to 8.6 the upload is done once it is reached)
    if ((uint64_t)g->total <= g->got) {
        return 0;
    }
    uint64_t left = (uint64_t)g->total - g->got;
    return left < len ? (size_t)left : len;
}

enum dd_curl_gate_step dd_curl_eval_gate_take(struct dd_curl_eval *nonnull ev,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    const char *nullable ptr, size_t ret, size_t ask, size_t len)
{
    struct dd_curl_gate *g = &ev->gate;
    if (g->state != DD_CURL_GATE_FILLING) {
        // turned off while the original function ran: nothing to hold into
        return DD_CURL_GATE_STEP_PASS;
    }
    if (ret > ask) {
        // CURL_READFUNC_ABORT and CURL_READFUNC_PAUSE return at once, with no
        // WAF call (G6.7); after PAUSE, libcurl reads again once unpaused and
        // the gate goes on with what it holds.
        if (ret == DD_CURL_READFUNC_PAUSE) {
            return DD_CURL_GATE_STEP_PASS;
        }
        // Not stated by the report (implementation-defined): any other value
        // above libcurl's length is an error for libcurl (CURLE_READ_ERROR),
        // passed on unchanged, and nothing is analysed; so is one above the
        // ask but within libcurl's length (up to 8.6, the ask can be the
        // smaller one) while nothing is held, as libcurl would then get it
        // without the gate. With bytes held, libcurl would take that many
        // bytes of its buffer in their place: abort rather than send a body
        // the gate changed.
        bool held = g->got > 0;
        _gate_off(ev);
        return ret <= len && held ? DD_CURL_GATE_STEP_ABORT
                                  : DD_CURL_GATE_STEP_PASS;
    }
    // ask 0: the upload size is reached, the original function is not
    // called again (it has not returned EOF)
    bool ready = ret == 0;
    if (ret > 0 && ptr) {
        // no memory guard (G9.6): a fatal error at the memory limit is caught
        // and re-raised once libcurl has returned (G9.2, G9.3)
        volatile bool caught = false;
        zend_execute_data *volatile saved_frame = EG(current_execute_data);
        zend_try { _gate_hold(g, ptr, ret); }
        zend_catch
        {
            EG(current_execute_data) = saved_frame;
            _caught_bailout(ev);
            caught = true;
        }
        zend_end_try();
        if (caught) {
            _gate_off(ev);
            return DD_CURL_GATE_STEP_READY;
        }
    }
    g->eof = ret == 0 && ask > 0;
    if (!ready) {
        ready = g->got >= g->body.cap ||
                (g->total >= 0 && (uint64_t)g->total <= g->got);
    }
    if (!ready) {
        return DD_CURL_GATE_STEP_MORE;
    }
    g->state = DD_CURL_GATE_SERVING;
    zend_execute_data *volatile saved_frame = EG(current_execute_data);
    zend_try { _gate_call(ev); }
    zend_catch
    {
        EG(current_execute_data) = saved_frame;
        _caught_bailout(ev);
    }
    zend_end_try();
    if (ev->blocked || ev->bailout) {
        _gate_off(ev);
    }
    return DD_CURL_GATE_STEP_READY;
}

bool dd_curl_eval_gate_aborts(const struct dd_curl_eval *nonnull ev)
{
    return ev->blocked || ev->bailout;
}

size_t dd_curl_eval_gate_serve(struct dd_curl_eval *nonnull ev,
    char *nonnull ptr, size_t len, bool *nonnull forward)
{
    struct dd_curl_gate *g = &ev->gate;
    *forward = false;
    if (g->state != DD_CURL_GATE_SERVING) {
        *forward = true;
        return 0;
    }
    size_t n = 0;
    const struct dd_http_buf *bufs[] = {&g->body.buf, &g->over};
    size_t off = g->served;
    for (size_t i = 0; i < sizeof(bufs) / sizeof(bufs[0]) && n < len; i++) {
        const struct dd_http_buf *b = bufs[i];
        if (off >= b->len) {
            off -= b->len;
            continue;
        }
        size_t take = MIN(b->len - off, len - n);
        memcpy(ptr + n, b->ptr + off, take);
        n += take;
        off = 0;
    }
    g->served += n;
    if (g->served >= g->body.buf.len + g->over.len) {
        // all served: an EOF the original function returned is returned
        // once, by this call or (if it served bytes) the next one; without
        // one, the rest of the body comes from the original function
        bool eof = g->eof;
        if (n == 0 || !eof) {
            _gate_off(ev);
            *forward = !eof && n == 0;
        }
    }
    return n;
}

#ifdef TESTING
const char *nonnull dd_curl_eval_gate_state_name(
    const struct dd_curl_eval *nonnull ev)
{
    switch (ev->gate.state) {
    case DD_CURL_GATE_FILLING:
        return "filling";
    case DD_CURL_GATE_SERVING:
        return "serving";
    case DD_CURL_GATE_OFF:
    default:
        return "off";
    }
}
#endif

// Arms the read-function gate (G6.7) at W1: the transfer is sampled, its body
// comes from CURLOPT_READFUNCTION or CURLOPT_INFILE, no CURLOPT_POSTFIELDS is
// set and W1's Content-Type is one a body is analysed for (JSON, XML or
// urlencoded). Otherwise nothing is armed and the reads go straight through:
// a body that can never be analysed is not held up to the cap nor read ahead.
// The transfer's slot is not the gate's: it covers the response body too
// (G8.9) and is given back at the end if no body was added (G8.11).
static void _gate_arm(struct dd_curl_eval *ev, const struct dd_curl_opts *opts,
    const zend_array *headers)
{
    if (!_sampled(ev) || !(opts->readfunction || opts->infile) ||
        Z_TYPE(opts->postfields) != IS_UNDEF) {
        return;
    }
    zend_string *ct = _last_value(headers, "content-type");
    if (_body_kind(ct, false) == DD_BODY_NONE) {
        if (ct) {
            zend_string_release(ct);
        }
        return;
    }
    // the gate's buffer belongs to the gate and has no guard (G5.18, G9.6)
    static const struct dd_curl_alloc gate_alloc = {_gate_realloc, _gate_free};
    struct dd_curl_gate *g = &ev->gate;
    _gate_off(ev);
    dd_body_buf_init(&g->body, &gate_alloc, ev->body_cap);
    g->total = -1;
    g->ct = ct;
    g->state = DD_CURL_GATE_FILLING;
}

// Releases what the gate holds; reads go straight through from now on
static void _gate_off(struct dd_curl_eval *ev)
{
    struct dd_curl_gate *g = &ev->gate;
    if (g->state == DD_CURL_GATE_OFF && !g->body.alloc) {
        return; // never armed
    }
    if (g->body.alloc) {
        dd_body_buf_release(&g->body);
    }
    if (g->over.ptr) {
        efree(g->over.ptr);
    }
    if (g->ct) {
        zend_string_release(g->ct);
    }
    memset(g, 0, sizeof(*g));
}

static void *_gate_realloc(void *ptr, size_t size)
{
#ifdef TESTING
    if (_gate_fatal_alloc > 0) {
        _gate_fatal_alloc--;
        if (_gate_fatal_alloc == 0) {
            zend_error(E_ERROR, "Injected fatal error in downstream request "
                                "analysis");
        }
    }
#endif
    return erealloc(ptr, size);
}

static void _gate_free(void *ptr) { efree(ptr); }

// Holds n bytes the original read function returned: those up to the cap go
// to the body sink (what W3b analyses), the rest of this read, past the cap,
// is only held to be served
static void _gate_hold(struct dd_curl_gate *g, const char *ptr, size_t n)
{
    size_t room =
        g->body.cap > g->body.buf.len ? g->body.cap - g->body.buf.len : 0;
    size_t in = MIN(n, room);
    // the gate's sink only fails if its allocator does, which raises instead
    (void)g->body.sink.chunk(&g->body.sink, ptr, in);
    if (n > in) {
        size_t more = n - in;
        g->over.ptr = _gate_realloc(g->over.ptr, g->over.len + more);
        memcpy(g->over.ptr + g->over.len, ptr + in, more);
        g->over.len += more;
        g->over.cap = g->over.len;
    }
    g->got += n;
}

// W3b: the request-stage call of hop 0 with the body the gate read (up to the
// cap), parsed by the content type of CURLOPT_HTTPHEADER, in hop 0's
// subcontext. No call if the body is empty after parsing (G7.18).
static void _gate_call(struct dd_curl_eval *ev)
{
    struct dd_curl_gate *g = &ev->gate;
    if (!ev->active || ev->blocked || ev->bailout || ev->hop != 0 ||
        ev->closed) {
        return;
    }
    const struct dd_http_buf *buf = &g->body.buf;
    zval body;
    struct dd_raw_body raw = {0};
    if (!buf->ptr || buf->len == 0 ||
        _body_kind(g->ct, false) == DD_BODY_NONE ||
        !_parse_content(
            buf->ptr, buf->len, g->ct, false, &body, &raw.msgpack)) {
        return;
    }
    zend_array *data = zend_new_array(1);
    if (raw.msgpack.ptr) {
        raw.key = "server.io.net.request.body";
    } else {
        zend_hash_str_update(
            data, LSTRARG("server.io.net.request.body"), &body);
    }
    _note_body_added(ev);
    _send_raw(ev, data, &raw, false, true);
}

// The size of the upload libcurl sends, or -1 if it is not known (chunked)
static int64_t _upload_size(dd_curl_easy *cp)
{
    const struct dd_curl_functions *funcs = dd_curl_linkage_funcs();
    if (!funcs) {
        return -1;
    }
    if (dd_curl_linkage_version_num() >= DD_CURL_UPLOAD_T_MIN_VERSION) {
        int64_t size = -1; // curl_off_t
        if (funcs->curl_easy_getinfo(cp, DD_CURLINFO_CONTENT_LENGTH_UPLOAD_T,
                &size) != DD_CURLE_OK) {
            return -1;
        }
        return size;
    }
    static const double two_pow_63 = 9223372036854775808.0;
    double size = -1;
    if (funcs->curl_easy_getinfo(
            cp, DD_CURLINFO_CONTENT_LENGTH_UPLOAD, &size) != DD_CURLE_OK ||
        size < 0 || !(size < two_pow_63)) { // also NaN
        return -1;
    }
    return (int64_t)size;
}

void dd_curl_eval_reraise(struct dd_curl_eval *nonnull ev)
{
    if (!ev->bailout) {
        return;
    }
    // raised once; the transfer gets no WAF call after it either, not even
    // the closing call when a later hook ends it (G9.7)
    ev->bailout = false;
    ev->active = false;
    _reraise();
}

bool dd_curl_eval_settleable(const struct dd_curl_eval *nonnull ev)
{
    return ev->blocked || ev->bailout;
}

void dd_curl_eval_block(struct dd_curl_eval *nonnull ev)
{
    if (!ev->blocked) {
        return;
    }
    dd_result verdict = ev->verdict;
    struct block_params block_params = ev->block_params;
    memset(&ev->block_params, 0, sizeof(ev->block_params));
    ev->blocked = false;
    ev->active = false;
    mlog_g(dd_log_debug, "Blocking the request after downstream request %s",
        ev->url ? ZSTR_VAL(ev->url) : "(unknown)");
    dd_req_lifecycle_abort(REQUEST_STAGE_MID_REQUEST, verdict, &block_params);
    dd_request_abort_destroy_block_params(&block_params);
}

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

// The transfer is over: the last hop's response call is made now (the hops
// before it were finished in their successor's PREREQ), which closes its
// subcontext. This is the only place that runs the WAF for the last hop:
// nothing runs inside the interposed header and write functions (G9.1).
// Below libcurl 7.80 there is no PREREQ: no response data is analysed, hop 0
// is closed by the call without data and later hops make no call (G7.5,
// G10.1).
static void _flush(struct dd_curl_eval *ev, const struct dd_curl_collect *c)
{
    if (dd_curl_linkage_version_num() < DD_CURL_PREREQ_MIN_VERSION) {
        if (ev->hop == 0 && ev->opened && !ev->closed) {
            _send(ev, zend_new_array(0), true, false);
        }
        return;
    }
    _finish_hop(ev, c, true);
}

// A PREREQ call, before the collector starts the hop it announces: the hop
// that is over gets its response call, or the call without data. Only this
// PREREQ proves that hop was intermediate, and its tags are then kept even if
// the next hop's W2 blocks. Nothing for the first call: no hop is over then.
static void _hop_over(struct dd_curl_eval *ev, const struct dd_curl_collect *c)
{
    if (ev->prereqs > 0) {
        _finish_hop(ev, c, false);
    }
}

// The (n+1)-th PREREQ call of the transfer, after the collector started the
// hop: hop n is about to be sent. Hop 0 was evaluated, from the options,
// before the transfer started (W1). For hop n > 0 (a redirect, a 401/407
// authentication retry, or the same request sent again, e.g. on a new
// connection: every PREREQ call is a hop), CURLINFO_EFFECTIVE_URL and
// CURLINFO_EFFECTIVE_METHOD are already those of the new hop (checked with
// libcurl 7.88.1: after a 302 to a POST, "GET" and the Location URL). W2 is
// made whatever the collector holds (stopped at DD_HTTP_MAX_HOPS hops, after a
// refused allocation...): no redirect target goes unchecked.
static bool _hop_start(struct dd_curl_eval *ev, dd_curl_easy *cp)
{
    size_t hop = ev->prereqs++;
    if (hop == 0) {
        return false;
    }
    // the gate covers hop 0 only: a later hop's body (a 307/308 resend, an
    // authentication retry) goes straight through (G6.7)
    _gate_off(ev);
    _advance(ev, hop);
    return _prereq_call(ev, cp);
}

// The URL and method of the hop about to be sent, from libcurl
static bool _prereq_call(struct dd_curl_eval *ev, dd_curl_easy *cp)
{
    zend_string *url = _getinfo_str(cp, DD_CURLINFO_EFFECTIVE_URL);
    if (url) {
        zend_string *http_url = NULL;
        if (_url_scheme(url, NULL, &http_url) == DD_URL_SCHEME_OTHER) {
            // a later hop to e.g. dict:// is out of scope: no call, for it or
            // its response (not logged through PHP: this runs inside a
            // libcurl callback)
            mlog_no_php(dd_log_debug,
                "Not evaluating hop %zu of a curl transfer: scheme of %s",
                ev->hop, ZSTR_VAL(url));
            zend_string_release(url);
            ev->hop_skipped = true;
            return false;
        }
        if (http_url) {
            zend_string_release(http_url);
        }
    }
    zend_array *data = zend_new_array(2);
    if (url) {
        _add_str(data, "server.io.net.url", url);
    }
    zend_string *method = _getinfo_str(cp, DD_CURLINFO_EFFECTIVE_METHOD);
    if (method) {
        _add_str(data, "server.io.net.request.method", method);
    }
    _send(ev, data, false, true);
    return ev->blocked;
}

// Makes the response call of the current hop if it got a response (it is the
// last call of its subcontext), else closes the subcontext without data if a
// call opened it. The collector holds the hop's head only while it is its
// current hop (hop n is its (n+1)-th PREREQ) and it has not stopped (an
// allocation refused, too many hops): otherwise the hop is closed. last_hop:
// the hop is the last one of the transfer, the only one whose body is
// analysed.
static void _finish_hop(
    struct dd_curl_eval *ev, const struct dd_curl_collect *c, bool last_hop)
{
    if (ev->closed || ev->hop_skipped) {
        return;
    }
    bool held = !c->stopped && c->hops == ev->hop + 1;
    if (held && c->head_complete) {
        _resp_call(ev, c, last_hop);
    } else {
        _close_hop(ev);
    }
}

// Closes the subcontext of the current hop with the call without data, if a
// call opened it
static void _close_hop(struct dd_curl_eval *ev)
{
    if (ev->opened && !ev->closed) {
        _send(ev, zend_new_array(0), true, false);
    }
}

static void _advance(struct dd_curl_eval *ev, size_t hop)
{
    if (ev->subctx_id) {
        zend_string_release(ev->subctx_id);
    }
    ev->hop = hop;
    ev->subctx_id = NULL;
    ev->opened = false;
    ev->closed = false;
    ev->hop_skipped = false;
}

// The response call, one per hop: status (a decimal string), headers and, for
// the last hop, the body if it is analysed, as the collector holds them. It
// carries no URL: the request call of the hop shares its subcontext. Being
// made after the response is complete, a single WAF evaluation covers all of
// it.
static void _resp_call(
    struct dd_curl_eval *ev, const struct dd_curl_collect *c, bool last_hop)
{
    zend_array *data = zend_new_array(3);
    struct dd_raw_body raw = {0};
    if (c->status > 0) {
        zval status;
        ZVAL_STR(&status, strpprintf(0, "%d", c->status));
        zend_hash_str_update(
            data, LSTRARG("server.io.net.response.status"), &status);
    }
    zval headers;
    dd_curl_headers_to_array(c->head.ptr, c->head.len, &headers);
    if (zend_hash_num_elements(Z_ARRVAL(headers)) > 0) {
        zend_hash_str_update(
            data, LSTRARG("server.io.net.response.headers"), &headers);
    } else {
        zval_ptr_dtor(&headers);
    }
    if (last_hop && _resp_body_analysed(ev, c)) {
        zend_string *ct = _head_content_type(&c->head);
        // the standard body sink: the bytes the write function delivered,
        // up to the cap
        _add_body(
            ev, data, &raw, "server.io.net.response.body", &c->body.buf, ct);
        if (ct) {
            zend_string_release(ct);
        }
    }
    _send_raw(ev, data, &raw, true, false);
}

// Runs the WAF on data (consumed) in the subcontext of the current hop. A
// last call without data only closes the subcontext of a hop that ended
// without a final response call; the helper runs no WAF for it. Only a
// request-stage call (may_block) records a blocking verdict; for a
// response-stage call it is ignored: the response stage only produces tags and
// metrics.
static void _send(
    struct dd_curl_eval *ev, zend_array *data, bool last, bool may_block)
{
    _send_raw(ev, data, NULL, last, may_block);
}

// _send() with a body that is already MessagePack, written after the data's
// entries and outside of the serialiser's limits (G7.10). raw is consumed.
static void _send_raw(struct dd_curl_eval *ev, zend_array *data,
    struct dd_raw_body *nullable raw, bool last, bool may_block)
{
    bool has_raw = raw && raw->msgpack.ptr;
    if (zend_hash_num_elements(data) == 0 && !has_raw) {
        if (!last || !ev->opened) {
            zend_array_destroy(data);
            ev->closed = ev->closed || last;
            return;
        }
    }

    if (!ev->subctx_id) {
        ev->subctx_id = strpprintf(0, "curl-" ZEND_ULONG_FMT "-" ZEND_ULONG_FMT,
            (zend_ulong)ev->seq, (zend_ulong)ev->hop);
    }
#ifdef TESTING
    if (_fatal_after > 0) {
        _fatal_after--;
    }
    if (_fatal_after == 0 && _fatal_after_armed && !_fatal_after_late) {
        _fatal_after_armed = false;
        zend_array_destroy(data);
        if (has_raw) {
            dd_trace_drop_body_msgpack(raw->msgpack);
        }
        zend_error(E_ERROR, "Injected fatal error in downstream request "
                            "analysis");
        return;
    }
#endif
    // the closing call without data: the helper runs no WAF and records no
    // metric for it, so neither does the extension
    bool closing_only = zend_hash_num_elements(data) == 0 && !has_raw;
    dd_conn *conn =
        dd_req_lifecycle_is_active() ? dd_helper_mgr_cur_conn() : NULL;
    if (conn) {
        zend_string *rule_variant = NULL;
        if (!closing_only) {
            rule_variant = may_block ? _request_zstr : _response_zstr;
        }
        struct req_exec_opts opts = {.rasp_rule = _ssrf_zstr,
            .rule_variant = rule_variant,
            .subctx_id = ev->subctx_id,
            .subctx_last_call = last};
        dd_mpack_raw_entry raw_entry;
        if (has_raw) {
            raw_entry = (dd_mpack_raw_entry){.name = raw->key,
                .name_len = strlen(raw->key),
                .bytes = (const char *)raw->msgpack.ptr,
                .len = raw->msgpack.len};
            opts.raw = &raw_entry;
            opts.raw_count = 1;
        }
        struct block_params block_params = {0};
        struct timespec start = dd_monotime_start();
        // the truncation of downstream data is not the request's
        bool was_truncated = dd_msgpack_helpers_is_data_truncated();
        // the exception of a callback that threw (G9.12) is not the WAF
        // call's: it is saved around it
        _exception_saved = EG(exception) != NULL;
        if (_exception_saved) {
            dd_exception_save(&_saved_exception);
        }
#ifdef TESTING
        if (EG(exception)) {
            _calls_with_exception++;
        }
#endif
        _truncation_saved = true;
        _truncation_before = was_truncated;
        dd_result res = dd_request_exec(conn, data, &opts, &block_params);
#ifdef TESTING
        if (_fatal_after == 0 && _fatal_after_armed && _fatal_after_late) {
            _fatal_after_armed = false;
            zend_array_destroy(data);
            if (has_raw) {
                dd_trace_drop_body_msgpack(raw->msgpack);
            }
            zend_error(E_ERROR, "Injected fatal error in downstream request "
                                "analysis");
            return;
        }
#endif
        _restore_truncation();
        _restore_exception();
        if (!closing_only) {
            dd_duration_rasp_ext_account(&start);
        }
        if ((res == dd_should_block || res == dd_should_redirect) &&
            may_block && !ev->blocked) {
            // no PHP error reporting: this may run inside a libcurl callback
            mlog_no_php(dd_log_debug,
                "Downstream request %s: blocking verdict (subcontext %s)",
                ev->url ? ZSTR_VAL(ev->url) : "", ZSTR_VAL(ev->subctx_id));
            ev->blocked = true;
            ev->verdict = res;
            ev->block_params = block_params;
        } else {
            dd_request_abort_destroy_block_params(&block_params);
        }
    } else {
        mlog_no_php(dd_log_debug,
            "No helper connection; not analysing downstream request");
    }
    zend_array_destroy(data);
    if (has_raw) {
        dd_trace_drop_body_msgpack(raw->msgpack);
    }
    if (last) {
        ev->closed = true;
    } else {
        ev->opened = true;
    }
}

// Adds the parsed body under key if it is not empty after parsing (G7.18)
static void _add_body(struct dd_curl_eval *ev, zend_array *data,
    struct dd_raw_body *raw, const char *key, const struct dd_http_buf *buf,
    zend_string *ct)
{
    if (!buf->ptr || buf->len == 0 || _body_kind(ct, true) == DD_BODY_NONE ||
        !_sampled(ev)) {
        return;
    }
    zval body;
    if (!_parse_content(buf->ptr, buf->len, ct, true, &body, &raw->msgpack)) {
        return;
    }
    if (raw->msgpack.ptr) {
        raw->key = key;
    } else {
        zend_hash_str_update(data, key, strlen(key), &body);
    }
    _note_body_added(ev);
}

// Whether the response body is to be sent: a parsed content type, and sampled
// (G8.9). Content-Encoding is not looked at: with CURLOPT_ACCEPT_ENCODING
// libcurl decodes the bytes before the write function; without it they fail
// to parse (G7.17).
static bool _resp_body_analysed(
    const struct dd_curl_eval *ev, const struct dd_curl_collect *c)
{
    struct dd_http_slice ct = _head_content_type_slice(&c->head);
    if (!ct.ptr || _body_kind_of(ct.ptr, ct.len, true) == DD_BODY_NONE) {
        return false;
    }
    return _sampled(ev);
}

// CURLOPT_HTTPHEADER lines and CURLOPT_COOKIE, plus Host from the URL and
// the Content-Type libcurl would use for CURLOPT_POSTFIELDS if none is set
static void _presend_headers(
    const struct dd_curl_opts *opts, zend_string *url, zval *headers)
{
    array_init(headers);
    zend_array *ht = Z_ARRVAL_P(headers);
    if (Z_TYPE(opts->httpheader) == IS_ARRAY) {
        zval *line;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL(opts->httpheader), line)
        {
            ZVAL_DEREF(line);
            if (Z_TYPE_P(line) == IS_STRING) {
                _header_line(ht, Z_STRVAL_P(line), Z_STRLEN_P(line));
            }
        }
        ZEND_HASH_FOREACH_END();
    }
    if (opts->cookie) {
        zval *list = zend_hash_str_find(ht, LSTRARG("cookie"));
        if (!list) {
            zval new_list;
            array_init(&new_list);
            list = zend_hash_str_update(ht, LSTRARG("cookie"), &new_list);
        }
        add_next_index_str(list, zend_string_copy(opts->cookie));
    }
    _add_host(ht, url);
    if (_postfields_sent(opts) &&
        !zend_hash_str_exists(ht, LSTRARG("content-type"))) {
        const zval *pf = &opts->postfields;
        bool multipart = Z_TYPE_P(pf) == IS_ARRAY &&
                         zend_hash_num_elements(Z_ARRVAL_P(pf)) > 0;
        zval list;
        array_init(&list);
        add_next_index_string(&list, multipart
                                         ? "multipart/form-data"
                                         : "application/x-www-form-urlencoded");
        zend_hash_str_update(ht, LSTRARG("content-type"), &list);
    }
}

// CURLOPT_POSTFIELDS, if sampled and libcurl is to send it: a string is
// parsed by content type, an array is sent as the multipart fields. A body
// that libcurl takes from CURLOPT_READFUNCTION or CURLOPT_INFILE has no source
// in the options: none is sent here (the read-function gate reads it,
// _gate_arm). An object was recorded as a string (PHP 8,
// converted once by the curl_setopt() hook) or as the array of its
// properties (PHP 7).
static void _presend_body(struct dd_curl_eval *ev,
    const struct dd_curl_opts *opts, const zend_array *headers,
    zend_array *data, struct dd_raw_body *raw)
{
    if (!_postfields_sent(opts)) {
        return;
    }
    zval *pf = (zval *)&opts->postfields;
    zval body;
    if (Z_TYPE_P(pf) == IS_ARRAY) {
        if (zend_hash_num_elements(Z_ARRVAL_P(pf)) == 0 || !_sampled(ev)) {
            return;
        }
        _array_body(Z_ARRVAL_P(pf), ev->body_cap, &body);
        if (zend_hash_num_elements(Z_ARRVAL(body)) == 0) {
            // no field made it into the body: empty after parsing (G7.18)
            zval_ptr_dtor(&body);
            return;
        }
    } else {
        zend_string *str = _scalar_str(pf);
        if (!str) {
            return;
        }
        zend_string *ct = _last_value(headers, "content-type");
        if (ZSTR_LEN(str) == 0 || _body_kind(ct, false) == DD_BODY_NONE ||
            !_sampled(ev)) {
            zend_string_release(str);
            if (ct) {
                zend_string_release(ct);
            }
            return;
        }
        size_t len = ZSTR_LEN(str);
        if (ev->body_cap > 0 && len > ev->body_cap) {
            len = ev->body_cap;
        }
        bool parsed =
            _parse_content(ZSTR_VAL(str), len, ct, false, &body, &raw->msgpack);
        if (ct) {
            zend_string_release(ct);
        }
        zend_string_release(str);
        if (!parsed) {
            return;
        }
    }
    if (raw->msgpack.ptr) {
        raw->key = "server.io.net.request.body";
    } else {
        zend_hash_str_update(
            data, LSTRARG("server.io.net.request.body"), &body);
    }
    _note_body_added(ev);
}

// Whether libcurl sends CURLOPT_POSTFIELDS as the body of the first request
static bool _postfields_sent(const struct dd_curl_opts *opts)
{
    return Z_TYPE(opts->postfields) != IS_UNDEF &&
           dd_curl_opts_postfields_sent(opts);
}

// Array CURLOPT_POSTFIELDS (multipart): the bytes of field names and
// contents count towards the body limit (G7.12); no memory cost per field or
// element is estimated (G9.6). The state holds only the text fields:
// CURLFile and CURLStringFile parts were dropped when it was recorded, so no
// file is read and nothing here can run user code (G7.7). A nested array
// is a list of element values from PHP 8.2 only: below it the state holds the
// text "Array" for the field (see _copy_fields).
// NOLINTNEXTLINE(readability-function-cognitive-complexity)
static void _array_body(const zend_array *fields, size_t cap, zval *out)
{
    array_init(out);
    size_t cur = 0; // bytes of the names and contents copied
#define LEFT() (cur >= cap ? 0 : cap - cur)
    zend_ulong idx;
    zend_string *key;
    zval *value;
    ZEND_HASH_FOREACH_KEY_VAL((zend_array *)fields, idx, key, value)
    {
        if (LEFT() == 0 && cur > 0) {
            break; // the cap was reached by the previous field
        }
        zend_string *skey =
            key ? zend_string_copy(key) : strpprintf(0, ZEND_ULONG_FMT, idx);
        if (ZSTR_LEN(skey) > LEFT()) {
            // the key is capped too
            zend_string *t = zend_string_init(ZSTR_VAL(skey), LEFT(), 0);
            zend_string_release(skey);
            skey = t;
        }
        cur += ZSTR_LEN(skey);
        zval res;
        ZVAL_EMPTY_STRING(&res);
        if (LEFT() == 0) {
            zend_symtable_update(Z_ARRVAL_P(out), skey, &res);
            zend_string_release(skey);
            break;
        }
        ZVAL_DEREF(value);
        zend_string *content = NULL;
        if (Z_TYPE_P(value) == IS_ARRAY) {
            array_init(&res);
            zval *elem;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), elem)
            {
                if (LEFT() == 0) {
                    break;
                }
                zend_string *s = _scalar_str(elem);
                if (!s) {
                    continue;
                }
                if (ZSTR_LEN(s) > LEFT()) {
                    zend_string *t = zend_string_init(ZSTR_VAL(s), LEFT(), 0);
                    zend_string_release(s);
                    s = t;
                }
                cur += ZSTR_LEN(s);
                add_next_index_str(&res, s);
            }
            ZEND_HASH_FOREACH_END();
        } else {
            content = _scalar_str(value);
        }

        if (content) {
            if (ZSTR_LEN(content) > LEFT()) {
                zend_string *t = zend_string_init(ZSTR_VAL(content), LEFT(), 0);
                zend_string_release(content);
                content = t;
            }
            cur += ZSTR_LEN(content);
            ZVAL_STR(&res, content);
        }
        zend_symtable_update(Z_ARRVAL_P(out), skey, &res);
        zend_string_release(skey);
    }
    ZEND_HASH_FOREACH_END();
#undef LEFT
}

// The string form of a string, number, boolean or null (conversions that
// cannot run user code nor raise diagnostics); NULL for anything else
static zend_string *_scalar_str(zval *zv)
{
    ZVAL_DEREF(zv);
    switch (Z_TYPE_P(zv)) {
    case IS_STRING:
        return zend_string_copy(Z_STR_P(zv));
    case IS_NULL:
    case IS_FALSE:
    case IS_TRUE:
    case IS_LONG:
    case IS_DOUBLE:
        return zval_get_string(zv);
    default:
        return NULL;
    }
}

// Host header from the URL's authority, like parse_url() host[:port]
static void _add_host(zend_array *headers, zend_string *url)
{
    if (zend_hash_str_exists(headers, LSTRARG("host"))) {
        return;
    }
    const char *start = strstr(ZSTR_VAL(url), "://");
    if (!start) {
        return;
    }
    start += LSTRLEN("://");
    const char *end = start + strcspn(start, "/?#");
    for (const char *p = end; p > start; p--) {
        if (p[-1] == '@') {
            start = p;
            break;
        }
    }
    if (end > start && end[-1] == ':') {
        end--;
    }
    if (end == start) {
        return;
    }
    zval list;
    array_init(&list);
    add_next_index_stringl(&list, start, (size_t)(end - start));
    zend_hash_str_update(headers, LSTRARG("host"), &list);
}

// One "Name: value" header line, added to the lists of values by name
static void _header_line(zend_array *headers, const char *line, size_t len)
{
    const char *colon = memchr(line, ':', len);
    if (!colon) {
        return;
    }
    _header_add(headers, _trim(line, colon), _trim(colon + 1, line + len));
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

static zend_string *_last_value(const zend_array *headers, const char *name)
{
    zval *list = zend_hash_str_find(headers, name, strlen(name));
    if (!list || Z_TYPE_P(list) != IS_ARRAY) {
        return NULL;
    }
    zend_string *res = NULL;
    zval *v;
    ZEND_HASH_REVERSE_FOREACH_VAL(Z_ARRVAL_P(list), v)
    {
        if (Z_TYPE_P(v) == IS_STRING) {
            res = zend_string_copy(Z_STR_P(v));
            break;
        }
    }
    ZEND_HASH_FOREACH_END();
    return res;
}

static zend_string *_head_content_type(const struct dd_http_buf *head)
{
    struct dd_http_slice found = _head_content_type_slice(head);
    return found.ptr ? zend_string_init(found.ptr, found.len, 0) : NULL;
}

// The last non-empty Content-Type value of a response head, into the head
static struct dd_http_slice _head_content_type_slice(
    const struct dd_http_buf *head)
{
    struct dd_http_slice found = {0};
    if (!head || !head->ptr) {
        return found;
    }
    struct dd_http_header_iter it;
    struct dd_http_slice name;
    struct dd_http_slice value;
    dd_http_header_iter_init(&it, head->ptr, head->len);
    while (dd_http_header_iter_next(&it, &name, &value)) {
        if (name.len == LSTRLEN("content-type") &&
            strncasecmp(name.ptr, LSTRARG("content-type")) == 0 &&
            value.len > 0) {
            found = value;
        }
    }
    return found;
}

static enum dd_body_kind _body_kind(zend_string *nullable ct, bool response)
{
    if (!ct) {
        return DD_BODY_NONE;
    }
    return _body_kind_of(ZSTR_VAL(ct), ZSTR_LEN(ct), response);
}

static enum dd_body_kind _body_kind_of(
    const char *ct, size_t len, bool response)
{
    const char *end = ct + len;
    const char *semi = memchr(ct, ';', len);
    struct dd_http_slice mime = _trim(ct, semi ? semi : end);
#define MIME_IS(lit)                                                           \
    (mime.len == LSTRLEN(lit) && strncasecmp(mime.ptr, LSTRARG(lit)) == 0)
#define MIME_STARTS(lit)                                                       \
    (mime.len >= LSTRLEN(lit) && strncasecmp(mime.ptr, LSTRARG(lit)) == 0)
    if (MIME_STARTS("application/json") || MIME_STARTS("application/xml") ||
        MIME_STARTS("text/xml")) {
        return DD_BODY_JSON_XML;
    }
    if (!response && MIME_IS("application/x-www-form-urlencoded")) {
        return DD_BODY_URLENCODED;
    }
#undef MIME_IS
#undef MIME_STARTS
    return DD_BODY_NONE;
}

// Parses a body by content type: JSON and XML through the body parsers (as
// convert_json()/convert_xml()), for a request x-www-form-urlencoded like
// parse_str(), but natively (form_urlencoded.h). False for other content
// types (text/plain included) and for a body that is empty after parsing.
// JSON and XML are returned as the converter's MessagePack in *raw (the caller
// owns the buffer), the others as a zval in out.
static bool _parse_content(const char *data, size_t len,
    zend_string *nullable ct, bool response, zval *out,
    ddog_AppsecBodyMessagePack *raw)
{
    switch (_body_kind(ct, response)) {
    case DD_BODY_JSON_XML: {
        zend_string *lct = zend_string_tolower(ct);
        struct dd_http_slice t =
            _trim(ZSTR_VAL(lct), ZSTR_VAL(lct) + ZSTR_LEN(lct));
        zend_string *entity = zend_string_init(data, len, 0);
        ddog_AppsecBodyMessagePack body =
            dd_entity_body_convert(t.ptr, t.len, entity);
        zend_string_release(entity);
        zend_string_release(lct);
        // "{}" is a value; a failure, nothing and "null" are not
        if (!dd_body_msgpack_has_value(body)) {
            dd_trace_drop_body_msgpack(body);
            return false;
        }
        // the converter's bytes go to the helper as they are (G7.10)
        *raw = body;
        return true;
    }
    case DD_BODY_URLENCODED:
        dd_form_urlencoded_parse(data, len, out);
        if (zend_hash_num_elements(Z_ARRVAL_P(out)) == 0) {
            zval_ptr_dtor(out);
            return false;
        }
        return true;
    case DD_BODY_NONE:
    default:
        return false;
    }
}

static zend_string *_getinfo_str(dd_curl_easy *cp, int info)
{
    const struct dd_curl_functions *funcs = dd_curl_linkage_funcs();
    if (!funcs || !cp) {
        return NULL;
    }
    char *str = NULL;
    if (funcs->curl_easy_getinfo(cp, info, &str) != DD_CURLE_OK || !str) {
        return NULL;
    }
    return zend_string_init(str, strlen(str), 0);
}

static void _add_str(zend_array *data, const char *key, zend_string *s)
{
    zval zv;
    ZVAL_STR(&zv, s);
    zend_hash_str_update(data, key, strlen(key), &zv);
}

// like PHP's trim() (" \t\n\r\0\x0B")
static struct dd_http_slice _trim(const char *p, const char *end)
{
    while (p < end && (*p == ' ' || *p == '\t' || *p == '\n' || *p == '\r' ||
                          *p == '\0' || *p == '\v')) {
        p++;
    }
    while (
        end > p && (end[-1] == ' ' || end[-1] == '\t' || end[-1] == '\n' ||
                       end[-1] == '\r' || end[-1] == '\0' || end[-1] == '\v')) {
        end--;
    }
    return (struct dd_http_slice){.ptr = p, .len = (size_t)(end - p)};
}

// A bailout (fatal error) was caught in one of our libcurl callbacks: the
// transfer gets no more WAF calls, and the bailout is re-raised by the hook
// that called libcurl (dd_curl_eval_reraise, dd_curl_eval_reraise_pending)
// once back from it, before the tracer's end hook (G9.3). Nothing else raises
// it: the app's callbacks that run before it keep running (the longjmp must
// not cross their libcurl frames, G9.2).
static void _caught_bailout(struct dd_curl_eval *ev)
{
    _restore_truncation();
    _restore_exception();
    if (ev) {
        ev->bailout = true;
    }
    _fatal_pending = true;
}

static void _restore_exception(void)
{
    if (_exception_saved) {
        _exception_saved = false;
        dd_exception_restore(&_saved_exception);
    }
}

static void _restore_truncation(void)
{
    if (_truncation_saved) {
        _truncation_saved = false;
        dd_msgpack_helpers_set_data_truncated(_truncation_before);
    }
}

// Re-raises the bailout caught in our callback. Inside a tracer sandbox the
// sandbox swallows it, as it does any fatal error of a hook closure (G9.3).
static ZEND_NORETURN void _reraise(void)
{
    _fatal_pending = false;
    zend_bailout();
}

#ifdef TESTING
// curl_eval_fatal_after(int $calls, bool $late = false): void
// The $calls-th WAF call for downstream requests from now on raises a fatal
// error instead (as the memory limit would), wherever it is made; with $late,
// once the call is done (as for an allocation while handling its reply).
static PHP_FUNCTION(datadog_appsec_testing_curl_eval_fatal_after)
{
    zend_long calls;
    zend_bool late = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l|b", &calls, &late) ==
        FAILURE) {
        RETURN_FALSE;
    }
    _fatal_after = calls;
    _fatal_after_armed = calls > 0;
    _fatal_after_late = late;
}

// curl_eval_gate_fatal_after_alloc(int $allocs): void
// The $allocs-th allocation of a read-function gate's buffers from now on
// raises a fatal error instead (as the memory limit would).
static PHP_FUNCTION(datadog_appsec_testing_curl_eval_gate_fatal_after_alloc)
{
    zend_long allocs;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &allocs) == FAILURE) {
        RETURN_FALSE;
    }
    _gate_fatal_alloc = allocs;
}

// curl_eval_pending_exception_calls(): int
// How many WAF calls for downstream requests were made while an exception was
// pending (G9.12: none, they run with it saved).
static PHP_FUNCTION(datadog_appsec_testing_curl_eval_pending_exception_calls)
{
    RETURN_LONG(_calls_with_exception);
}

// curl_eval_catch_fatal(): void
// Leaves the state of a bailout caught in one of our libcurl callbacks (the
// fatal error to be re-raised by the next hook), without one.
static PHP_FUNCTION(datadog_appsec_testing_curl_eval_catch_fatal)
{
    zend_try
    {
        zend_error(E_ERROR, "Injected fatal error in downstream request "
                            "analysis");
    }
    zend_catch { _caught_bailout(NULL); }
    zend_end_try();
}

// curl_eval_data_truncated(): bool
// The request's data_truncated flag (the one request_shutdown reports).
static PHP_FUNCTION(datadog_appsec_testing_curl_eval_data_truncated)
{
    RETURN_BOOL(dd_msgpack_helpers_is_data_truncated());
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(fatal_after_arginfo, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, calls, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, late, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    gate_fatal_after_alloc_arginfo, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, allocs, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(catch_fatal_arginfo, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    data_truncated_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    pending_exception_calls_arginfo, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_eval_fatal_after", PHP_FN(datadog_appsec_testing_curl_eval_fatal_after), fatal_after_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_eval_gate_fatal_after_alloc", PHP_FN(datadog_appsec_testing_curl_eval_gate_fatal_after_alloc), gate_fatal_after_alloc_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_eval_catch_fatal", PHP_FN(datadog_appsec_testing_curl_eval_catch_fatal), catch_fatal_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_eval_data_truncated", PHP_FN(datadog_appsec_testing_curl_eval_data_truncated), data_truncated_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_eval_pending_exception_calls", PHP_FN(datadog_appsec_testing_curl_eval_pending_exception_calls), pending_exception_calls_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on
#endif

void dd_curl_eval_minit(void)
{
    _ssrf_zstr = zend_string_init_interned(LSTRARG("ssrf"), 1);
    _request_zstr = zend_string_init_interned(LSTRARG("request"), 1);
    _response_zstr = zend_string_init_interned(LSTRARG("response"), 1);
#ifdef TESTING
    if (get_global_DD_APPSEC_TESTING()) {
        dd_phpobj_reg_funcs(testing_functions);
    }
#endif
}
