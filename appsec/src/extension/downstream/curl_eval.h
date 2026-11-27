// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// WAF evaluation of the transfers observed by curl_observe.c (RFC-1062): the
// server.io.net.* addresses are sent with dd_request_exec, one WAF
// subcontext per hop.
//
// Transfers whose URL has the http or https scheme, as libcurl sees it (case
// insensitively, or guessed for a URL without one) are evaluated. A transfer
// whose URL is not known (never set, or not recorded exactly: unknown_option)
// is not: nothing is read back from libcurl for hop 0.
//
// Per hop, the calls are (each one only if it has data):
//  - hop 0, once, before libcurl runs (W1: the curl_exec pre-hook, or for a
//    multi handle the curl_multi_exec pre-hook of the first exec after
//    curl_multi_add_handle: libcurl reads the options then), entirely from
//    the recorded options:
//    URL, method (CURLOPT_CUSTOMREQUEST verbatim, else derived; omitted when
//    it is not sure), headers and cookie from curl_setopt, and the
//    CURLOPT_POSTFIELDS body if sampled and known (none for a body from
//    CURLOPT_READFUNCTION/CURLOPT_INFILE). The request as sent is not
//    observed;
//  - hop 0, W3b: a body from CURLOPT_READFUNCTION/CURLOPT_INFILE (no
//    POSTFIELDS), if sampled, from the read-function gate (dd_curl_gate),
//    before any byte of it is sent: the parsed body only;
//  - hop > 0, from CURLOPT_PREREQFUNCTION (if installed), before its request
//    is sent: URL and method (CURLINFO_EFFECTIVE_URL/_METHOD), whatever the
//    state of the collector (stopped included). A hop whose URL has a scheme
//    other than http(s) gets no call at all;
//  - the response, once the hop is over (in the next hop's PREREQ, before
//    the collector is told the hop started, or once the transfer is over),
//    if the collector (curl_collect.h) holds the hop with its complete head:
//    status (a decimal string) and headers, with the body for the last hop
//    only, if it is sampled and parseable. One call per hop, and the last
//    call of the subcontext (no URL: it is in the request call of the same
//    subcontext);
//  - a hop that got no response is closed by a last call without data.
// The subcontexts are named curl-<transfer>-<hop>. Hop n is the one started
// by the (n+1)-th PREREQ call; proxy CONNECT heads are not hops (the
// collector drops them). The response data comes from the collector fed by
// the interposed header and write functions, which run no WAF code (G9.1):
// the body bytes are what libcurl's write function delivers (de-chunked,
// and content-decoded with CURLOPT_ACCEPT_ENCODING), read through the
// collector's body sink.
//
// Whether a transfer's bodies are sent (sampling) is decided once, when W1
// runs, for both bodies (hop 0's request body and the last hop's response
// body). A sampled transfer holds a slot of the per-request limit, which it
// gives back if it ends up sending no body.
//
// The calls made from libcurl callbacks (W2, the intermediate response call,
// W3b) never block nor unwind through
// libcurl: a blocking verdict is recorded (and further evaluation of the
// transfer stops); a bailout is caught and recorded. Both are acted upon
// once control is back in the PHP function hook that called libcurl
// (dd_curl_eval_reraise, dd_curl_eval_block): that is the first point where
// it is safe (G9.3). Nothing else re-raises (no VM interrupt handler), and a
// tracer sandbox around the hook swallows the bailout as it does any other
// fatal error. A hook entered inside libcurl (an app callback of another
// transfer) does nothing at all (G4.3): no evaluation, nothing to defer.

#include <php.h>
#include <stdbool.h>
#include <stdint.h>

#include "../attributes.h"
#include "../dddefs.h"
#include "../request_abort.h"
#include "curl_collect.h"
#include "curl_linkage_detect.h"
#include "curl_opts.h"

// The read-function gate of hop 0 (G6.7, G7.15): armed at W1 for a sampled
// transfer whose body comes from CURLOPT_READFUNCTION or CURLOPT_INFILE (and
// no CURLOPT_POSTFIELDS). The interposed read function then fills the gate
// through ext/curl's read function, the gate makes its request-stage call
// (W3b) with the body, then serves the bytes it holds and turns off: later
// reads go straight to ext/curl's function.
enum dd_curl_gate_state {
    DD_CURL_GATE_OFF = 0, // not armed, or done
    DD_CURL_GATE_FILLING, // reading the body; nothing passed to libcurl yet
    DD_CURL_GATE_SERVING, // W3b made: passing the held bytes to libcurl
};

