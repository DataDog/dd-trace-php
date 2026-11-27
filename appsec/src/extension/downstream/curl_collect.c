// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "curl_collect.h"

#include <stdlib.h>
#include <string.h>

enum {
    HTTP_STATUS_MIN = 100,
    HTTP_STATUS_FINAL_MIN = 200, // below: interim (1xx)
    DECIMAL_BASE = 10,
    HEAD_INITIAL_CAP = 256,
};

static void *_libc_realloc(void *ptr, size_t size);
static void _libc_free(void *ptr);
static void _stop_oom(struct dd_curl_collect *c);
static bool _buf_grow(const struct dd_curl_alloc *alloc, struct dd_http_buf *b,
    size_t need, size_t limit);
static void _buf_release(
    const struct dd_curl_alloc *alloc, struct dd_http_buf *b);
static bool _body_chunk(
    struct dd_body_sink *sink, const char *data, size_t len);
static void _body_end(struct dd_body_sink *sink, bool framing_complete);
static void _body_reset(struct dd_body_buf *b);

void dd_curl_collect_init(struct dd_curl_collect *c,
    const struct dd_curl_alloc *alloc, size_t body_cap)
{
    static const struct dd_curl_alloc libc_alloc = {_libc_realloc, _libc_free};
    memset(c, 0, sizeof(*c));
    c->alloc = alloc ? alloc : &libc_alloc;
    c->head_cap = DD_HTTP_HEAD_CAP;
    dd_body_buf_init(&c->body, c->alloc, body_cap);
    c->sink = &c->body.sink;
}

void dd_body_buf_init(
    struct dd_body_buf *b, const struct dd_curl_alloc *alloc, size_t cap)
{
    static const struct dd_curl_alloc libc_alloc = {_libc_realloc, _libc_free};
    memset(b, 0, sizeof(*b));
    b->sink.chunk = _body_chunk;
    b->sink.end = _body_end;
    b->alloc = alloc ? alloc : &libc_alloc;
    b->cap = cap;
}

void dd_body_buf_release(struct dd_body_buf *b) { _body_reset(b); }

void dd_curl_collect_destroy(struct dd_curl_collect *c)
{
    _buf_release(c->alloc, &c->head);
    _body_reset(&c->body);
}

static void _head_clear(struct dd_curl_collect *c);
static void _head_append(
    struct dd_curl_collect *c, const char *line, size_t len);
static bool _is_blank(const char *line, size_t len);
static void _first_line(
    struct dd_curl_collect *c, const char *line, size_t len);
static void _blank_line(struct dd_curl_collect *c);

void dd_curl_collect_prereq(struct dd_curl_collect *c)
{
    if (c->stopped) {
        return;
    }
    if (c->hops == DD_HTTP_MAX_HOPS) {
        // a sanity limit, not a policy: no analysis of such a transfer
        c->hops_truncated = true;
        c->stopped = true;
        return;
    }
    c->hops++;
    c->interim = 0;
    _head_clear(c);
    // only the last hop's body is kept (G5.7): libcurl never calls the write
    // function for the body of a redirect it follows, and one buffer bounds
    // memory
    _body_reset(&c->body);
}

void dd_curl_collect_header_line(
    struct dd_curl_collect *c, const char *line, size_t len)
{
    // before hop 0's PREREQ these lines belong to a CONNECT tunnel; after a
    // complete head they are trailers or the tunnel head of a later hop
    if (c->stopped || len == 0 || c->hops == 0 || c->head_complete) {
        return;
    }
    if (_is_blank(line, len)) {
        if (!c->started) {
            return; // a blank line without a head
        }
        _head_append(c, line, len);
        if (c->stopped) {
            return; // refused: no complete (empty) head
        }
        _blank_line(c);
        return;
    }
    if (!c->started) {
        // the first line of a head: its status is read even if it does not
        // fit the cap
        c->started = true;
        _first_line(c, line, len);
    }
    _head_append(c, line, len);
}

