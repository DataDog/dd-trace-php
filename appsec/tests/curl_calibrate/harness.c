// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// dd_curl_calibrate() against a real libcurl (the one dlopen("libcurl.so.4")
// finds, so LD_LIBRARY_PATH selects the build) and against a fake libcurl
// whose curl_easy_setopt stores the values in ways that must be refused.
// Usage: harness

#ifndef _GNU_SOURCE
#    define _GNU_SOURCE // RTLD_DEFAULT
#endif
#include "../../src/extension/downstream/curl_calibrate.h"
#include "../../src/extension/downstream/curl_slots.h"

#include <dlfcn.h>
#include <stdarg.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

enum {
    CURLOPT_WRITEDATA = 10001,
    CURLOPT_READDATA = 10009,
    CURLOPT_HEADERDATA = 10029,
    CURLOPT_WRITEFUNCTION = 20011,
    CURLOPT_READFUNCTION = 20012,
    CURLOPT_HEADERFUNCTION = 20079,
};

static int failures;

#define CHECK(cond)                                                            \
    do {                                                                       \
        if (!(cond)) {                                                         \
            fprintf(stderr, "  %s:%d: check failed: %s\n", __FILE__, __LINE__, \
                #cond);                                                        \
            failures++;                                                        \
            return;                                                            \
        }                                                                      \
    } while (0)

typedef size_t (*usable_size_fn)(void *);

static struct dd_curl_calibrate_api _real;
static dd_curl_version_info_data *(*_version_info)(dd_curl_version);

static void *_slot(void *handle, size_t off)
{
    void *v;
    memcpy(&v, (char *)handle + off, sizeof(v));
    return v;
}

static void _print(const struct dd_curl_calibration *c)
{
    printf("  accepted=%d reason=\"%s\"", c->accepted, c->reason);
    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
        printf(
            " %s=%zu", dd_curl_slot_name((enum dd_curl_slot)i), c->offsets[i]);
    }
    printf("\n");
}

// --- real libcurl -----------------------------------------------------------

static bool _read_only(enum dd_curl_slot slot)
{
    return slot == DD_CURL_SLOT_READ_FUNCTION || slot == DD_CURL_SLOT_READ_DATA;
}

static void _case_real_unique(void)
{
    bool all = _real.version_num >= DD_CURL_PREREQ_MIN_VERSION;
    printf("== real libcurl: %s unique, aligned offsets inside the handle\n",
        all ? "six" : "two (read slots only, below 7.80)");
    struct dd_curl_calibration c;
    dd_curl_calibrate(&_real, &c);
    _print(&c);
    CHECK(c.accepted);
    void *h = _real.easy_init();
    CHECK(h);
    size_t usable = _real.usable_size(h);
    _real.easy_cleanup(h);
    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
        if (!all && !_read_only((enum dd_curl_slot)i)) {
            CHECK(c.offsets[i] == 0);
            continue;
        }
        CHECK(c.offsets[i] % sizeof(void *) == 0);
        CHECK(c.offsets[i] + sizeof(void *) <= usable);
        for (int j = 0; j < i; j++) { CHECK(c.offsets[i] != c.offsets[j]); }
    }
}

static void _case_real_stable(void)
{
    puts("== real libcurl: a second calibration gives the same offsets");
    struct dd_curl_calibration a;
    struct dd_curl_calibration b;
    dd_curl_calibrate(&_real, &a);
    dd_curl_calibrate(&_real, &b);
    CHECK(a.accepted && b.accepted);
    CHECK(memcmp(a.offsets, b.offsets, sizeof(a.offsets)) == 0);
}

static size_t _app_write(char *p, size_t s, size_t n, void *u)
{
    (void)p;
    (void)u;
    return s * n;
}
static size_t _app_header(char *p, size_t s, size_t n, void *u)
{
    (void)p;
    (void)u;
    return (s * n) + 0 * 1;
}
static size_t _app_read(char *p, size_t s, size_t n, void *u)
{
    (void)p;
    (void)s;
    (void)n;
    (void)u;
    return 0;
}

