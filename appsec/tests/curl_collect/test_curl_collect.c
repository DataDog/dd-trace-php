// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// Unit tests of appsec/src/extension/downstream/curl_collect.c, without PHP.
// The event sequences are those measured on libcurl 7.74 to 8.18 (see the
// design report, section 5: with CURLOPT_HEADER the write function sees each
// head line before the header function does). Every input is passed in a heap
// copy of its exact size, so that under AddressSanitizer a read past the data
// fails.

#include "../../src/extension/downstream/curl_collect.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static int failures;
static const char *current_test;

#define CHECK(cond)                                                            \
    do {                                                                       \
        if (!(cond)) {                                                         \
            fprintf(stderr, "%s:%d [%s] check failed: %s\n", __FILE__,         \
                __LINE__, current_test, #cond);                                \
            failures++;                                                        \
        }                                                                      \
    } while (0)

#define CHECK_MEM(ptr, len, lit)                                               \
    do {                                                                       \
        size_t _l = sizeof(lit) - 1;                                           \
        if ((len) != _l || ((len) > 0 && memcmp((ptr), (lit), _l) != 0)) {     \
            fprintf(stderr,                                                    \
                "%s:%d [%s] expected \"%s\", got \"%.*s\" (len %zu)\n",        \
                __FILE__, __LINE__, current_test, lit, (int)(len),             \
                (ptr) ? (const char *)(ptr) : "", (size_t)(len));              \
            failures++;                                                        \
        }                                                                      \
    } while (0)

#define CHECK_HEAD(c, lit) CHECK_MEM((c)->head.ptr, (c)->head.len, lit)
#define BODY_LEN(x) ((x)->c.body.buf.len)
#define CHECK_BODY(x, lit)                                                     \
    CHECK_MEM((x)->c.body.buf.ptr, (x)->c.body.buf.len, lit)

// An allocator in the way of the extension's _coll_realloc: it may refuse
// (return NULL). It records the largest size ever requested and the bytes
// live, so that tests can check the body cap and the release of buffers.
static struct {
    size_t live;      // bytes currently allocated
    size_t max_req;   // largest size requested
    unsigned calls;   // calls to realloc
    long refuse_from; // refuse the call with this number (1-based) and all
                      // later ones; -1: never
} alloc_state;

struct alloc_hdr {
    size_t size;
};

static void *test_realloc(void *ptr, size_t size)
{
    alloc_state.calls++;
    if (size > alloc_state.max_req) {
        alloc_state.max_req = size;
    }
    if (alloc_state.refuse_from >= 0 &&
        (long)alloc_state.calls >= alloc_state.refuse_from) {
        return NULL;
    }
    struct alloc_hdr *old = ptr ? (struct alloc_hdr *)ptr - 1 : NULL;
    struct alloc_hdr *n = realloc(old, sizeof(*n) + size);
    if (!n) {
        return NULL;
    }
    alloc_state.live += size - (old ? n->size : 0);
    n->size = size;
    return n + 1;
}

static void test_free(void *ptr)
{
    if (!ptr) {
        return;
    }
    struct alloc_hdr *h = (struct alloc_hdr *)ptr - 1;
    alloc_state.live -= h->size;
    free(h);
}

static const struct dd_curl_alloc test_alloc = {test_realloc, test_free};

enum { DEFAULT_BODY_CAP = 1 << 20 };

// the transfer under test
struct xfer {
    struct dd_curl_collect c;
};

static void x_init_cap(struct xfer *x, size_t body_cap)
{
    memset(&alloc_state, 0, sizeof(alloc_state));
    alloc_state.refuse_from = -1;
    memset(x, 0, sizeof(*x));
    dd_curl_collect_init(&x->c, &test_alloc, body_cap);
    x->c.sampled = true;
}

static void x_init(struct xfer *x) { x_init_cap(x, DEFAULT_BODY_CAP); }

static void x_free(struct xfer *x)
{
    dd_curl_collect_destroy(&x->c);
    CHECK(alloc_state.live == 0); // nothing leaks through our allocator
}

static void P(struct xfer *x) { dd_curl_collect_prereq(&x->c); }

static void H(struct xfer *x, const char *line)
{
    size_t len = strlen(line);
    char *copy = malloc(len ? len : 1);
    memcpy(copy, line, len);
    dd_curl_collect_header_line(&x->c, copy, len);
    free(copy);
}

static void W_n(struct xfer *x, const char *data, size_t len)
{
    char *copy = malloc(len ? len : 1);
    memcpy(copy, data, len);
    dd_curl_collect_write(&x->c, copy, len);
    free(copy);
}

static void W(struct xfer *x, const char *data) { W_n(x, data, strlen(data)); }

// a line as libcurl delivers it with CURLOPT_HEADER: the write function
// first, then the header function
static void WH(struct xfer *x, const char *line)
{
    W(x, line);
    H(x, line);
}

// A head of lines in `lines` (each ending in LF), on the header function only
// (HEADER 0) or on both (HEADER 1)
static void head(struct xfer *x, const char *lines, bool header_opt)
{
    while (*lines) {
        const char *lf = strchr(lines, '\n');
        size_t n = (size_t)(lf - lines) + 1;
        char *line = malloc(n + 1);
        memcpy(line, lines, n);
        line[n] = '\0';
        if (header_opt) {
            WH(x, line);
        } else {
            H(x, line);
        }
        free(line);
        lines += n;
    }
}

#define OK200 "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n"

static void test_header0_plain(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, OK200, false);
    CHECK(x.c.phase == DD_CURL_PHASE_BODY);
    CHECK(x.c.head_complete);
    CHECK(x.c.status == 200);
    CHECK(x.c.hops == 1);
    W(&x, "hello");
    CHECK_HEAD(&x.c, OK200);
    CHECK_BODY(&x, "hello");
    x_free(&x);
}