void dd_curl_collect_write(
    struct dd_curl_collect *c, const char *data, size_t len)
{
    if (c->stopped || c->phase != DD_CURL_PHASE_BODY || len == 0) {
        return; // the head phase: head bytes of CURLOPT_HEADER (G5.9)
    }
    if (!c->sampled) {
        return; // nothing is copied for a transfer that is not analysed
    }
    if (!c->sink->chunk(c->sink, data, len)) {
        _stop_oom(c);
    }
}

void dd_curl_collect_stop(struct dd_curl_collect *c)
{
    c->stopped = true;
    _buf_release(c->alloc, &c->head);
    _body_reset(&c->body);
}

void dd_curl_collect_end(struct dd_curl_collect *c, bool framing_complete)
{
    // no end event before a complete head (no response was received: not an
    // empty body), nor for a transfer that is not analysed or was stopped
    if (c->stopped || !c->sampled || c->phase != DD_CURL_PHASE_BODY) {
        return;
    }
    c->sink->end(c->sink, framing_complete);
}

static void *_libc_realloc(void *ptr, size_t size)
{
    return realloc(ptr, size);
}

static void _libc_free(void *ptr) { free(ptr); }

// An allocation was refused: stop collecting and release what was collected.
// The transfer is not touched.
static void _stop_oom(struct dd_curl_collect *c)
{
    c->oom = true;
    dd_curl_collect_stop(c);
}

// Grows b so that it holds at least `need` bytes (need <= limit): doubling,
// never past limit. false: the allocator refused.
static bool _buf_grow(const struct dd_curl_alloc *alloc, struct dd_http_buf *b,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    size_t need, size_t limit)
{
    if (need <= b->cap) {
        return true;
    }
    size_t ncap = b->cap ? b->cap : HEAD_INITIAL_CAP;
    if (ncap > limit) {
        ncap = limit; // a small cap is never exceeded by the first block
    }
    while (ncap < need) { ncap = ncap > limit / 2 ? limit : ncap * 2; }
    if (ncap > limit) {
        ncap = limit; // also keeps ncap from wrapping
    }
    char *nptr = alloc->realloc_fn(b->ptr, ncap);
    if (!nptr) {
        return false;
    }
    b->ptr = nptr;
    b->cap = ncap;
    return true;
}

static void _buf_release(
    const struct dd_curl_alloc *alloc, struct dd_http_buf *b)
{
    if (b->ptr) {
        alloc->free_fn(b->ptr);
    }
    *b = (struct dd_http_buf){0};
}

// The standard sink. Copies up to the cap; the rest is not analysed (the app
// still gets it: the collector never holds anything back).
static bool _body_chunk(struct dd_body_sink *sink, const char *data, size_t len)
{
    struct dd_body_buf *b = (struct dd_body_buf *)sink;
    size_t room = b->cap - b->buf.len;
    size_t n = len < room ? len : room;
    if (n < len) {
        b->truncated = true;
    }
    if (n == 0) {
        return true;
    }
    if (!_buf_grow(b->alloc, &b->buf, b->buf.len + n, b->cap)) {
        return false;
    }
    memcpy(b->buf.ptr + b->buf.len, data, n);
    b->buf.len += n;
    return true;
}

static void _body_end(struct dd_body_sink *sink, bool framing_complete)
{
    struct dd_body_buf *b = (struct dd_body_buf *)sink;
    b->ended = true;
    b->framing_complete = framing_complete;
}

// drops the body (and what is known about it), keeps the cap
static void _body_reset(struct dd_body_buf *b)
{
    _buf_release(b->alloc, &b->buf);
    b->truncated = false;
    b->ended = false;
    b->framing_complete = false;
}

static void _head_clear(struct dd_curl_collect *c)
{
    c->head.len = 0;
    c->started = false;
    c->head_complete = false;
    c->head_truncated = false;
    c->status = 0;
    c->phase = DD_CURL_PHASE_HEAD;
}

static void _first_line(struct dd_curl_collect *c, const char *line, size_t len)
{
    int status = 0;
    while (len > 0 && (line[len - 1] == '\n' || line[len - 1] == '\r')) {
        len--;
    }
    if (dd_http_parse_status_line(line, len, &status)) {
        c->status = status;
    }
}

