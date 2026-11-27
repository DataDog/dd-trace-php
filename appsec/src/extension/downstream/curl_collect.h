// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// PHP-agnostic core of the collector of a libcurl transfer observed through
// the interposed header and write functions and the PREREQ callback (G5.6,
// G5.9, G5.11). It includes neither Zend nor libcurl headers, so it can be
// compiled into a standalone test program (appsec/tests/curl_collect/).
//
// Events: PREREQ (a hop starts), header line (one call per line, as the
// header function receives it), write bytes and end (the transfer is over).
// The collector holds the head of the current hop; the body is handed to a
// body sink as it arrives (G5.18).
//
// - A head is complete at its blank line. Header lines that follow a complete
//   head are ignored until the next PREREQ (trailers; the CONNECT head that
//   precedes a later hop).
// - Heads that arrive before hop 0's PREREQ are those of a CONNECT tunnel and
//   are dropped.
// - A 1xx head is discarded at its blank line and starts no hop.
// - The head is capped at head_cap. A line that does not fit is dropped whole
//   and head_truncated is set; the head still completes at its blank line
//   (which is dropped too if it does not fit) and its status is read from the
//   first line even if that line was dropped. A consumer of a hop that
//   received a response must handle head_complete with head.len == 0 or a
//   truncated head.
// - With CURLOPT_HEADER the write function sees the CONNECT head of a later
//   hop in the body phase of the previous hop, so its bytes reach the sink;
//   they belong to the previous hop's body buffer, which the consumer drops
//   at the next PREREQ (G5.7).
// - Body sink (G5.7, G5.8, G5.18): write bytes of the body phase go, once per
//   call and unchanged, to c->sink (chunks arrive split anywhere). The
//   standard sink is c->body, a capped buffer: it copies at most body.cap
//   bytes (the rest is not analysed and only sets truncated), and only if the
//   transfer is sampled (c->sampled, default false: an unsampled transfer
//   copies nothing). Only the last hop's body is kept: a PREREQ releases the
//   previous hop's body buffer.
// - All growth of head and body goes through the allocator given to init
//   (the extension's: dd_emalloc_fits, then erealloc), which may refuse. A
//   refusal sets oom and stops the collector: nothing more is collected and
//   the buffers are released; the transfer itself is untouched. The
//   collector runs no PHP code and no zend_try.
// - Phase rule: the head phase lasts from the start of the transfer and from
//   each PREREQ; write bytes in it are dropped (with CURLOPT_HEADER libcurl
//   calls the write function for each head line, always before the header
//   function). The body phase starts at the blank line of a non-1xx head.
// - Hops after DD_HTTP_MAX_HOPS are a sanity limit: the collector stops.

#include <stdbool.h>
#include <stddef.h>

#define DD_HTTP_HEAD_CAP ((size_t)256 * 1024)
#define DD_HTTP_MAX_HOPS 32

// The allocator of the collector's buffers. realloc_fn may return NULL (a
// refusal); it is never called with a size of zero. free_fn releases blocks of
// realloc_fn.
struct dd_curl_alloc {
    void *(*realloc_fn)(void *ptr, size_t size);
    void (*free_fn)(void *ptr);
};

struct dd_http_buf {
    char *ptr;
    size_t len;
    size_t cap;
};

struct dd_http_slice {
    const char *ptr;
    size_t len;
};

enum dd_curl_phase {
    DD_CURL_PHASE_HEAD, // write bytes are dropped
    DD_CURL_PHASE_BODY, // write bytes go to the sink
};

// The body sink interface: the evaluator reads a body only through a sink.
// After the collector stops (oom or a sink refusing a chunk) the sink gets no
// end event: a custom sink owns the release of what it holds; the standard
// sink's buffer is released by the collector.
struct dd_body_sink {
    // body bytes, split anywhere; false: the sink could not take them (the
    // collector stops)
    bool (*chunk)(struct dd_body_sink *sink, const char *data, size_t len);
    // no more bytes of this body; framing_complete: libcurl completed the
    // body's framing (not aborted or cut short)
    void (*end)(struct dd_body_sink *sink, bool framing_complete);
};