// What the gate does with a value the original read function returned
enum dd_curl_gate_step {
    DD_CURL_GATE_STEP_MORE,  // taken; read again
    DD_CURL_GATE_STEP_PASS,  // return the value to libcurl as it is
    DD_CURL_GATE_STEP_READY, // the body is read and W3b was made: serve
    DD_CURL_GATE_STEP_ABORT, // return CURL_READFUNC_ABORT (no WAF call)
};

struct dd_curl_gate {
    enum dd_curl_gate_state state;
    // the body read, up to the body cap: what W3b analyses, through the body
    // sink interface (G5.18). Its allocations, like the WAF call's, have no
    // memory guard (G9.6): a memory-limit fatal is caught by the read
    // callback's zend_try.
    struct dd_body_buf body;
    // the bytes of the read that crossed the cap, past it: served, not
    // analysed
    struct dd_http_buf over;
    size_t got;    // bytes the original read function returned so far
    size_t served; // of the held bytes (body, then over)
    bool eof;      // the original read function returned 0
    // the upload size libcurl sends (CURLINFO_CONTENT_LENGTH_UPLOAD), once
    // known; -1 if not known (chunked): the body ends at EOF then
    int64_t total;
    bool total_read;
    // the content type of the body: the last one of CURLOPT_HTTPHEADER
    zend_string *nullable ct;
};

enum dd_curl_sampling {
    DD_CURL_SAMPLING_UNDECIDED = 0,
    DD_CURL_SAMPLING_YES, // its bodies are sent to the WAF
    DD_CURL_SAMPLING_NO,
};

struct dd_curl_eval {
    bool active; // this transfer is evaluated
    // decided once, when W1 runs (dd_curl_eval_presend)
    enum dd_curl_sampling sampling;
    bool slot; // sampled, and the slot of the request limit is not given back
    bool body_added; // a body was added to a WAF call
    bool blocked;    // a blocking verdict was recorded
    bool bailout;    // a bailout was caught in a libcurl callback
    dd_result verdict;
    struct block_params block_params;
    uint64_t seq; // of the transfer in the request (subcontext ids)
    size_t body_cap;
    // CURLOPT_URL when the transfer started (with the scheme libcurl
    // guesses, if it has none)
    zend_string *nullable url;

    // the hop being evaluated: the count of our PREREQ calls of the transfer
    // less one (hop n starts at the (n+1)-th call), whatever the collector
    // holds
    size_t hop;
    // our PREREQ calls of the transfer so far
    size_t prereqs;
    zend_string *nullable subctx_id;
    bool opened; // a call without subctx_last_call was made
    bool closed; // the last call was made
    // the hop's URL has a scheme other than http(s): no call for it
    bool hop_skipped;
    struct dd_curl_gate gate;
};

// MINIT: testing functions
void dd_curl_eval_minit(void);
// RINIT
void dd_curl_eval_rinit(void);
void dd_curl_eval_rshutdown(void);

// Around the calls of the hooks into libcurl that run transfers (curl_exec,
// curl_multi_exec, curl_multi_remove_handle, curl_multi_close), through which
// ext/curl may run the app's code (e.g. a WRITEFUNCTION calling curl_exec()
// for another handle). While one is in progress, the curl hooks that observe
// do nothing (dd_curl_eval_in_libcurl).
void dd_curl_eval_libcurl_enter(void);
// puts back a truncation flag that a fatal error swallowed by a sandbox left
// saved (to be called when a curl hook is entered)
void dd_curl_eval_hook_enter(void);
void dd_curl_eval_libcurl_leave(void);
bool dd_curl_eval_in_libcurl(void);
// From a hook, once out of libcurl: re-raises a bailout caught in a libcurl
// callback and not re-raised yet (does not return then).
void dd_curl_eval_reraise_pending(void);

void dd_curl_eval_init(struct dd_curl_eval *nonnull ev);
void dd_curl_eval_destroy(struct dd_curl_eval *nonnull ev);

// Why a transfer with these options would not be evaluated
// (dd_curl_eval_start), without starting anything; NULL if it would.
// "appsec_inactive" and "not_evaluated" (no request being analysed) are no
// skip reasons of G3.9 and are not logged; "scheme" is one.
const char *nullable dd_curl_eval_skip_reason(
    const struct dd_curl_opts *nonnull opts);
// A transfer with our functions installed starts: decides whether it is
// evaluated and, if so, whether its bodies are sent.
void dd_curl_eval_start(struct dd_curl_eval *nonnull ev,
    const struct dd_curl_opts *nonnull opts, size_t body_cap);
// W1: hop 0, before libcurl runs, from the recorded options. Returns whether
// it must be blocked.
bool dd_curl_eval_presend(
    struct dd_curl_eval *nonnull ev, const struct dd_curl_opts *nonnull opts);