static void _blank_line(struct dd_curl_collect *c)
{
    if (c->status != 0 && c->status < HTTP_STATUS_FINAL_MIN) {
        // 1xx: discarded, the final head follows in the same hop
        c->interim++;
        _head_clear(c);
        return;
    }
    c->head_complete = true;
    c->phase = DD_CURL_PHASE_BODY;
}

static bool _is_blank(const char *line, size_t len)
{
    return (len == 1 && line[0] == '\n') ||
           (len == 2 && line[0] == '\r' && line[1] == '\n');
}

// The head is capped; what does not fit is dropped whole (a line is never cut
// in the middle) and the head is flagged as truncated. The blank line that
// completes the head is kept only if it fits. The buffer grows through the
// collector's allocator; a refusal sets oom and stops the collector.
static void _head_append(
    struct dd_curl_collect *c, const char *line, size_t len)
{
    if (c->head_truncated || len > c->head_cap - c->head.len) {
        c->head_truncated = true;
        return;
    }
    if (!_buf_grow(c->alloc, &c->head, c->head.len + len, c->head_cap)) {
        _stop_oom(c);
        return;
    }
    memcpy(c->head.ptr + c->head.len, line, len);
    c->head.len += len;
}

bool dd_http_parse_status_line(const char *line, size_t len, int *status)
{
    static const char prefix[] = "HTTP/";
    const size_t prefix_len = sizeof(prefix) - 1;
    if (len < prefix_len || memcmp(line, prefix, prefix_len) != 0) {
        return false;
    }
    const char *p = line + prefix_len;
    const char *end = line + len;
    // version: 1.1, 1.0, 2, 3
    const char *ver = p;
    while (p < end && *p != ' ') { p++; }
    if (p == ver || p == end) {
        return false;
    }
    while (p < end && *p == ' ') { p++; }
    if (end - p < 3) {
        return false;
    }
    int code = 0;
    for (int i = 0; i < 3; i++) {
        if (p[i] < '0' || p[i] > '9') {
            return false;
        }
        code = (code * DECIMAL_BASE) + (p[i] - '0');
    }
    if (end - p > 3 && p[3] != ' ') {
        return false;
    }
    if (code < HTTP_STATUS_MIN) {
        return false;
    }
    *status = code;
    return true;
}

void dd_http_header_iter_init(
    struct dd_http_header_iter *it, const char *head, size_t len)
{
    it->p = head;
    it->end = head ? head + len : head;
    if (!head) {
        return;
    }
    // skip the start line
    const char *lf = memchr(head, '\n', len);
    it->p = lf ? lf + 1 : it->end;
}

// NOLINTBEGIN(bugprone-easily-swappable-parameters)
bool dd_http_header_iter_next(struct dd_http_header_iter *it,
    struct dd_http_slice *name, struct dd_http_slice *value)
// NOLINTEND(bugprone-easily-swappable-parameters)
{
    while (it->p && it->p < it->end) {
        const char *line = it->p;
        const char *lf = memchr(line, '\n', (size_t)(it->end - line));
        const char *line_end = lf ? lf : it->end;
        it->p = lf ? lf + 1 : it->end;
        if (line_end > line && line_end[-1] == '\r') {
            line_end--;
        }
        if (line_end == line) {
            it->p = it->end; // empty line: end of the head
            return false;
        }
        const char *colon = memchr(line, ':', (size_t)(line_end - line));
        if (!colon) {
            continue;
        }
        *name = dd_http_trim(line, colon);
        *value = dd_http_trim(colon + 1, line_end);
        return true;
    }
    return false;
}

struct dd_http_slice dd_http_trim(const char *p, const char *end)
{
    while (p < end && (*p == ' ' || *p == '\t')) { p++; }
    while (end > p && (end[-1] == ' ' || end[-1] == '\t')) { end--; }
    return (struct dd_http_slice){p, (size_t)(end - p)};
}