// The standard sink: one capped buffer. The body cap is a property of it and
// is held nowhere else.
struct dd_body_buf {
    struct dd_body_sink sink; // must be first
    const struct dd_curl_alloc *alloc;
    size_t cap;
    struct dd_http_buf buf; // at most cap bytes
    bool truncated;         // bytes past the cap were not copied
    bool ended;
    bool framing_complete;
};

// A standard sink outside a collector (the read-function gate's request body,
// G5.18), with its own allocator (NULL: libc); release drops what it holds
// (b stays usable, with the same cap)
void dd_body_buf_init(
    struct dd_body_buf *b, const struct dd_curl_alloc *alloc, size_t cap);
void dd_body_buf_release(struct dd_body_buf *b);

// Holds pointers into itself (sink, body.alloc): it must not be moved or copied
// after dd_curl_collect_init.
struct dd_curl_collect {
    const struct dd_curl_alloc *alloc;
    bool sampled; // the sampling decision of the transfer: copy bodies only
                  // if set
    struct dd_body_buf body;   // the standard sink
    struct dd_body_sink *sink; // where write bytes go (default: &body.sink)
    size_t head_cap;
    // head of the current hop: status line and header lines (each ending as
    // received), and the blank line when complete. Empty before hop 0.
    struct dd_http_buf head;
    bool started; // the first line of the current head was seen
    bool head_complete;
    bool head_truncated; // lines were dropped at the cap
    int status;          // of the status line of the head; 0 if none
    unsigned interim;    // 1xx heads discarded in the current hop
    unsigned hops;       // PREREQs seen: hop n is the one started by the
                         // (n+1)th
    enum dd_curl_phase phase;
    bool hops_truncated; // more than DD_HTTP_MAX_HOPS hops
    bool oom;            // an allocation failed
    bool stopped;        // events are ignored (hops_truncated or oom)
};

// alloc: NULL for libc realloc/free. body_cap: the cap of the standard sink.
void dd_curl_collect_init(struct dd_curl_collect *c,
    const struct dd_curl_alloc *alloc, size_t body_cap);
void dd_curl_collect_destroy(struct dd_curl_collect *c);

// The interposed PREREQ callback: a new hop starts. The previous hop's head
// is released: callers read it before this call.
void dd_curl_collect_prereq(struct dd_curl_collect *c);
// One line of the interposed header function (CRLF or LF included, as
// libcurl passes it; the blank line is a line too)
void dd_curl_collect_header_line(
    struct dd_curl_collect *c, const char *line, size_t len);
// Bytes of the interposed write function. In the body phase of a sampled
// transfer they are passed to the sink (once per call, unchanged); otherwise
// they are dropped.
void dd_curl_collect_write(
    struct dd_curl_collect *c, const char *data, size_t len);
// What comes next cannot be attributed to a hop (our PREREQ was replaced by
// the app's during the transfer): stop collecting and release what was
// collected, as on a refused allocation but without setting oom.
void dd_curl_collect_stop(struct dd_curl_collect *c);
// The transfer is over (or the hop's body is): the sink's end event. Only
// delivered in the body phase of a sampled, not stopped collector: with no
// complete head (nothing received) there is no body to end.
void dd_curl_collect_end(struct dd_curl_collect *c, bool framing_complete);

// --- parsing helpers (also used to present the collected heads) ---

// "HTTP/<version> SP 3DIGIT [SP reason]" (line without its CRLF); false if
// malformed
bool dd_http_parse_status_line(const char *line, size_t len, int *status);

// Iterates over the "name: value" lines of a head, after its start line.
// Names and values are trimmed of spaces and tabs; lines without a colon are
// skipped. Stops at the first empty line or the end of the buffer.
struct dd_http_header_iter {
    const char *p;
    const char *end;
};
void dd_http_header_iter_init(
    struct dd_http_header_iter *it, const char *head, size_t len);
bool dd_http_header_iter_next(struct dd_http_header_iter *it,
    struct dd_http_slice *name, struct dd_http_slice *value);

// the slice [p, end) without leading and trailing spaces and tabs
struct dd_http_slice dd_http_trim(const char *p, const char *end);