static void _case_real_fresh_handle(void)
{
    puts("== real libcurl: a fresh handle has no header callback; the offsets "
         "read back what curl_easy_setopt set");
    if (_real.version_num < DD_CURL_PREREQ_MIN_VERSION) {
        puts("  skip: below 7.80 only the read slots are calibrated");
        return;
    }
    struct dd_curl_calibration c;
    dd_curl_calibrate(&_real, &c);
    CHECK(c.accepted);

    void *h = _real.easy_init();
    CHECK(h);
    void *hf = _slot(h, c.offsets[DD_CURL_SLOT_HEADER_FUNCTION]);
    void *hd = _slot(h, c.offsets[DD_CURL_SLOT_HEADER_DATA]);
    void *wf = _slot(h, c.offsets[DD_CURL_SLOT_WRITE_FUNCTION]);
    void *rf = _slot(h, c.offsets[DD_CURL_SLOT_READ_FUNCTION]);

    static char data[3];
    _real.easy_setopt(h, CURLOPT_WRITEFUNCTION, _app_write);
    _real.easy_setopt(h, CURLOPT_WRITEDATA, (void *)&data[0]);
    _real.easy_setopt(h, CURLOPT_HEADERFUNCTION, _app_header);
    _real.easy_setopt(h, CURLOPT_HEADERDATA, (void *)&data[1]);
    _real.easy_setopt(h, CURLOPT_READFUNCTION, _app_read);
    _real.easy_setopt(h, CURLOPT_READDATA, (void *)&data[2]);
    void *got[DD_CURL_SLOT_COUNT];
    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
        got[i] = _slot(h, c.offsets[i]);
    }
    _real.easy_cleanup(h);

    CHECK(hf == NULL);
    CHECK(hd == NULL);
    // libcurl's defaults are fwrite and fread
    CHECK(wf != NULL);
    CHECK(rf != NULL);
    CHECK(got[DD_CURL_SLOT_WRITE_FUNCTION] == (void *)_app_write);
    CHECK(got[DD_CURL_SLOT_WRITE_DATA] == (void *)&data[0]);
    CHECK(got[DD_CURL_SLOT_HEADER_FUNCTION] == (void *)_app_header);
    CHECK(got[DD_CURL_SLOT_HEADER_DATA] == (void *)&data[1]);
    CHECK(got[DD_CURL_SLOT_READ_FUNCTION] == (void *)_app_read);
    CHECK(got[DD_CURL_SLOT_READ_DATA] == (void *)&data[2]);
}

// --- fake libcurl -----------------------------------------------------------

#define FAKE_SIZE 512

enum fake_mode {
    FAKE_NORMAL,
    FAKE_DUPLICATE,   // WRITEFUNCTION stored twice
    FAKE_DROP,        // READDATA not stored
    FAKE_MOVE,        // WRITEDATA stored elsewhere the second time
    FAKE_SETOPT_FAIL, // HEADERFUNCTION refused
    FAKE_INIT_FAIL,   // curl_easy_init returns NULL
};
static enum fake_mode _mode;
static int _writedata_calls;
static int _init_calls;

static const size_t _fake_off[DD_CURL_SLOT_COUNT] = {
    [DD_CURL_SLOT_WRITE_FUNCTION] = 64,
    [DD_CURL_SLOT_WRITE_DATA] = 16,
    [DD_CURL_SLOT_HEADER_FUNCTION] = 72,
    [DD_CURL_SLOT_HEADER_DATA] = 24,
    [DD_CURL_SLOT_READ_FUNCTION] = 88,
    [DD_CURL_SLOT_READ_DATA] = 40,
};

static dd_curl_easy *_fake_init(void)
{
    _writedata_calls = 0;
    _init_calls++;
    return _mode == FAKE_INIT_FAIL ? NULL : calloc(1, FAKE_SIZE);
}

static void _fake_cleanup(dd_curl_easy *h) { free(h); }

static void _fake_store(void *h, size_t off, void *v)
{
    memcpy((char *)h + off, &v, sizeof(v));
}