static void test_header1_write_before_header(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, OK200, true);
    CHECK(BODY_LEN(&x) == 0); // head bytes never reach the sink
    W(&x, "hel");
    W(&x, "lo");
    CHECK_HEAD(&x.c, OK200);
    CHECK_BODY(&x, "hello");
    x_free(&x);
}

static void test_header1_blank_line_last(void)
{
    // the write of the blank line is still in the head phase
    struct xfer x;
    x_init(&x);
    P(&x);
    WH(&x, "HTTP/1.1 200 OK\r\n");
    W(&x, "\r\n");
    CHECK(x.c.phase == DD_CURL_PHASE_HEAD);
    CHECK(BODY_LEN(&x) == 0);
    H(&x, "\r\n");
    CHECK(x.c.phase == DD_CURL_PHASE_BODY);
    x_free(&x);
}

static void test_writes_in_one_byte_calls(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    const char *h = OK200;
    for (size_t i = 0; h[i]; i++) { W_n(&x, h + i, 1); }
    head(&x, OK200, false);
    const char *b = "hello";
    for (size_t i = 0; b[i]; i++) { W_n(&x, b + i, 1); }
    CHECK_BODY(&x, "hello");
    x_free(&x);
}

static void test_huge_header_line_in_16k_chunks(void)
{
    // libcurl passes a 40009-byte line to the write function in chunks of
    // 16384 and to the header function whole, after the chunks
    enum { LINE = 40009, CHUNK = 16384 };
    char *line = malloc(LINE + 1);
    memcpy(line, "X-Big: ", 7);
    memset(line + 7, 'a', LINE - 7 - 2);
    memcpy(line + LINE - 2, "\r\n", 2);
    line[LINE] = '\0';

    struct xfer x;
    x_init(&x);
    P(&x);
    WH(&x, "HTTP/1.1 200 OK\r\n");
    for (size_t off = 0; off < LINE; off += CHUNK) {
        size_t n = LINE - off < CHUNK ? LINE - off : CHUNK;
        W_n(&x, line + off, n);
    }
    H(&x, line);
    WH(&x, "\r\n");
    CHECK(BODY_LEN(&x) == 0);
    CHECK(x.c.head_complete);
    CHECK(!x.c.head_truncated);
    CHECK(x.c.head.len == strlen("HTTP/1.1 200 OK\r\n") + LINE + 2);
    W(&x, "hello");
    CHECK_BODY(&x, "hello");
    free(line);
    x_free(&x);
}

static void test_connect_before_hop0(void)
{
    // the CONNECT response head precedes hop 0's PREREQ, on both functions
    for (int header_opt = 0; header_opt < 2; header_opt++) {
        struct xfer x;
        x_init(&x);
        head(&x,
            "HTTP/1.1 200 Connection established\r\n"
            "Proxy-Agent: x\r\n\r\n",
            header_opt);
        CHECK(x.c.hops == 0);
        CHECK(x.c.head.len == 0);
        CHECK(x.c.phase == DD_CURL_PHASE_HEAD);
        CHECK(BODY_LEN(&x) == 0);
        P(&x);
        head(&x, OK200, header_opt);
        W(&x, "hello");
        CHECK_HEAD(&x.c, OK200);
        CHECK_BODY(&x, "hello");
        CHECK(x.c.hops == 1);
        x_free(&x);
    }
}

