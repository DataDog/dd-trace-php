// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// PHP-agnostic interposition of our functions in libcurl's write, header and
// read callback slots of a CURL * (G5.2), through the offsets found by the
// calibration (curl_calibrate.h). No Zend or libcurl headers: it is also
// built into the calibration's standalone test program
// (appsec/tests/curl_calibrate/).
//
// ext/curl sets each slot to one of its static functions (curl_write,
// curl_write_header, curl_read) and the slot's data to the handle's own
// php_curl *, when the handle is created and on curl_reset(); nothing else
// it does through PHP touches them. Those functions are the "pristine" ones.
// They cannot be looked up, so each is learnt from the first handle that
// passes the guard with a slot that does not hold ours, and never changes
// afterwards: a slot that later holds something else is not understood, and
// the handle is not interposed.
//
// Per transfer: dd_curl_slots_normalise() at its start (observed or not),
// then for an observed one dd_curl_slots_guard() and
// dd_curl_slots_install(), and dd_curl_slots_restore() at its end. Slots are
// read at the calibrated offsets and written with curl_easy_setopt, which
// stores at those same offsets. Below libcurl 7.80 only the read slot is used
// (G3.4): the others are never read nor written.

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "curl_calibrate.h"

enum dd_curl_islot {
    DD_CURL_ISLOT_WRITE,  // CURLOPT_WRITEFUNCTION, data CURLOPT_WRITEDATA
    DD_CURL_ISLOT_HEADER, // CURLOPT_HEADERFUNCTION, data CURLOPT_HEADERDATA
    DD_CURL_ISLOT_READ,   // CURLOPT_READFUNCTION, data CURLOPT_READDATA
    DD_CURL_ISLOT_COUNT
};

// curl_write_callback, curl_read_callback
typedef size_t (*dd_curl_slot_fn)(
    char *ptr, size_t size, size_t nmemb, void *userdata);

struct dd_curl_slots {
    dd_curl_code (*easy_setopt)(dd_curl_easy *, dd_curl_option, ...);
    size_t fn_off[DD_CURL_ISLOT_COUNT];
    size_t data_off[DD_CURL_ISLOT_COUNT];
    bool used[DD_CURL_ISLOT_COUNT];
    dd_curl_slot_fn ours[DD_CURL_ISLOT_COUNT];
    // the pristine functions; 0 until learnt. Shared by all threads (the
    // static functions of ext/curl are the same for every handle): read and
    // set atomically, set once.
    uintptr_t pristine[DD_CURL_ISLOT_COUNT];
};

enum dd_curl_slot_state {
    DD_CURL_SLOT_UNUSED,   // not used with this libcurl (below 7.80)
    DD_CURL_SLOT_OURS,     // holds our function
    DD_CURL_SLOT_PRISTINE, // holds the pristine function
    DD_CURL_SLOT_OTHER,    // anything else (or the pristine is not known)
};

// cal must be accepted; version_num is LIBCURL_VERSION_NUM
void dd_curl_slots_init(struct dd_curl_slots *s,
    const struct dd_curl_calibration *cal, unsigned int version_num,
    dd_curl_code (*easy_setopt)(dd_curl_easy *, dd_curl_option, ...),
    const dd_curl_slot_fn ours[DD_CURL_ISLOT_COUNT]);

// The pristine function of the slot; NULL if not learnt yet
dd_curl_slot_fn dd_curl_slots_pristine(
    const struct dd_curl_slots *s, enum dd_curl_islot slot);

enum dd_curl_slot_state dd_curl_slots_state(const struct dd_curl_slots *s,
    const dd_curl_easy *cp, enum dd_curl_islot slot);
// The slot's data pointer (NULL for an unused slot)
void *dd_curl_slots_data(const struct dd_curl_slots *s, const dd_curl_easy *cp,
    enum dd_curl_islot slot);

// Each used slot that holds our function gets the pristine one back (a copy
// made while ours were installed carries them; G5.2). False if one of them
// could not be put back (libcurl refused it): that slot still holds ours.
bool dd_curl_slots_normalise(struct dd_curl_slots *s, dd_curl_easy *cp);

#define DD_CURL_SLOTS_REASON_SIZE 128

// Whether the handle can be interposed: each used slot holds the pristine
// function or ours, and its data is `owner` (the handle's php_curl *). False
// with the reason otherwise. A pristine function not known yet is learnt here
// from the slot, but only once every slot passed.
bool dd_curl_slots_guard(struct dd_curl_slots *s, const dd_curl_easy *cp,
    const void *owner, char reason[DD_CURL_SLOTS_REASON_SIZE]);

// Puts our function in each used slot (the data stays); after a successful
// guard. False if libcurl refused one: the slots are as before then.
bool dd_curl_slots_install(struct dd_curl_slots *s, dd_curl_easy *cp);

// Each used slot that still holds our function gets the pristine one back:
// one overwritten since (curl_reset()) is left alone
void dd_curl_slots_restore(struct dd_curl_slots *s, dd_curl_easy *cp);

// "write", "header", "read" ("" for an invalid slot)
const char *dd_curl_islot_name(enum dd_curl_islot slot);