static dd_curl_code _fake_setopt(dd_curl_easy *h, dd_curl_option opt, ...)
{
    va_list ap;
    va_start(ap, opt);
    void *v = va_arg(ap, void *);
    va_end(ap);
    switch (opt) {
    case CURLOPT_WRITEFUNCTION:
        _fake_store(h, _fake_off[DD_CURL_SLOT_WRITE_FUNCTION], v);
        if (_mode == FAKE_DUPLICATE) {
            _fake_store(h, 200, v);
        }
        return 0;
    case CURLOPT_WRITEDATA:
        if (_mode == FAKE_MOVE && ++_writedata_calls == 2) {
            _fake_store(h, _fake_off[DD_CURL_SLOT_WRITE_DATA], NULL);
            _fake_store(h, 208, v);
            return 0;
        }
        _fake_store(h, _fake_off[DD_CURL_SLOT_WRITE_DATA], v);
        return 0;
    case CURLOPT_HEADERFUNCTION:
        if (_mode == FAKE_SETOPT_FAIL) {
            return 48; // CURLE_UNKNOWN_OPTION
        }
        _fake_store(h, _fake_off[DD_CURL_SLOT_HEADER_FUNCTION], v);
        return 0;
    case CURLOPT_HEADERDATA:
        _fake_store(h, _fake_off[DD_CURL_SLOT_HEADER_DATA], v);
        return 0;
    case CURLOPT_READFUNCTION:
        _fake_store(h, _fake_off[DD_CURL_SLOT_READ_FUNCTION], v);
        return 0;
    case CURLOPT_READDATA:
        if (_mode != FAKE_DROP) {
            _fake_store(h, _fake_off[DD_CURL_SLOT_READ_DATA], v);
        }
        return 0;
    default:
        return 48;
    }
}

static size_t _fake_usable(void *h)
{
    (void)h;
    return FAKE_SIZE;
}

static size_t _fake_usable_huge(void *h)
{
    (void)h;
    return SIZE_MAX / 2;
}

static size_t _fake_usable_tiny(void *h)
{
    (void)h;
    return sizeof(void *);
}

static const struct dd_curl_calibrate_api _fake = {
    .easy_init = _fake_init,
    .easy_cleanup = _fake_cleanup,
    .easy_setopt = _fake_setopt,
    .usable_size = _fake_usable,
    .version_num = 0x075000, // 7.80.0
};

// below 7.80 only the read slots are calibrated (G3.4)
static const size_t _fake_off_read[DD_CURL_SLOT_COUNT] = {
    [DD_CURL_SLOT_READ_FUNCTION] = 88,
    [DD_CURL_SLOT_READ_DATA] = 40,
};

// reason NULL: accepted with the offsets `expect` (NULL: _fake_off)
static void _fake_case_ex(const char *name, enum fake_mode mode,
    const struct dd_curl_calibrate_api *api, const char *reason,
    const size_t *expect)
{
    printf("== fake libcurl: %s\n", name);
    _mode = mode;
    _init_calls = 0;
    struct dd_curl_calibration c;
    dd_curl_calibrate(api, &c);
    _print(&c);
    if (!reason) {
        CHECK(c.accepted);
        CHECK(memcmp(c.offsets, expect ? expect : _fake_off,
                  sizeof(_fake_off)) == 0);
        return;
    }
    CHECK(!c.accepted);
    CHECK(strstr(c.reason, reason) != NULL);
    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) { CHECK(c.offsets[i] == 0); }
}

static void _fake_case(const char *name, enum fake_mode mode,
    const struct dd_curl_calibrate_api *api, const char *reason)
{
    _fake_case_ex(name, mode, api, reason, NULL);
}