static void test_connect_before_hop_n(void)
{
    // hop 0's final head is a 302; the redirect needs a new tunnel: its head
    // arrives after the 302's and before hop 1's PREREQ (Hx3 P Hx8 P Hx4)
    for (int header_opt = 0; header_opt < 2; header_opt++) {
        struct xfer x;
        x_init(&x);
        P(&x);
        head(&x, "HTTP/1.1 302 Found\r\nLocation: /b\r\n\r\n", header_opt);
        head(&x, "HTTP/1.1 200 Connection established\r\n\r\n", header_opt);
        // the tunnel head did not replace the 302's
        CHECK_HEAD(&x.c, "HTTP/1.1 302 Found\r\nLocation: /b\r\n\r\n");
        CHECK(x.c.status == 302);
        CHECK(x.c.head_complete);
        // with CURLOPT_HEADER the write function sees the tunnel head in the
        // body phase of hop 0, so it reaches the sink: it belongs to hop 0's
        // body buffer, which is dropped at the next PREREQ (G5.7)
        if (header_opt) {
            CHECK_BODY(&x, "HTTP/1.1 200 Connection established\r\n\r\n");
        } else {
            CHECK(BODY_LEN(&x) == 0);
        }
        P(&x);
        CHECK(x.c.hops == 2);
        CHECK(x.c.head.len == 0);
        CHECK(!x.c.head_complete);
        CHECK(x.c.phase == DD_CURL_PHASE_HEAD);
        head(&x, OK200, header_opt);
        W(&x, "hello");
        CHECK_HEAD(&x.c, OK200);
        CHECK(x.c.status == 200);
        x_free(&x);
    }
}

static void test_suppress_connect_headers(void)
{
    // no tunnel head at all: PREREQ and the head, as without a proxy
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, OK200, true);
    W(&x, "hello");
    CHECK_HEAD(&x.c, OK200);
    CHECK_BODY(&x, "hello");
    x_free(&x);
}

static void test_continue_100(void)
{
    for (int header_opt = 0; header_opt < 2; header_opt++) {
        struct xfer x;
        x_init(&x);
        P(&x);
        head(&x, "HTTP/1.1 100 Continue\r\n\r\n", header_opt);
        CHECK(x.c.phase == DD_CURL_PHASE_HEAD);
        CHECK(x.c.head.len == 0);
        CHECK(x.c.interim == 1);
        CHECK(x.c.hops == 1); // no hop of its own
        head(&x, OK200, header_opt);
        W(&x, "hello");
        CHECK_HEAD(&x.c, OK200);
        CHECK_BODY(&x, "hello");
        CHECK(x.c.interim == 1);
        CHECK(x.c.hops == 1);
        x_free(&x);
    }
}

static void test_early_hints_103(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, "HTTP/1.1 103 Early Hints\r\nLink: </a.css>; rel=preload\r\n\r\n",
        true);
    CHECK(x.c.head.len == 0);
    CHECK(x.c.phase == DD_CURL_PHASE_HEAD);
    head(&x, OK200, true);
    W(&x, "hello");
    CHECK_HEAD(&x.c, OK200);
    CHECK_BODY(&x, "hello");
    CHECK(x.c.interim == 1);
    x_free(&x);
}

static void test_two_interim_heads(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, "HTTP/1.1 103 Early Hints\r\nLink: x\r\n\r\n", false);
    head(&x, "HTTP/1.1 100 Continue\r\n\r\n", false);
    head(&x, OK200, false);
    CHECK(x.c.interim == 2);
    CHECK_HEAD(&x.c, OK200);
    x_free(&x);
}

static void test_trailers_header0(void)
{
    // chunked response with a trailer; the body arrives de-chunked; the
    // trailer reaches only the header function, which ignores it
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n", false);
    W(&x, "hello");
    H(&x, "X-Trailer: 1\r\n");
    H(&x, "\r\n");
    CHECK_HEAD(&x.c, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n");
    CHECK_BODY(&x, "hello");
    CHECK(x.c.phase == DD_CURL_PHASE_BODY);
    x_free(&x);
}

static void test_trailers_header1_8_5(void)
{
    // libcurl 8.5+ with CURLOPT_HEADER passes each trailer line to the write
    // function too (write first, no blank line): 8 bytes end the body copy.
    // This exception to G5.9 is accepted, so the test records it.
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n", true);
    W(&x, "hello");
    WH(&x, "X-T: 1\r\n"); // 8 bytes
    CHECK_HEAD(&x.c, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n");
    CHECK_BODY(&x, "helloX-T: 1\r\n");
    H(&x, "\r\n");
    CHECK(x.c.head_complete);
    x_free(&x);
}

static void test_lines_after_complete_head_ignored(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, OK200, false);
    H(&x, "HTTP/1.1 500 Nope\r\n");
    H(&x, "X-Late: 1\r\n");
    H(&x, "\r\n");
    CHECK_HEAD(&x.c, OK200);
    CHECK(x.c.status == 200);
    CHECK(x.c.phase == DD_CURL_PHASE_BODY);
    x_free(&x);
}

static void test_redirect_chain(void)
{
    // 302, 301, 200; HEADER 1: each head's writes precede its lines, and a
    // redirect has no body write
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, "HTTP/1.1 302 Found\r\nLocation: /b\r\n\r\n", true);
    CHECK(x.c.status == 302);
    CHECK(x.c.phase == DD_CURL_PHASE_BODY);
    P(&x);
    CHECK(x.c.hops == 2);
    CHECK(x.c.phase == DD_CURL_PHASE_HEAD);
    CHECK(x.c.head.len == 0);
    head(&x, "HTTP/1.1 301 Moved\r\nLocation: /c\r\n\r\n", true);
    CHECK(x.c.status == 301);
    CHECK_HEAD(&x.c, "HTTP/1.1 301 Moved\r\nLocation: /c\r\n\r\n");
    P(&x);
    CHECK(x.c.hops == 3);
    head(&x, OK200, true);
    W(&x, "hello");
    CHECK_HEAD(&x.c, OK200);
    CHECK(x.c.status == 200);
    CHECK_BODY(&x, "hello");
    CHECK(!x.c.hops_truncated);
    x_free(&x);
}

