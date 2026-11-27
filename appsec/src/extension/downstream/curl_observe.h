// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// Observation of the transfers of PHP curl handles: per-handle state
// (tracked options, last transfer).
//
// The ext/curl function hooks (curl_hooks.c) drive this module; the WAF
// evaluation of the transfers is in curl_eval.c.

#include <php.h>
#include <stdbool.h>

#include "../attributes.h"
#include "curl_eval.h"
#include "curl_opts.h"

struct dd_curl_hstate;

// MINIT: testing functions
void dd_curl_observe_minit(void);
// zend extension startup, after dd_curl_handle_startup(); returns whether
// handles can be observed in this process (the hooks are installed only then)
bool dd_curl_observe_startup(void);
void dd_curl_observe_rinit(void);
// restores the options of any transfer still observed and frees all state
void dd_curl_observe_rshutdown(void);

// --- driven by the hooks ---

// Whether handles are tracked now (in a request, analysis not disabled)
bool dd_curl_observe_tracking(void);

// The tracked state of a PHP curl handle; NULL if it isn't tracked (e.g. not
// created by curl_init() or curl_copy_handle() while AppSec was enabled).
struct dd_curl_hstate *nullable dd_curl_hstate_find(zval *nonnull zch);
// New state for a handle just returned by curl_init()/curl_copy_handle();
// NULL if handles are not being tracked.
struct dd_curl_hstate *nullable dd_curl_hstate_create(zval *nonnull zch);
struct dd_curl_opts *nonnull dd_curl_hstate_opts(
    struct dd_curl_hstate *nonnull hs);
// A key for a multi handle (CurlMultiHandle object or PHP 7 resource), never
// reused for another multi handle in the request; 0 if zmh is neither or, on
// PHP 8 without create, if no transfer was added to it yet (create: for
// curl_multi_add_handle(), and only while handles are tracked).
uintptr_t dd_curl_multi_key(zval *nonnull zmh, bool create);

// A transfer of zch starts (curl_exec(): multi == 0; the first
// curl_multi_exec() after curl_multi_add_handle(): the multi's key, see
// dd_curl_xfer_start_pending). Records whether it is observed and, if so,
// installs our functions. Returns the handle's state if this started a new
// transfer with our functions installed, NULL otherwise.
struct dd_curl_hstate *nullable dd_curl_xfer_begin(
    zval *nonnull zch, uintptr_t multi);
// The transfer is over: restores the callback slots and options (if still
// ours). completed: libcurl reported the
// transfer done without error (the body sink's framing-complete flag).
void dd_curl_xfer_end(struct dd_curl_hstate *nonnull hs, bool completed);
void dd_curl_xfer_set_in_exec(struct dd_curl_hstate *nonnull hs, bool in_exec);
// curl_multi_exec(): re-installs our options on the transfers of that multi
// (an app's curl_reset()/curl_setopt() between calls may have removed them),
// or stops observing those whose options now say they must not be observed
void dd_curl_xfer_reassert_multi(uintptr_t multi);
// curl_multi_close(): ends the transfers of that multi
void dd_curl_xfer_end_multi(uintptr_t multi);
// curl_multi_exec() returned CURLM_OK with nothing running (G4.18): every
// transfer of that multi still observed is over. Ends them.
void dd_curl_xfer_flush_multi(uintptr_t multi);
// curl_multi_add_handle() / curl_multi_remove_handle() succeeded: libcurl
// has the handle in that multi now / no longer (whether its transfer is
// observed or not): until then, it starts no other transfer of the handle
void dd_curl_xfer_added_to_multi(zval *nonnull zch, uintptr_t multi);
// A successful add also makes the handle pending in that multi (G4.17):
// nothing is recorded or evaluated until the first curl_multi_exec of the
// multi, since libcurl reads the options only then. A failed add (also of a
// handle already in it), a removal and the close of the multi drop the
// pending entry without starting the transfer.
void dd_curl_xfer_add_failed(zval *nonnull zch, uintptr_t multi);
// curl_multi_exec(), before libcurl runs: begins the transfer of each handle
// pending in that multi. Returns whether the request is being blocked (the
// transfers after the one that blocks it stay pending).
bool dd_curl_xfer_start_pending(uintptr_t multi);
// A curl_multi_exec() entered inside libcurl (G4.3): the transfers pending in
// that multi are dropped, never started (G4.17, G6.12).
void dd_curl_xfer_drop_pending(uintptr_t multi);
// Drops the pending entry of the handle in that multi, nothing else (a nested
// remove, G4.17); an installed transfer is left alone (G4.3)
void dd_curl_xfer_drop_pending_handle(zval *nonnull zch, uintptr_t multi);
void dd_curl_xfer_removed_from_multi(zval *nonnull zch, uintptr_t multi);
// curl_multi_remove_handle() / curl_multi_info_read() DONE message: ends the
// handle's transfer if it is one of that multi
void dd_curl_xfer_end_in_multi(
    zval *nonnull zch, uintptr_t multi, bool completed);
// Whether libcurl has a current URL for the handle (CURLINFO_EFFECTIVE_URL
// is not empty); false if it has none or it cannot be read
bool dd_curl_has_effective_url(zval *nonnull zch);