static void _fake_cases(void)
{
    _fake_case("each value stored once", FAKE_NORMAL, &_fake, NULL);
    _fake_case("write function stored twice (non-unique sentinel)",
        FAKE_DUPLICATE, &_fake, "write_function sentinel found 2 times");
    _fake_case("read data not stored", FAKE_DROP, &_fake,
        "read_data sentinel found 0 times");
    _fake_case("write data moves between rounds", FAKE_MOVE, &_fake,
        "write_data moved between rounds");
    _fake_case("curl_easy_setopt(HEADERFUNCTION) fails", FAKE_SETOPT_FAIL,
        &_fake, "curl_easy_setopt(header_function) failed with 48");
    _fake_case("curl_easy_init fails", FAKE_INIT_FAIL, &_fake,
        "curl_easy_init() failed");

    struct dd_curl_calibrate_api no_usable = _fake;
    no_usable.usable_size = NULL;
    _fake_case("no malloc_usable_size", FAKE_NORMAL, &no_usable,
        "the allocator provides no malloc_usable_size");

    // CURLDEBUG: the handle is not the start of an allocator block, so
    // malloc_usable_size(handle) is undefined; refused before curl_easy_init
    struct dd_curl_calibrate_api memdebug = _fake;
    memdebug.features = 1 << 13; // CURL_VERSION_CURLDEBUG
    _fake_case("libcurl built with CURLDEBUG", FAKE_NORMAL, &memdebug,
        "libcurl is built with memory debugging (CURLDEBUG)");
    if (_init_calls != 0) {
        fprintf(stderr, "  CURLDEBUG: curl_easy_init called %d time(s)\n",
            _init_calls);
        failures++;
    }

    // below 7.80 only the read slots count: an ambiguous write slot does not
    // refuse (G3.4, exception to G5.1)
    struct dd_curl_calibrate_api old = _fake;
    old.version_num = 0x074f00; // 7.79.0
    _fake_case_ex("below 7.80, write function stored twice", FAKE_DUPLICATE,
        &old, NULL, _fake_off_read);
    _fake_case_ex("below 7.80, each value stored once", FAKE_NORMAL, &old, NULL,
        _fake_off_read);
    _fake_case("below 7.80, read data not stored", FAKE_DROP, &old,
        "read_data sentinel found 0 times");

    struct dd_curl_calibrate_api tiny = _fake;
    tiny.usable_size = _fake_usable_tiny;
    _fake_case("malloc_usable_size reports one pointer", FAKE_NORMAL, &tiny,
        "malloc_usable_size reported an implausible");
    // the scan never runs: a block that large is not the handle (a scan
    // would read far past the fake's 512 bytes)
    struct dd_curl_calibrate_api huge = _fake;
    huge.usable_size = _fake_usable_huge;
    _fake_case("malloc_usable_size reports SIZE_MAX / 2", FAKE_NORMAL, &huge,
        "malloc_usable_size reported an implausible");
}

// --- slot interposition (curl_slots.c) --------------------------------------

// "ext/curl's" static functions, another app's, and ours
static size_t _pristine_write(char *p, size_t s, size_t n, void *u)
{
    (void)p, (void)u;
    return (s * n) + 0;
}
static size_t _pristine_header(char *p, size_t s, size_t n, void *u)
{
    (void)p, (void)u;
    return (s * n) + 1 - 1;
}
static size_t _pristine_read(char *p, size_t s, size_t n, void *u)
{
    (void)p, (void)s, (void)n, (void)u;
    return 1;
}
static size_t _foreign_write(char *p, size_t s, size_t n, void *u)
{
    (void)p, (void)s, (void)n, (void)u;
    return 2;
}
static size_t _ours_write(char *p, size_t s, size_t n, void *u)
{
    (void)p, (void)s, (void)n, (void)u;
    return 3;
}
static size_t _ours_header(char *p, size_t s, size_t n, void *u)
{
    (void)p, (void)s, (void)n, (void)u;
    return 4;
}
static size_t _ours_read(char *p, size_t s, size_t n, void *u)
{
    (void)p, (void)s, (void)n, (void)u;
    return 5;
}
static const dd_curl_slot_fn _ours[DD_CURL_ISLOT_COUNT] = {
    _ours_write, _ours_header, _ours_read};
static const dd_curl_slot_fn _pristine[DD_CURL_ISLOT_COUNT] = {
    _pristine_write, _pristine_header, _pristine_read};

static const int _fn_opt[DD_CURL_ISLOT_COUNT] = {
    CURLOPT_WRITEFUNCTION, CURLOPT_HEADERFUNCTION, CURLOPT_READFUNCTION};
static const int _data_opt[DD_CURL_ISLOT_COUNT] = {
    CURLOPT_WRITEDATA, CURLOPT_HEADERDATA, CURLOPT_READDATA};

// a handle as ext/curl sets it up: its functions, and its php_curl * as data
static void _ext_curl_setup(
    const struct dd_curl_calibrate_api *api, void *h, void *owner)
{
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        api->easy_setopt(h, _fn_opt[i], _pristine[i]);
        api->easy_setopt(h, _data_opt[i], owner);
    }
}

static void _slots_for(struct dd_curl_slots *s,
    const struct dd_curl_calibrate_api *api, struct dd_curl_calibration *c)
{
    dd_curl_calibrate(api, c);
    dd_curl_slots_init(s, c, api->version_num, api->easy_setopt, _ours);
}