static void test_writes_in_head_phase_dropped_after_prereq(void)
{
    // body bytes of hop 0 (a redirect with a body is not written, but a
    // buggy sequence must still not leak into hop 1's head phase)
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, "HTTP/1.1 302 Found\r\n\r\n", false);
    P(&x);
    W(&x, "stray");
    CHECK(BODY_LEN(&x) == 0);
    x_free(&x);
}

static void test_writes_before_hop0_dropped(void)
{
    struct xfer x;
    x_init(&x);
    W(&x, "tunnel");
    CHECK(BODY_LEN(&x) == 0);
    x_free(&x);
}

static void test_head_cap(void)
{
    struct xfer x;
    x_init(&x);
    x.c.head_cap = 40;
    P(&x);
    H(&x, "HTTP/1.1 200 OK\r\n");
    H(&x, "X-A: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\r\n");
    H(&x, "X-B: b\r\n");
    CHECK(x.c.head_truncated);
    CHECK(x.c.head.len <= 40);
    CHECK(!x.c.head_complete);
    CHECK(x.c.status == 200);
    H(&x, "\r\n"); // still complete at its blank line
    CHECK(x.c.head_complete);
    CHECK(x.c.phase == DD_CURL_PHASE_BODY);
    W(&x, "hello");
    CHECK_BODY(&x, "hello");
    x_free(&x);
}

static void test_default_head_cap(void)
{
    struct xfer x;
    x_init(&x);
    CHECK(x.c.head_cap == (size_t)256 * 1024);
    CHECK(DD_HTTP_HEAD_CAP == (size_t)256 * 1024);
    x_free(&x);
}

static void test_head_cap_boundary(void)
{
    // "HTTP/1.1 200 OK\r\n" (17) + "A: b\r\n" (6) + "\r\n" (2) = 25
    const size_t full = 25;
    for (size_t cap = full - 1; cap <= full + 1; cap++) {
        struct xfer x;
        x_init(&x);
        x.c.head_cap = cap;
        P(&x);
        head(&x, "HTTP/1.1 200 OK\r\nA: b\r\n\r\n", false);
        CHECK(x.c.head_complete);
        CHECK(x.c.phase == DD_CURL_PHASE_BODY);
        if (cap >= full) {
            CHECK(!x.c.head_truncated);
            CHECK_HEAD(&x.c, "HTTP/1.1 200 OK\r\nA: b\r\n\r\n");
        } else {
            // the blank line does not fit: dropped whole, head truncated
            CHECK(x.c.head_truncated);
            CHECK_HEAD(&x.c, "HTTP/1.1 200 OK\r\nA: b\r\n");
        }
        x_free(&x);
    }
    // a line one byte over what is left is dropped whole
    struct xfer x;
    x_init(&x);
    x.c.head_cap = 17 + 5;
    P(&x);
    H(&x, "HTTP/1.1 200 OK\r\n");
    H(&x, "A: bc\r\n"); // 7 bytes, 5 left
    CHECK(x.c.head_truncated);
    CHECK_HEAD(&x.c, "HTTP/1.1 200 OK\r\n");
    H(&x, "B:\r\n"); // would fit, but nothing is appended after truncation
    CHECK_HEAD(&x.c, "HTTP/1.1 200 OK\r\n");
    x_free(&x);
}

static void test_head_cap_below_status_line(void)
{
    // the status line alone exceeds the cap: the head is empty and truncated,
    // yet the status is parsed and the head completes at its blank line
    struct xfer x;
    x_init(&x);
    x.c.head_cap = 10;
    P(&x);
    H(&x, "HTTP/1.1 200 OK\r\n");
    CHECK(x.c.head.len == 0);
    CHECK(x.c.head_truncated);
    CHECK(x.c.status == 200);
    H(&x, "\r\n");
    CHECK(x.c.head_complete);
    CHECK(x.c.phase == DD_CURL_PHASE_BODY);
    CHECK(x.c.head.len == 0);
    W(&x, "hello");
    CHECK_BODY(&x, "hello");
    x_free(&x);

    // an interim head whose status line does not fit is still discarded
    x_init(&x);
    x.c.head_cap = 10;
    P(&x);
    head(&x, "HTTP/1.1 100 Continue\r\n\r\n", false);
    CHECK(x.c.phase == DD_CURL_PHASE_HEAD);
    CHECK(!x.c.head_complete);
    CHECK(x.c.status == 0);
    x_free(&x);
}