// From CURLOPT_PREREQFUNCTION, in two steps around the collector's PREREQ
// event (dd_curl_collect_prereq), which releases the previous hop's head and
// body:
// - before it: the hop that is over (none at the first call) gets its
//   response call from what the collector holds, or the call without data;
// - after it: the hop about to be sent is counted and, for hop > 0, gets W2.
//   Returns whether the request must not be sent (blocked, or a bailout was
//   caught).
void dd_curl_eval_prereq_hop_over(struct dd_curl_eval *nonnull ev,
    const struct dd_curl_collect *nonnull coll);
bool dd_curl_eval_prereq_hop_start(
    struct dd_curl_eval *nonnull ev, dd_curl_easy *nonnull cp);
// The transfer is over (from a PHP function hook): makes the response call of
// the last hop if it got a response, or closes it.
void dd_curl_eval_end(struct dd_curl_eval *nonnull ev,
    const struct dd_curl_collect *nonnull coll);
// Whether the bodies of the transfer are sent (decided at W1)
static inline bool dd_curl_eval_sampled(const struct dd_curl_eval *nonnull ev)
{
    return ev->sampling == DD_CURL_SAMPLING_YES;
}

// The read-function gate (G6.7), driven by the interposed read function
// (inside libcurl's read callback, hop 0 only: a later hop turns it off).
static inline bool dd_curl_eval_gate_armed(
    const struct dd_curl_eval *nonnull ev)
{
    return ev->gate.state != DD_CURL_GATE_OFF;
}
static inline bool dd_curl_eval_gate_filling(
    const struct dd_curl_eval *nonnull ev)
{
    return ev->gate.state == DD_CURL_GATE_FILLING;
}
// While filling: how many bytes to ask of the original read function for a
// libcurl read of len bytes (len, or what is left of the upload size libcurl
// sends; it never reads past it); 0 if the body is complete, after which
// dd_curl_eval_gate_take(ev, NULL, 0, 0, len) makes W3b.
size_t dd_curl_eval_gate_ask(
    struct dd_curl_eval *nonnull ev, dd_curl_easy *nonnull cp, size_t len);
// While filling: the original read function returned ret for an ask of
// `ask` bytes into ptr, for a libcurl read of len bytes. Bytes are held; at
// EOF, the cap or the upload size, W3b is made (under zend_try).
// CURL_READFUNC_ABORT, CURL_READFUNC_PAUSE or another value above ask is
// returned with no WAF call: passed on unchanged (ABORT or another value
// turn the gate off; after PAUSE it goes on filling), except a value within
// len after bytes were held, which gives DD_CURL_GATE_STEP_ABORT. Not
// filling (the gate was turned off meanwhile): DD_CURL_GATE_STEP_PASS.
enum dd_curl_gate_step dd_curl_eval_gate_take(struct dd_curl_eval *nonnull ev,
    const char *nullable ptr, size_t ret, size_t ask, size_t len);
// Once ready: whether the read must return CURL_READFUNC_ABORT (W3b gave a
// blocking verdict, or a bailout was caught; applied or re-raised once
// libcurl has returned, G7.16)
bool dd_curl_eval_gate_aborts(const struct dd_curl_eval *nonnull ev);
// Serving: copies held bytes into ptr (at most len) and returns their
// count. *forward: nothing is held anymore and the body did not end (EOF):
// the read goes to the original read function, and the gate is off.
size_t dd_curl_eval_gate_serve(struct dd_curl_eval *nonnull ev,
    char *nonnull ptr, size_t len, bool *nonnull forward);
#ifdef TESTING
const char *nonnull dd_curl_eval_gate_state_name(
    const struct dd_curl_eval *nonnull ev);
#endif

// If a bailout was caught in a libcurl callback of the transfer: re-raises it
// (does not return)
void dd_curl_eval_reraise(struct dd_curl_eval *nonnull ev);
// Whether dd_curl_eval_reraise() or dd_curl_eval_block() has something to do
// now: a verdict, or a bailout to re-raise
bool dd_curl_eval_settleable(const struct dd_curl_eval *nonnull ev);
// A bailout was caught in a libcurl callback, outside the evaluation (e.g.
// the memory limit while collecting the data)
void dd_curl_eval_caught_bailout(struct dd_curl_eval *nonnull ev);
static inline bool dd_curl_eval_blocked(const struct dd_curl_eval *nonnull ev)
{
    return ev->blocked;
}
// Blocks the request with the recorded verdict (dd_req_lifecycle_abort);
// usually does not return. Nothing happens if no verdict was recorded.
void dd_curl_eval_block(struct dd_curl_eval *nonnull ev);

// The headers of an HTTP head (start line first): lower-cased names, lists
// of trimmed values, empty values dropped.
void dd_curl_headers_to_array(
    const char *nullable head, size_t len, zval *nonnull arr);