static bool _states_are(const struct dd_curl_slots *s, const void *h,
    enum dd_curl_slot_state w, enum dd_curl_slot_state hd,
    enum dd_curl_slot_state r)
{
    return dd_curl_slots_state(s, h, DD_CURL_ISLOT_WRITE) == w &&
           dd_curl_slots_state(s, h, DD_CURL_ISLOT_HEADER) == hd &&
           dd_curl_slots_state(s, h, DD_CURL_ISLOT_READ) == r;
}

static void _case_slots_lifecycle(const struct dd_curl_calibrate_api *api)
{
    puts("  - learn the pristine functions, install ours, restore them");
    struct dd_curl_calibration c;
    struct dd_curl_slots s;
    _slots_for(&s, api, &c);
    CHECK(c.accepted);
    static char owner;
    void *h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        CHECK(dd_curl_slots_pristine(&s, (enum dd_curl_islot)i) == NULL);
    }
    CHECK(_states_are(
        &s, h, DD_CURL_SLOT_OTHER, DD_CURL_SLOT_OTHER, DD_CURL_SLOT_OTHER));
    char reason[DD_CURL_SLOTS_REASON_SIZE];
    dd_curl_slots_normalise(&s, h);
    CHECK(dd_curl_slots_guard(&s, h, &owner, reason));
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        CHECK(
            dd_curl_slots_pristine(&s, (enum dd_curl_islot)i) == _pristine[i]);
    }
    CHECK(dd_curl_slots_install(&s, h));
    CHECK(_states_are(
        &s, h, DD_CURL_SLOT_OURS, DD_CURL_SLOT_OURS, DD_CURL_SLOT_OURS));
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        CHECK(dd_curl_slots_data(&s, h, (enum dd_curl_islot)i) == &owner);
    }
    dd_curl_slots_restore(&s, h);
    CHECK(_states_are(&s, h, DD_CURL_SLOT_PRISTINE, DD_CURL_SLOT_PRISTINE,
        DD_CURL_SLOT_PRISTINE));
    api->easy_cleanup(h);
}

static void _case_slots_guard_data(const struct dd_curl_calibrate_api *api)
{
    puts("  - guard: a data pointer that is not the handle's own: not "
         "interposed");
    struct dd_curl_calibration c;
    struct dd_curl_slots s;
    _slots_for(&s, api, &c);
    static char owner;
    static char other;
    void *h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    api->easy_setopt(h, CURLOPT_READDATA, (void *)&other);
    char reason[DD_CURL_SLOTS_REASON_SIZE];
    dd_curl_slots_normalise(&s, h);
    bool ok = dd_curl_slots_guard(&s, h, &owner, reason);
    printf("    reason=\"%s\"\n", ok ? "" : reason);
    CHECK(!ok);
    CHECK(strstr(reason, "read data") != NULL);
    // the caller installs nothing; the guard changed nothing
    CHECK(dd_curl_slots_state(&s, h, DD_CURL_ISLOT_READ) != DD_CURL_SLOT_OURS);
    CHECK(dd_curl_slots_data(&s, h, DD_CURL_ISLOT_READ) == &other);
    api->easy_cleanup(h);
}

static void _case_slots_guard_foreign(const struct dd_curl_calibrate_api *api)
{
    puts("  - guard: a function that differs from the pristine one learnt: "
         "not interposed");
    struct dd_curl_calibration c;
    struct dd_curl_slots s;
    _slots_for(&s, api, &c);
    static char owner;
    char reason[DD_CURL_SLOTS_REASON_SIZE];
    void *h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    CHECK(dd_curl_slots_guard(&s, h, &owner, reason)); // learns
    api->easy_cleanup(h);

    h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    api->easy_setopt(h, CURLOPT_WRITEFUNCTION, _foreign_write);
    dd_curl_slots_normalise(&s, h);
    bool ok = dd_curl_slots_guard(&s, h, &owner, reason);
    printf("    reason=\"%s\"\n", ok ? "" : reason);
    CHECK(!ok);
    CHECK(strstr(reason, "write function") != NULL);
    CHECK(dd_curl_slots_pristine(&s, DD_CURL_ISLOT_WRITE) == _pristine_write);
    CHECK(
        dd_curl_slots_state(&s, h, DD_CURL_ISLOT_WRITE) == DD_CURL_SLOT_OTHER);
    api->easy_cleanup(h);

    puts("  - guard: nothing learnt from a slot without a function");
    _slots_for(&s, api, &c);
    h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    api->easy_setopt(h, CURLOPT_HEADERFUNCTION, (dd_curl_slot_fn)NULL);
    if (dd_curl_slots_state(&s, h, DD_CURL_ISLOT_HEADER) ==
        DD_CURL_SLOT_UNUSED) {
        api->easy_cleanup(h);
        return; // below 7.80
    }
    ok = dd_curl_slots_guard(&s, h, &owner, reason);
    CHECK(!ok);
    CHECK(dd_curl_slots_pristine(&s, DD_CURL_ISLOT_HEADER) == NULL);
    api->easy_cleanup(h);
}

