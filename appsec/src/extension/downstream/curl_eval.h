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
// The calls made from libcurl callbacks (W2, the intermediate response call)
// never block: a blocking verdict is recorded (and further evaluation of the
// transfer stops). It is acted upon once control is back in the PHP function
// hook that called libcurl (dd_curl_eval_block): that is the first point
// where it is safe (G9.3).
// A hook entered inside libcurl (an app callback of another
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
};

// MINIT
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
void dd_curl_eval_libcurl_leave(void);
bool dd_curl_eval_in_libcurl(void);

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
//   Returns whether the request must not be sent (blocked).
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

// Whether dd_curl_eval_block() has something to do now: a verdict
bool dd_curl_eval_settleable(const struct dd_curl_eval *nonnull ev);
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