static void test_no_status_line(void)
{
    // not HTTP: the head still ends at its blank line
    struct xfer x;
    x_init(&x);
    P(&x);
    H(&x, "garbage\r\n");
    H(&x, "\r\n");
    CHECK(x.c.head_complete);
    CHECK(x.c.status == 0);
    CHECK(x.c.phase == DD_CURL_PHASE_BODY);
    x_free(&x);
}

static void test_lf_only_lines(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    H(&x, "HTTP/1.1 200 OK\n");
    H(&x, "A: b\n");
    H(&x, "\n");
    CHECK(x.c.head_complete);
    CHECK(x.c.status == 200);
    CHECK_HEAD(&x.c, "HTTP/1.1 200 OK\nA: b\n\n");
    x_free(&x);
}

static void test_http2_status_line(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    H(&x, "HTTP/2 200 \r\n");
    H(&x, "\r\n");
    CHECK(x.c.status == 200);
    CHECK(x.c.phase == DD_CURL_PHASE_BODY);
    x_free(&x);
}

static void test_hop_cap(void)
{
    struct xfer x;
    x_init(&x);
    for (int i = 0; i < DD_HTTP_MAX_HOPS; i++) {
        P(&x);
        head(&x, "HTTP/1.1 302 Found\r\n\r\n", false);
    }
    CHECK(!x.c.hops_truncated);
    CHECK(!x.c.stopped);
    P(&x);
    CHECK(x.c.hops_truncated);
    CHECK(x.c.stopped);
    head(&x, OK200, false);
    W(&x, "hello");
    CHECK(BODY_LEN(&x) == 0);
    CHECK(x.c.hops == DD_HTTP_MAX_HOPS);
    x_free(&x);
}

static void test_zero_length_events(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    H(&x, "");
    W_n(&x, "", 0);
    head(&x, OK200, false);
    W_n(&x, "", 0);
    CHECK(BODY_LEN(&x) == 0);
    CHECK(x.c.head_complete);
    x_free(&x);
}

// --- the body sink (G5.7, G5.8, G5.18, G9.5) ---

static void to_body(struct xfer *x)
{
    P(x);
    head(x, OK200, false);
    alloc_state.max_req = 0; // from here on: the requests of the body
}

static void test_body_cap_never_exceeded(void)
{
    struct xfer x;
    x_init_cap(&x, 10);
    to_body(&x);
    W(&x, "aaaaaaa");
    CHECK(!x.c.body.truncated);
    W(&x, "bbbbbbb");
    CHECK_BODY(&x, "aaaaaaabbb");
    CHECK(x.c.body.truncated);
    W(&x, "ccc");
    CHECK_BODY(&x, "aaaaaaabbb");
    CHECK(alloc_state.max_req <= 10);
    // truncation does not stop the collector: the transfer goes on
    CHECK(!x.c.stopped);
    CHECK(!x.c.oom);
    x_free(&x);
}

static void test_body_cap_boundary(void)
{
    struct xfer x;
    x_init_cap(&x, 5);
    to_body(&x);
    W(&x, "hello");
    CHECK_BODY(&x, "hello");
    CHECK(!x.c.body.truncated); // exactly the cap fits
    W(&x, "!");
    CHECK_BODY(&x, "hello");
    CHECK(x.c.body.truncated);
    CHECK(alloc_state.max_req <= 5);
    x_free(&x);
}

static void test_body_cap_one_huge_chunk(void)
{
    enum { HUGE_LEN = 3 * 1024 * 1024 };
    char *big = malloc(HUGE_LEN);
    memset(big, 'z', HUGE_LEN);
    struct xfer x;
    x_init_cap(&x, 1000);
    to_body(&x);
    dd_curl_collect_write(&x.c, big, HUGE_LEN);
    CHECK(BODY_LEN(&x) == 1000);
    CHECK(x.c.body.truncated);
    CHECK(alloc_state.max_req <= 1000);
    free(big);
    x_free(&x);
}

static void test_body_cap_one_byte_chunks(void)
{
    struct xfer x;
    x_init_cap(&x, 7);
    to_body(&x);
    for (int i = 0; i < 100; i++) { W_n(&x, "x", 1); }
    CHECK(BODY_LEN(&x) == 7);
    CHECK(x.c.body.truncated);
    CHECK(alloc_state.max_req <= 7);
    x_free(&x);
}

static void test_body_cap_zero(void)
{
    // a cap of zero copies nothing and allocates nothing
    struct xfer x;
    x_init_cap(&x, 0);
    to_body(&x);
    W(&x, "hello");
    CHECK(BODY_LEN(&x) == 0);
    CHECK(x.c.body.truncated);
    CHECK(alloc_state.max_req == 0);
    x_free(&x);
}