static void _case_slots_guard_no_learning_on_failure(
    const struct dd_curl_calibrate_api *api)
{
    puts("  - guard: nothing is learnt from a handle the guard rejects");
    struct dd_curl_calibration c;
    struct dd_curl_slots s;
    _slots_for(&s, api, &c);
    static char owner;
    static char other;
    char reason[DD_CURL_SLOTS_REASON_SIZE];
    // the first handle seen holds another extension's write function and
    // data that is not its own
    void *h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    bool old =
        dd_curl_slots_state(&s, h, DD_CURL_ISLOT_WRITE) == DD_CURL_SLOT_UNUSED;
    int foreign_slot = old ? DD_CURL_ISLOT_READ : DD_CURL_ISLOT_WRITE;
    api->easy_setopt(h, _fn_opt[foreign_slot], _foreign_write);
    api->easy_setopt(h, _data_opt[foreign_slot], (void *)&other);
    CHECK(!dd_curl_slots_guard(&s, h, &owner, reason));
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        CHECK(dd_curl_slots_pristine(&s, (enum dd_curl_islot)i) == NULL);
    }
    api->easy_cleanup(h);

    // ext/curl's functions, but one slot's data is not the handle's own
    h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    api->easy_setopt(h, CURLOPT_READDATA, (void *)&other);
    CHECK(!dd_curl_slots_guard(&s, h, &owner, reason));
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        CHECK(dd_curl_slots_pristine(&s, (enum dd_curl_islot)i) == NULL);
    }
    api->easy_cleanup(h);

    // an ext/curl handle afterwards passes, and its functions are learnt
    h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    bool ok = dd_curl_slots_guard(&s, h, &owner, reason);
    printf("    reason=\"%s\"\n", ok ? "" : reason);
    CHECK(ok);
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        if (dd_curl_slots_state(&s, h, (enum dd_curl_islot)i) !=
            DD_CURL_SLOT_UNUSED) {
            CHECK(dd_curl_slots_pristine(&s, (enum dd_curl_islot)i) ==
                  _pristine[i]);
        }
    }
    api->easy_cleanup(h);
}

static void _case_slots_copy(const struct dd_curl_calibrate_api *api)
{
    puts("  - a copy made while ours are installed is normalised, then "
         "passes the guard");
    struct dd_curl_calibration c;
    struct dd_curl_slots s;
    _slots_for(&s, api, &c);
    static char owner;
    static char copy_owner;
    char reason[DD_CURL_SLOTS_REASON_SIZE];
    void *h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    CHECK(dd_curl_slots_guard(&s, h, &owner, reason));
    CHECK(dd_curl_slots_install(&s, h));
    api->easy_cleanup(h);

    // what curl_easy_duphandle() and ext/curl leave on the copy: our
    // functions, the copy's php_curl * as data
    void *copy = api->easy_init();
    CHECK(copy);
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        api->easy_setopt(copy, _fn_opt[i], _ours[i]);
        api->easy_setopt(copy, _data_opt[i], (void *)&copy_owner);
    }
    bool old = dd_curl_slots_state(&s, copy, DD_CURL_ISLOT_WRITE) ==
               DD_CURL_SLOT_UNUSED;
    dd_curl_slots_normalise(&s, copy);
    CHECK(
        _states_are(&s, copy, old ? DD_CURL_SLOT_UNUSED : DD_CURL_SLOT_PRISTINE,
            old ? DD_CURL_SLOT_UNUSED : DD_CURL_SLOT_PRISTINE,
            DD_CURL_SLOT_PRISTINE));
    CHECK(dd_curl_slots_guard(&s, copy, &copy_owner, reason));
    api->easy_cleanup(copy);
}

