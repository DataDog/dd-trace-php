// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "curl_calibrate.h"

#include <stdarg.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>

// curl.h: CURLOPTTYPE_OBJECTPOINT 10000, CURLOPTTYPE_FUNCTIONPOINT 20000
enum {
    DD_CURLOPT_WRITEDATA = 10001,
    DD_CURLOPT_READDATA = 10009,
    DD_CURLOPT_HEADERDATA = 10029,
    DD_CURLOPT_WRITEFUNCTION = 20011,
    DD_CURLOPT_READFUNCTION = 20012,
    DD_CURLOPT_HEADERFUNCTION = 20079,
};

enum { DD_ROUNDS = 2 };

// A plausible upper bound on sizeof(struct Curl_easy) plus allocator slack:
// the handles of the libcurl matrix are a few KiB. A larger usable size means
// the malloc_usable_size found is not the one of libcurl's allocator.
enum { DD_CURL_CALIBRATION_MAX_HANDLE = 64 * 1024 };

static const struct {
    const char *name;
    dd_curl_option option;
} _slots[DD_CURL_SLOT_COUNT] = {
    [DD_CURL_SLOT_WRITE_FUNCTION] = {"write_function",
        DD_CURLOPT_WRITEFUNCTION},
    [DD_CURL_SLOT_WRITE_DATA] = {"write_data", DD_CURLOPT_WRITEDATA},
    [DD_CURL_SLOT_HEADER_FUNCTION] = {"header_function",
        DD_CURLOPT_HEADERFUNCTION},
    [DD_CURL_SLOT_HEADER_DATA] = {"header_data", DD_CURLOPT_HEADERDATA},
    [DD_CURL_SLOT_READ_FUNCTION] = {"read_function", DD_CURLOPT_READFUNCTION},
    [DD_CURL_SLOT_READ_DATA] = {"read_data", DD_CURLOPT_READDATA},
};

typedef size_t (*_callback)(char *ptr, size_t size, size_t nmemb, void *ud);

static void _set_reason(struct dd_curl_calibration *out, const char *fmt, ...)
    __attribute__((format(printf, 2, 3)));
static bool _sentinels(uintptr_t values[DD_ROUNDS][DD_CURL_SLOT_COUNT],
    struct dd_curl_calibration *out);
static bool _set(const struct dd_curl_calibrate_api *api, dd_curl_easy *probe,
    const uintptr_t values[DD_CURL_SLOT_COUNT],
    struct dd_curl_calibration *out);
static bool _find(const dd_curl_easy *probe, size_t size,
    const uintptr_t values[DD_CURL_SLOT_COUNT],
    const bool needed[DD_CURL_SLOT_COUNT], int round,
    size_t offsets[DD_CURL_SLOT_COUNT], struct dd_curl_calibration *out);

void dd_curl_calibrate(
    const struct dd_curl_calibrate_api *api, struct dd_curl_calibration *out)
{
    memset(out, 0, sizeof(*out));
    if (!api->usable_size) {
        _set_reason(out, "the allocator provides no malloc_usable_size");
        return;
    }
    // Every handle comes from curl_dbg_calloc, behind a struct memdebug: the
    // CURL * is not the start of an allocator block, so malloc_usable_size()
    // of it is undefined and the scan bound could be anything. Refused before
    // any handle is created.
    if (api->features & DD_CURL_VERSION_CURLDEBUG) {
        _set_reason(out, "libcurl is built with memory debugging (CURLDEBUG): "
                         "handles are not allocator blocks");
        return;
    }
    // Below 7.80 only the read slots are used (G3.4, exception to G5.1): an
    // ambiguous write or header slot does not refuse, and their offsets
    // stay 0.
    bool all = api->version_num >= DD_CURL_PREREQ_MIN_VERSION;
    bool needed[DD_CURL_SLOT_COUNT];
    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
        needed[i] = all || i == DD_CURL_SLOT_READ_FUNCTION ||
                    i == DD_CURL_SLOT_READ_DATA;
    }
    uintptr_t values[DD_ROUNDS][DD_CURL_SLOT_COUNT];
    if (!_sentinels(values, out)) {
        return;
    }

    // never performed: a transfer copies the read slots into libcurl's
    // transfer state, where they would be found a second time
    dd_curl_easy *probe = api->easy_init();
    if (!probe) {
        _set_reason(out, "curl_easy_init() failed");
        return;
    }
    size_t size = api->usable_size(probe);
    bool ok = size >= DD_CURL_SLOT_COUNT * sizeof(void *) &&
              size <= DD_CURL_CALIBRATION_MAX_HANDLE;
    if (!ok) {
        // a different allocator than libcurl's (e.g. a libcurl linked
        // -Bsymbolic against a private one): never scan by that bound
        _set_reason(out,
            "malloc_usable_size reported an implausible %zu-byte handle", size);
    }
    size_t offsets[DD_ROUNDS][DD_CURL_SLOT_COUNT] = {{0}};
    for (int r = 0; r < DD_ROUNDS && ok; r++) {
        ok = _set(api, probe, values[r], out) &&
             _find(probe, size, values[r], needed, r, offsets[r], out);
    }
    // the sentinel functions are never called: cleanup of a handle that never
    // performed runs no callback
    api->easy_cleanup(probe);
    if (!ok) {
        return;
    }

    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
        if (needed[i] && offsets[1][i] != offsets[0][i]) {
            _set_reason(out, "%s moved between rounds (offset %zu, then %zu)",
                _slots[i].name, offsets[0][i], offsets[1][i]);
            return;
        }
    }
    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
        out->offsets[i] = needed[i] ? offsets[0][i] : 0;
    }
    out->accepted = true;
    _set_reason(out, all ? "write, header and read slots found once each, "
                           "stable over two rounds"
                         : "read slots found once each, stable over two "
                           "rounds (below libcurl 7.80 only the read slots "
                           "are used)");
}