static void test_body_refusal_stops_collection(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, OK200, false);
    W(&x, "hello");
    CHECK_BODY(&x, "hello");
    // the next growth is refused
    alloc_state.refuse_from = (long)alloc_state.calls + 1;
    char *big = malloc(100000);
    memset(big, 'a', 100000);
    dd_curl_collect_write(&x.c, big, 100000);
    free(big);
    CHECK(x.c.oom);
    CHECK(x.c.stopped);
    CHECK(BODY_LEN(&x) == 0); // what was copied is released
    CHECK(alloc_state.live == 0);
    // everything after the refusal is ignored; the transfer is untouched
    // (the events still return and do nothing)
    unsigned calls = alloc_state.calls;
    W(&x, "more");
    H(&x, "X: y\r\n");
    P(&x);
    head(&x, OK200, false);
    dd_curl_collect_end(&x.c, true);
    CHECK(alloc_state.calls == calls);
    CHECK(BODY_LEN(&x) == 0);
    CHECK(!x.c.body.ended);
    x_free(&x);
}

// our PREREQ was replaced by the app's: the collector stops without oom and
// releases what it holds; later events do nothing
static void test_stop(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, OK200, false);
    W(&x, "hello");
    dd_curl_collect_stop(&x.c);
    CHECK(x.c.stopped);
    CHECK(!x.c.oom);
    CHECK(x.c.head.len == 0);
    CHECK(BODY_LEN(&x) == 0);
    CHECK(alloc_state.live == 0);
    unsigned calls = alloc_state.calls;
    W(&x, "more");
    P(&x);
    head(&x, OK200, false);
    dd_curl_collect_end(&x.c, true);
    CHECK(alloc_state.calls == calls);
    CHECK(x.c.hops == 1);
    CHECK(!x.c.body.ended);
    x_free(&x);
}

static void test_body_first_allocation_refused(void)
{
    struct xfer x;
    x_init(&x);
    to_body(&x);
    alloc_state.refuse_from = (long)alloc_state.calls + 1;
    W(&x, "hello");
    CHECK(x.c.oom);
    CHECK(x.c.stopped);
    CHECK(BODY_LEN(&x) == 0);
    x_free(&x);
}

static void test_head_growth_refused(void)
{
    // the head grows through the same allocator (G9.5)
    struct xfer x;
    x_init(&x);
    P(&x);
    alloc_state.refuse_from = 1;
    H(&x, "HTTP/1.1 200 OK\r\n");
    CHECK(x.c.oom);
    CHECK(x.c.stopped);
    H(&x, "\r\n");
    W(&x, "hello");
    CHECK(BODY_LEN(&x) == 0);
    CHECK(alloc_state.calls == 1);
    x_free(&x);
}

static void test_unsampled_copies_nothing(void)
{
    struct xfer x;
    x_init(&x);
    x.c.sampled = false;
    P(&x);
    head(&x, OK200, false);
    unsigned calls = alloc_state.calls;
    size_t live = alloc_state.live;
    W(&x, "hello");
    W(&x, "world");
    CHECK(alloc_state.calls == calls);
    CHECK(alloc_state.live == live);
    CHECK(BODY_LEN(&x) == 0);
    CHECK(!x.c.body.truncated);
    CHECK(!x.c.oom);
    CHECK(x.c.head_complete); // heads are still followed
    x_free(&x);
}

static void test_previous_hop_body_dropped_at_prereq(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    head(&x, "HTTP/1.1 302 Found\r\nLocation: /b\r\n\r\n", false);
    W(&x, "redirect body");
    CHECK_BODY(&x, "redirect body");
    size_t live = alloc_state.live;
    CHECK(live > 0);
    P(&x); // hop 1 starts: libcurl followed the redirect
    CHECK(BODY_LEN(&x) == 0);
    CHECK(alloc_state.live < live); // the buffer is released, not kept
    head(&x, OK200, false);
    W(&x, "final");
    CHECK_BODY(&x, "final");
    CHECK(!x.c.body.truncated);
    x_free(&x);
}

static void test_prereq_resets_body_flags(void)
{
    struct xfer x;
    x_init_cap(&x, 4);
    to_body(&x);
    W(&x, "toolong");
    dd_curl_collect_end(&x.c, true);
    CHECK(x.c.body.truncated);
    CHECK(x.c.body.ended);
    P(&x);
    CHECK(!x.c.body.truncated);
    CHECK(!x.c.body.ended);
    CHECK(!x.c.body.framing_complete);
    x_free(&x);
}

static void test_end_event_and_framing_flag(void)
{
    struct xfer x;
    x_init(&x);
    to_body(&x);
    W(&x, "hello");
    CHECK(!x.c.body.ended);
    dd_curl_collect_end(&x.c, true);
    CHECK(x.c.body.ended);
    CHECK(x.c.body.framing_complete);
    x_free(&x);

    x_init(&x);
    to_body(&x);
    W(&x, "hel");
    dd_curl_collect_end(&x.c, false); // e.g. the transfer was aborted
    CHECK(x.c.body.ended);
    CHECK(!x.c.body.framing_complete);
    CHECK_BODY(&x, "hel");
    x_free(&x);
}