static void _case_slots_restore_overwritten(
    const struct dd_curl_calibrate_api *api)
{
    puts("  - restore leaves a slot overwritten since alone");
    struct dd_curl_calibration c;
    struct dd_curl_slots s;
    _slots_for(&s, api, &c);
    static char owner;
    char reason[DD_CURL_SLOTS_REASON_SIZE];
    void *h = api->easy_init();
    CHECK(h);
    _ext_curl_setup(api, h, &owner);
    CHECK(dd_curl_slots_guard(&s, h, &owner, reason));
    CHECK(dd_curl_slots_install(&s, h));
    // the read slot is reset (curl_reset() sets the pristine one); another
    // gets something else
    api->easy_setopt(h, CURLOPT_READFUNCTION, _pristine_read);
    bool old =
        dd_curl_slots_state(&s, h, DD_CURL_ISLOT_WRITE) == DD_CURL_SLOT_UNUSED;
    if (!old) {
        api->easy_setopt(h, CURLOPT_WRITEFUNCTION, _foreign_write);
    }
    dd_curl_slots_restore(&s, h);
    if (!old) {
        CHECK(_states_are(&s, h, DD_CURL_SLOT_OTHER, DD_CURL_SLOT_PRISTINE,
            DD_CURL_SLOT_PRISTINE));
        void *w;
        memcpy(
            &w, (char *)h + c.offsets[DD_CURL_SLOT_WRITE_FUNCTION], sizeof(w));
        CHECK(w == (void *)_foreign_write);
    } else {
        CHECK(dd_curl_slots_state(&s, h, DD_CURL_ISLOT_READ) ==
              DD_CURL_SLOT_PRISTINE);
    }
    api->easy_cleanup(h);
}

static void _case_slots_below_780(void)
{
    puts("  - below 7.80 only the read slot is touched");
    struct dd_curl_calibrate_api old = _fake;
    old.version_num = 0x074f00;
    _mode = FAKE_NORMAL;
    struct dd_curl_calibration c;
    struct dd_curl_slots s;
    _slots_for(&s, &old, &c);
    CHECK(c.accepted);
    static char owner;
    char reason[DD_CURL_SLOTS_REASON_SIZE];
    void *h = old.easy_init();
    CHECK(h);
    // the write and header slots hold anything: they are not looked at
    old.easy_setopt(h, CURLOPT_WRITEFUNCTION, _foreign_write);
    old.easy_setopt(h, CURLOPT_HEADERDATA, NULL);
    old.easy_setopt(h, CURLOPT_READFUNCTION, _pristine_read);
    old.easy_setopt(h, CURLOPT_READDATA, (void *)&owner);
    CHECK(_states_are(
        &s, h, DD_CURL_SLOT_UNUSED, DD_CURL_SLOT_UNUSED, DD_CURL_SLOT_OTHER));
    CHECK(dd_curl_slots_guard(&s, h, &owner, reason));
    CHECK(dd_curl_slots_install(&s, h));
    CHECK(_states_are(
        &s, h, DD_CURL_SLOT_UNUSED, DD_CURL_SLOT_UNUSED, DD_CURL_SLOT_OURS));
    void *w;
    memcpy(&w, (char *)h + _fake_off[DD_CURL_SLOT_WRITE_FUNCTION], sizeof(w));
    CHECK(w == (void *)_foreign_write);
    dd_curl_slots_restore(&s, h);
    CHECK(dd_curl_slots_state(&s, h, DD_CURL_ISLOT_READ) ==
          DD_CURL_SLOT_PRISTINE);
    old.easy_cleanup(h);
}

static void _case_slots_install_refused(void)
{
    puts("  - libcurl refuses one of our functions: the slots are as before");
    _mode = FAKE_NORMAL;
    struct dd_curl_calibration c;
    struct dd_curl_slots s;
    _slots_for(&s, &_fake, &c);
    CHECK(c.accepted);
    static char owner;
    char reason[DD_CURL_SLOTS_REASON_SIZE];
    void *h = _fake.easy_init();
    CHECK(h);
    _ext_curl_setup(&_fake, h, &owner);
    CHECK(dd_curl_slots_guard(&s, h, &owner, reason));
    _mode = FAKE_SETOPT_FAIL; // HEADERFUNCTION refused
    CHECK(!dd_curl_slots_install(&s, h));
    _mode = FAKE_NORMAL;
    CHECK(_states_are(&s, h, DD_CURL_SLOT_PRISTINE, DD_CURL_SLOT_PRISTINE,
        DD_CURL_SLOT_PRISTINE));
    _fake.easy_cleanup(h);
}