const char *dd_curl_slot_name(enum dd_curl_slot slot)
{
    if ((int)slot < 0 || slot >= DD_CURL_SLOT_COUNT) {
        return "";
    }
    return _slots[slot].name;
}

// Never called. Each touches its own counter, so the bodies differ and
// identical-code folding (GCC's -fipa-icf at -O2) cannot merge them; distinct
// addresses are checked anyway.
static unsigned _sentinel_calls[DD_ROUNDS * 3];
#define DD_SENTINEL_FN(n)                                                      \
    static size_t _sentinel_fn_##n(                                            \
        char *ptr, size_t size, size_t nmemb, void *ud)                        \
    {                                                                          \
        (void)ptr;                                                             \
        (void)size;                                                            \
        (void)nmemb;                                                           \
        (void)ud;                                                              \
        _sentinel_calls[n]++;                                                  \
        return 0;                                                              \
    }
// the signature is the one of libcurl's write callbacks
// NOLINTBEGIN(bugprone-easily-swappable-parameters,readability-non-const-parameter)
DD_SENTINEL_FN(0)
DD_SENTINEL_FN(1)
DD_SENTINEL_FN(2)
DD_SENTINEL_FN(3)
DD_SENTINEL_FN(4)
DD_SENTINEL_FN(5)
// NOLINTEND(bugprone-easily-swappable-parameters,readability-non-const-parameter)
#undef DD_SENTINEL_FN

static const _callback _sentinel_fns[DD_ROUNDS][3] = {
    {_sentinel_fn_0, _sentinel_fn_1, _sentinel_fn_2},
    {_sentinel_fn_3, _sentinel_fn_4, _sentinel_fn_5},
};
// data sentinels: addresses of our own, so no libcurl pointer equals them
static char _sentinel_data[DD_ROUNDS][3];

static bool _sentinels(uintptr_t values[DD_ROUNDS][DD_CURL_SLOT_COUNT],
    struct dd_curl_calibration *out)
{
    for (int r = 0; r < DD_ROUNDS; r++) {
        values[r][DD_CURL_SLOT_WRITE_FUNCTION] = (uintptr_t)_sentinel_fns[r][0];
        values[r][DD_CURL_SLOT_HEADER_FUNCTION] =
            (uintptr_t)_sentinel_fns[r][1];
        values[r][DD_CURL_SLOT_READ_FUNCTION] = (uintptr_t)_sentinel_fns[r][2];
        values[r][DD_CURL_SLOT_WRITE_DATA] = (uintptr_t)&_sentinel_data[r][0];
        values[r][DD_CURL_SLOT_HEADER_DATA] = (uintptr_t)&_sentinel_data[r][1];
        values[r][DD_CURL_SLOT_READ_DATA] = (uintptr_t)&_sentinel_data[r][2];
    }
    const uintptr_t *all = &values[0][0];
    for (int i = 0; i < DD_ROUNDS * DD_CURL_SLOT_COUNT; i++) {
        for (int j = 0; j < i; j++) {
            if (all[i] == all[j]) {
                _set_reason(out, "the sentinels are not distinct");
                return false;
            }
        }
    }
    return true;
}

static bool _set(const struct dd_curl_calibrate_api *api, dd_curl_easy *probe,
    const uintptr_t values[DD_CURL_SLOT_COUNT], struct dd_curl_calibration *out)
{
    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
        dd_curl_code rc;
        if (i == DD_CURL_SLOT_WRITE_FUNCTION ||
            i == DD_CURL_SLOT_HEADER_FUNCTION ||
            i == DD_CURL_SLOT_READ_FUNCTION) {
            // a function pointer must be passed as one through the varargs
            rc =
                api->easy_setopt(probe, _slots[i].option, (_callback)values[i]);
        } else {
            rc = api->easy_setopt(probe, _slots[i].option, (void *)values[i]);
        }
        if (rc != 0) {
            _set_reason(
                out, "curl_easy_setopt(%s) failed with %d", _slots[i].name, rc);
            return false;
        }
    }
    return true;
}

static bool _find(const dd_curl_easy *probe, size_t size,
    const uintptr_t values[DD_CURL_SLOT_COUNT],
    const bool needed[DD_CURL_SLOT_COUNT], int round,
    size_t offsets[DD_CURL_SLOT_COUNT], struct dd_curl_calibration *out)
{
    size_t count[DD_CURL_SLOT_COUNT] = {0};
    const char *block = probe;
    for (size_t off = 0; off + sizeof(uintptr_t) <= size;
        off += sizeof(uintptr_t)) {
        uintptr_t v;
        memcpy(&v, block + off, sizeof(v));
        for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
            if (v == values[i] && count[i]++ == 0) {
                offsets[i] = off;
            }
        }
    }
    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
        if (needed[i] && count[i] != 1) {
            _set_reason(out,
                "%s sentinel found %zu times in the %zu-byte handle (round %d)",
                _slots[i].name, count[i], size, round + 1);
            return false;
        }
    }
    return true;
}

static void _set_reason(struct dd_curl_calibration *out, const char *fmt, ...)
{
    va_list ap;
    va_start(ap, fmt);
    (void)vsnprintf(out->reason, sizeof(out->reason), fmt, ap);
    va_end(ap);
}