static void test_end_only_in_body_phase(void)
{
    // an end call while no head completed (no response received) is not a
    // complete empty body
    struct xfer x;
    x_init(&x);
    dd_curl_collect_end(&x.c, true); // before hop 0
    CHECK(!x.c.body.ended);
    P(&x);
    H(&x, "HTTP/1.1 200 OK\r\n"); // head incomplete
    dd_curl_collect_end(&x.c, true);
    CHECK(!x.c.body.ended);
    CHECK(!x.c.body.framing_complete);
    H(&x, "\r\n");
    dd_curl_collect_end(&x.c, true);
    CHECK(x.c.body.ended);
    CHECK(x.c.body.framing_complete);
    x_free(&x);

    // after a stop: no end event either
    x_init(&x);
    P(&x);
    head(&x, OK200, false);
    alloc_state.refuse_from = (long)alloc_state.calls + 1;
    W(&x, "hello");
    CHECK(x.c.stopped);
    dd_curl_collect_end(&x.c, true);
    CHECK(!x.c.body.ended);
    CHECK(!x.c.body.framing_complete);
    x_free(&x);
}

static void test_blank_line_refused_leaves_no_complete_head(void)
{
    struct xfer x;
    x_init(&x);
    P(&x);
    H(&x, "HTTP/1.1 200 OK\r\n");
    char line[256];
    memset(line, 'a', sizeof(line));
    memcpy(line, "X: ", 3);
    // the head fills the initial block but for one byte: the blank line
    // needs a larger block
    size_t n = 256 - 17 - 1;
    line[n - 2] = '\r';
    line[n - 1] = '\n';
    dd_curl_collect_header_line(&x.c, line, n);
    CHECK(!x.c.stopped);
    alloc_state.refuse_from = (long)alloc_state.calls + 1;
    H(&x, "\r\n");
    CHECK(x.c.oom);
    CHECK(x.c.stopped);
    CHECK(!x.c.head_complete);
    CHECK(x.c.phase == DD_CURL_PHASE_HEAD);
    x_free(&x);
}

// a sink of another kind: records the chunks as they come
struct rec_sink {
    struct dd_body_sink sink;
    char data[64];
    size_t len;
    unsigned chunks;
    int ends;
    bool framing;
};

static bool rec_chunk(struct dd_body_sink *s, const char *d, size_t n)
{
    struct rec_sink *r = (struct rec_sink *)s;
    memcpy(r->data + r->len, d, n);
    r->len += n;
    r->chunks++;
    return true;
}

static void rec_end(struct dd_body_sink *s, bool framing_complete)
{
    struct rec_sink *r = (struct rec_sink *)s;
    r->ends++;
    r->framing = framing_complete;
}

static void test_sink_is_replaceable(void)
{
    struct xfer x;
    x_init(&x);
    struct rec_sink r = {{rec_chunk, rec_end}, {0}, 0, 0, 0, false};
    x.c.sink = &r.sink;
    to_body(&x);
    W(&x, "hel");
    W(&x, "lo");
    dd_curl_collect_end(&x.c, true);
    CHECK_MEM(r.data, r.len, "hello");
    CHECK(r.chunks == 2); // delivered once per call, unchanged
    CHECK(r.ends == 1);
    CHECK(r.framing);
    CHECK(BODY_LEN(&x) == 0); // the standard sink was not used
    x_free(&x);
}

static bool refuse_chunk(struct dd_body_sink *s, const char *d, size_t n)
{
    (void)s;
    (void)d;
    (void)n;
    return false;
}

static void test_sink_refusal_stops_collector(void)
{
    struct xfer x;
    x_init(&x);
    struct rec_sink r = {{refuse_chunk, rec_end}, {0}, 0, 0, 0, false};
    x.c.sink = &r.sink;
    to_body(&x);
    W(&x, "hello");
    CHECK(x.c.oom);
    CHECK(x.c.stopped);
    dd_curl_collect_end(&x.c, true);
    CHECK(r.ends == 0);
    x_free(&x);
}

static void test_chunks_split_anywhere(void)
{
    // the body arrives in two or three pieces cut at every position: the
    // result is the same, also when a piece is empty
    const char *body = "0123456789abcdef";
    size_t n = strlen(body);
    for (size_t i = 0; i <= n; i++) {
        for (size_t j = i; j <= n; j++) {
            struct xfer x;
            x_init(&x);
            to_body(&x);
            W_n(&x, body, i);
            W_n(&x, body + i, j - i);
            W_n(&x, body + j, n - j);
            CHECK_BODY(&x, "0123456789abcdef");
            CHECK(!x.c.body.truncated);
            x_free(&x);
        }
    }
    // and across the cap
    for (size_t cap = 0; cap <= n; cap++) {
        for (size_t i = 0; i <= n; i++) {
            struct xfer x;
            x_init_cap(&x, cap);
            to_body(&x);
            W_n(&x, body, i);
            W_n(&x, body + i, n - i);
            CHECK(BODY_LEN(&x) == cap);
            CHECK(
                BODY_LEN(&x) == 0 || memcmp(x.c.body.buf.ptr, body, cap) == 0);
            CHECK(x.c.body.truncated == (cap < n));
            CHECK(alloc_state.max_req <= cap);
            x_free(&x);
        }
    }
}

