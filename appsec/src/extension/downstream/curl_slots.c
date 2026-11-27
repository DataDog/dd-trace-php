// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "curl_slots.h"

#include <stdio.h>
#include <string.h>

// curl.h: CURLOPTTYPE_FUNCTIONPOINT 20000
enum {
    DD_CURLOPT_WRITEFUNCTION = 20011,
    DD_CURLOPT_READFUNCTION = 20012,
    DD_CURLOPT_HEADERFUNCTION = 20079,
};

static const struct {
    const char *name;
    dd_curl_option option;
    enum dd_curl_slot fn_slot;
    enum dd_curl_slot data_slot;
} _islots[DD_CURL_ISLOT_COUNT] = {
    [DD_CURL_ISLOT_WRITE] = {"write", DD_CURLOPT_WRITEFUNCTION,
        DD_CURL_SLOT_WRITE_FUNCTION, DD_CURL_SLOT_WRITE_DATA},
    [DD_CURL_ISLOT_HEADER] = {"header", DD_CURLOPT_HEADERFUNCTION,
        DD_CURL_SLOT_HEADER_FUNCTION, DD_CURL_SLOT_HEADER_DATA},
    [DD_CURL_ISLOT_READ] = {"read", DD_CURLOPT_READFUNCTION,
        DD_CURL_SLOT_READ_FUNCTION, DD_CURL_SLOT_READ_DATA},
};

static uintptr_t _read_ptr(const dd_curl_easy *cp, size_t off);
static uintptr_t _fn(const struct dd_curl_slots *s, const dd_curl_easy *cp,
    enum dd_curl_islot slot);
static bool _set_fn(
    struct dd_curl_slots *s, dd_curl_easy *cp, int slot, uintptr_t fn);

void dd_curl_slots_init(struct dd_curl_slots *s,
    const struct dd_curl_calibration *cal, unsigned int version_num,
    dd_curl_code (*easy_setopt)(dd_curl_easy *, dd_curl_option, ...),
    const dd_curl_slot_fn ours[DD_CURL_ISLOT_COUNT])
{
    memset(s, 0, sizeof(*s));
    s->easy_setopt = easy_setopt;
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        // below 7.80 the calibration leaves the write and header offsets 0
        s->used[i] =
            cal->accepted && (i == DD_CURL_ISLOT_READ ||
                                 version_num >= DD_CURL_PREREQ_MIN_VERSION);
        s->fn_off[i] = cal->offsets[_islots[i].fn_slot];
        s->data_off[i] = cal->offsets[_islots[i].data_slot];
        s->ours[i] = ours[i];
    }
}

dd_curl_slot_fn dd_curl_slots_pristine(
    const struct dd_curl_slots *s, enum dd_curl_islot slot)
{
    if ((int)slot < 0 || slot >= DD_CURL_ISLOT_COUNT) {
        return NULL;
    }
    return (dd_curl_slot_fn)__atomic_load_n(
        &s->pristine[slot], __ATOMIC_ACQUIRE);
}

enum dd_curl_slot_state dd_curl_slots_state(const struct dd_curl_slots *s,
    const dd_curl_easy *cp, enum dd_curl_islot slot)
{
    if ((int)slot < 0 || slot >= DD_CURL_ISLOT_COUNT || !s->used[slot]) {
        return DD_CURL_SLOT_UNUSED;
    }
    uintptr_t fn = _fn(s, cp, slot);
    if (fn == (uintptr_t)s->ours[slot]) {
        return DD_CURL_SLOT_OURS;
    }
    uintptr_t pristine = (uintptr_t)dd_curl_slots_pristine(s, slot);
    if (pristine && fn == pristine) {
        return DD_CURL_SLOT_PRISTINE;
    }
    return DD_CURL_SLOT_OTHER;
}

void *dd_curl_slots_data(const struct dd_curl_slots *s, const dd_curl_easy *cp,
    enum dd_curl_islot slot)
{
    if ((int)slot < 0 || slot >= DD_CURL_ISLOT_COUNT || !s->used[slot]) {
        return NULL;
    }
    return (void *)_read_ptr(cp, s->data_off[slot]);
}

bool dd_curl_slots_normalise(struct dd_curl_slots *s, dd_curl_easy *cp)
{
    bool ok = true;
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        if (dd_curl_slots_state(s, cp, (enum dd_curl_islot)i) !=
            DD_CURL_SLOT_OURS) {
            continue;
        }
        // ours is only ever installed once the pristine is known
        uintptr_t pristine =
            (uintptr_t)dd_curl_slots_pristine(s, (enum dd_curl_islot)i);
        if (!pristine || !_set_fn(s, cp, i, pristine)) {
            ok = false;
        }
    }
    return ok;
}