static void _case_slots_normalise_refused(void)
{
    puts("  - libcurl refuses to put a pristine function back: normalising "
         "says so");
    _mode = FAKE_NORMAL;
    struct dd_curl_calibration c;
    struct dd_curl_slots s;
    _slots_for(&s, &_fake, &c);
    CHECK(c.accepted);
    static char owner;
    char reason[DD_CURL_SLOTS_REASON_SIZE];
    void *h = _fake.easy_init();
    CHECK(h);
    _ext_curl_setup(&_fake, h, &owner);
    CHECK(dd_curl_slots_guard(&s, h, &owner, reason));
    CHECK(dd_curl_slots_install(&s, h));
    _mode = FAKE_SETOPT_FAIL; // HEADERFUNCTION refused
    CHECK(!dd_curl_slots_normalise(&s, h));
    _mode = FAKE_NORMAL;
    CHECK(_states_are(&s, h, DD_CURL_SLOT_PRISTINE, DD_CURL_SLOT_OURS,
        DD_CURL_SLOT_PRISTINE));
    CHECK(dd_curl_slots_normalise(&s, h));
    CHECK(_states_are(&s, h, DD_CURL_SLOT_PRISTINE, DD_CURL_SLOT_PRISTINE,
        DD_CURL_SLOT_PRISTINE));
    // nothing of ours left: nothing to put back
    CHECK(dd_curl_slots_normalise(&s, h));
    _fake.easy_cleanup(h);
}

static void _slots_cases(
    const char *name, const struct dd_curl_calibrate_api *api)
{
    printf("== slots, %s libcurl\n", name);
    _mode = FAKE_NORMAL;
    _case_slots_lifecycle(api);
    _case_slots_guard_data(api);
    _case_slots_guard_foreign(api);
    _case_slots_guard_no_learning_on_failure(api);
    _case_slots_copy(api);
    _case_slots_restore_overwritten(api);
}

static int _load_real(void)
{
    void *lib = dlopen("libcurl.so.4", RTLD_NOW | RTLD_LOCAL);
    if (!lib) {
        fprintf(stderr, "dlopen(libcurl.so.4): %s\n", dlerror());
        return 0;
    }
    _real.easy_init = (dd_curl_easy * (*)(void)) dlsym(lib, "curl_easy_init");
    _real.easy_cleanup =
        (void (*)(dd_curl_easy *))dlsym(lib, "curl_easy_cleanup");
    _real.easy_setopt = (dd_curl_code (*)(
        dd_curl_easy *, dd_curl_option, ...))dlsym(lib, "curl_easy_setopt");
    _real.usable_size =
        (usable_size_fn)dlsym(RTLD_DEFAULT, "malloc_usable_size");
    _version_info = (dd_curl_version_info_data * (*)(dd_curl_version))
        dlsym(lib, "curl_version_info");
    if (!_real.easy_init || !_real.easy_cleanup || !_real.easy_setopt ||
        !_real.usable_size || !_version_info) {
        fprintf(stderr, "missing libcurl function or malloc_usable_size\n");
        return 0;
    }
    const dd_curl_version_info_data *vi = _version_info(DD_CURLVERSION_FIRST);
    _real.version_num = vi->version_num;
    _real.features = vi->features;
    Dl_info dli;
    const char *path = "?";
    if (dladdr((void *)_real.easy_init, &dli) && dli.dli_fname) {
        path = dli.dli_fname;
    }
    printf("libcurl %s (%#x) at %s\n", vi->version, vi->version_num, path);
    return 1;
}

int main(void)
{
    if (!_load_real()) {
        return 2;
    }
    _case_real_unique();
    _case_real_stable();
    _case_real_fresh_handle();
    _fake_cases();
    _slots_cases("real", &_real);
    _slots_cases("fake", &_fake);
    puts("== slots, fake libcurl only");
    _case_slots_below_780();
    _case_slots_install_refused();
    _case_slots_normalise_refused();
    if (failures) {
        printf("%d case(s) failed\n", failures);
        return 1;
    }
    puts("all cases passed");
    return 0;
}