static void test_standalone_body_buf(void)
{
    // the standard sink outside a collector (the read-function gate's
    // request body): capped, through its own allocator, released whole
    memset(&alloc_state, 0, sizeof(alloc_state));
    alloc_state.refuse_from = -1;
    struct dd_body_buf b;
    dd_body_buf_init(&b, &test_alloc, 10);
    CHECK(b.sink.chunk(&b.sink, "01234", 5));
    CHECK(b.sink.chunk(&b.sink, "56789abc", 8));
    CHECK_MEM(b.buf.ptr, b.buf.len, "0123456789");
    CHECK(b.truncated);
    CHECK(alloc_state.max_req <= 10);
    b.sink.end(&b.sink, true);
    CHECK(b.ended && b.framing_complete);
    dd_body_buf_release(&b);
    CHECK(alloc_state.live == 0);
    CHECK(b.buf.len == 0 && !b.truncated && !b.ended && b.cap == 10);
    // usable again after a release
    CHECK(b.sink.chunk(&b.sink, "xy", 2));
    CHECK_MEM(b.buf.ptr, b.buf.len, "xy");
    dd_body_buf_release(&b);
    CHECK(alloc_state.live == 0);
}

static void test_helpers(void)
{
    int status = 0;
    CHECK(dd_http_parse_status_line("HTTP/1.1 204 No Content", 23, &status));
    CHECK(status == 204);
    CHECK(!dd_http_parse_status_line("HTTP/1.1 99", 11, &status));
    struct dd_http_header_iter it;
    struct dd_http_slice n, v;
    const char *h =
        "HTTP/1.1 200 OK\r\nA:  b \r\nnocolon\r\nC:d\r\n\r\nE:f\r\n";
    dd_http_header_iter_init(&it, h, strlen(h));
    CHECK(dd_http_header_iter_next(&it, &n, &v));
    CHECK_MEM(n.ptr, n.len, "A");
    CHECK_MEM(v.ptr, v.len, "b");
    CHECK(dd_http_header_iter_next(&it, &n, &v));
    CHECK_MEM(n.ptr, n.len, "C");
    CHECK(!dd_http_header_iter_next(&it, &n, &v));
}

#define RUN(t)                                                                 \
    do {                                                                       \
        current_test = #t;                                                     \
        t();                                                                   \
    } while (0)

int main(void)
{
    RUN(test_header0_plain);
    RUN(test_header1_write_before_header);
    RUN(test_header1_blank_line_last);
    RUN(test_writes_in_one_byte_calls);
    RUN(test_huge_header_line_in_16k_chunks);
    RUN(test_connect_before_hop0);
    RUN(test_connect_before_hop_n);
    RUN(test_suppress_connect_headers);
    RUN(test_continue_100);
    RUN(test_early_hints_103);
    RUN(test_two_interim_heads);
    RUN(test_trailers_header0);
    RUN(test_trailers_header1_8_5);
    RUN(test_lines_after_complete_head_ignored);
    RUN(test_redirect_chain);
    RUN(test_writes_in_head_phase_dropped_after_prereq);
    RUN(test_writes_before_hop0_dropped);
    RUN(test_head_cap);
    RUN(test_default_head_cap);
    RUN(test_head_cap_boundary);
    RUN(test_head_cap_below_status_line);
    RUN(test_no_status_line);
    RUN(test_lf_only_lines);
    RUN(test_http2_status_line);
    RUN(test_hop_cap);
    RUN(test_zero_length_events);
    RUN(test_body_cap_never_exceeded);
    RUN(test_body_cap_boundary);
    RUN(test_body_cap_one_huge_chunk);
    RUN(test_body_cap_one_byte_chunks);
    RUN(test_body_cap_zero);
    RUN(test_body_refusal_stops_collection);
    RUN(test_stop);
    RUN(test_body_first_allocation_refused);
    RUN(test_head_growth_refused);
    RUN(test_unsampled_copies_nothing);
    RUN(test_previous_hop_body_dropped_at_prereq);
    RUN(test_prereq_resets_body_flags);
    RUN(test_end_event_and_framing_flag);
    RUN(test_end_only_in_body_phase);
    RUN(test_blank_line_refused_leaves_no_complete_head);
    RUN(test_sink_is_replaceable);
    RUN(test_sink_refusal_stops_collector);
    RUN(test_chunks_split_anywhere);
    RUN(test_standalone_body_buf);
    RUN(test_helpers);
    if (failures) {
        fprintf(stderr, "%d check(s) failed\n", failures);
        return 1;
    }
    printf("all curl_collect tests passed\n");
    return 0;
}