bool dd_curl_slots_guard(struct dd_curl_slots *s, const dd_curl_easy *cp,
    const void *owner, char reason[DD_CURL_SLOTS_REASON_SIZE])
{
    // Every slot is checked before anything is learnt: a handle the guard
    // rejects (another extension's function, data that is not the handle's
    // own) must not fix the pristine functions for the process
    uintptr_t learn[DD_CURL_ISLOT_COUNT] = {0};
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        if (!s->used[i]) {
            continue;
        }
        uintptr_t fn = _fn(s, cp, (enum dd_curl_islot)i);
        if (!fn) {
            // not ext/curl's (libcurl's default for the header function)
            (void)snprintf(reason, DD_CURL_SLOTS_REASON_SIZE,
                "the %s function is not set", _islots[i].name);
            return false;
        }
        if (fn != (uintptr_t)s->ours[i]) {
            uintptr_t pristine =
                (uintptr_t)dd_curl_slots_pristine(s, (enum dd_curl_islot)i);
            if (pristine && fn != pristine) {
                (void)snprintf(reason, DD_CURL_SLOTS_REASON_SIZE,
                    "the %s function is neither ext/curl's nor ours",
                    _islots[i].name);
                return false;
            }
            if (!pristine) {
                // the first one seen that is not ours is ext/curl's
                learn[i] = fn;
            }
        }
        if (_read_ptr(cp, s->data_off[i]) != (uintptr_t)owner) {
            (void)snprintf(reason, DD_CURL_SLOTS_REASON_SIZE,
                "the %s data is not the handle's own", _islots[i].name);
            return false;
        }
    }
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        uintptr_t expected = 0;
        // another thread may have learnt it meanwhile: the same, or not
        if (learn[i] &&
            !__atomic_compare_exchange_n(&s->pristine[i], &expected, learn[i],
                false, __ATOMIC_ACQ_REL, __ATOMIC_ACQUIRE) &&
            expected != learn[i]) {
            (void)snprintf(reason, DD_CURL_SLOTS_REASON_SIZE,
                "the %s function is neither ext/curl's nor ours",
                _islots[i].name);
            return false;
        }
    }
    return true;
}

bool dd_curl_slots_install(struct dd_curl_slots *s, dd_curl_easy *cp)
{
    uintptr_t before[DD_CURL_ISLOT_COUNT];
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        if (!s->used[i]) {
            continue;
        }
        before[i] = _fn(s, cp, (enum dd_curl_islot)i);
        if (!_set_fn(s, cp, i, (uintptr_t)s->ours[i])) {
            for (int j = 0; j < i; j++) {
                if (s->used[j]) {
                    (void)_set_fn(s, cp, j, before[j]);
                }
            }
            return false;
        }
    }
    return true;
}

void dd_curl_slots_restore(struct dd_curl_slots *s, dd_curl_easy *cp)
{
    // the same as normalising: only a slot that still holds ours changes. A
    // slot libcurl refuses to change keeps ours, which only forward (G5.4);
    // the next transfer start tries again.
    (void)dd_curl_slots_normalise(s, cp);
}

const char *dd_curl_islot_name(enum dd_curl_islot slot)
{
    if ((int)slot < 0 || slot >= DD_CURL_ISLOT_COUNT) {
        return "";
    }
    return _islots[slot].name;
}

// cp was validated as a libcurl easy handle and the calibrated offsets lie
// inside it
static uintptr_t _read_ptr(const dd_curl_easy *cp, size_t off)
{
    uintptr_t v;
    memcpy(&v, (const char *)cp + off, sizeof(v));
    return v;
}

static uintptr_t _fn(const struct dd_curl_slots *s, const dd_curl_easy *cp,
    enum dd_curl_islot slot)
{
    return _read_ptr(cp, s->fn_off[slot]);
}

// Through curl_easy_setopt, which stores the function at the calibrated
// offset: a function pointer must be passed as one through the varargs
static bool _set_fn(
    struct dd_curl_slots *s, dd_curl_easy *cp, int slot, uintptr_t fn)
{
    return s->easy_setopt(cp, _islots[slot].option, (dd_curl_slot_fn)fn) == 0;
}
