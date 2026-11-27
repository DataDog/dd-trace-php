// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
// Zend/zend_weakrefs.h must come before ext/compatibility.h (included by
// attributes.h), which on PHP 8.0 renames its zend_weakrefs_hash_* functions
// to the tracer's fallbacks (as in tracer/handlers_curl.c).
#include <php.h>
#if PHP_VERSION_ID >= 80000
#    include <Zend/zend_weakrefs.h>
#endif

#include "curl_observe.h"

#include <stddef.h>
#include <stdint.h>
#include <string.h>
#if defined(TESTING) && defined(__GLIBC__)
#    include <malloc.h>
#endif

#include <ext/compatibility.h>

#include "../configuration.h"
#include "../ddappsec.h"
#include "../logging.h"
#include "../php_helpers.h"
#include "../php_objects.h"
#include "curl_eval.h"
#include "curl_handle.h"
#include "curl_hooks.h"
#include "curl_linkage.h"
#include "curl_slots.h"

// libcurl option numbers and values (curl.h; stable public ABI):
// CURLOPT_PREREQFUNCTION = CURLOPTTYPE_FUNCTIONPOINT + 312 and
// CURLOPT_PREREQDATA = CURLOPTTYPE_CBPOINT + 313 (both since 7.80.0);
// CURLE_OK = 0; CURL_PREREQFUNC_OK = 0, CURL_PREREQFUNC_ABORT = 1.
enum {
    DD_CURLOPT_PREREQFUNCTION = 20312,
    DD_CURLOPT_PREREQDATA = 10313,
    DD_CURLE_OK = 0,
    DD_CURLINFO_EFFECTIVE_URL = 0x100001, // CURLINFO_STRING + 1
    DD_CURL_PREREQFUNC_OK = 0,
    DD_CURL_PREREQFUNC_ABORT = 1,
};
// CURL_READFUNC_ABORT
#define DD_CURL_READFUNC_ABORT ((size_t)0x10000000)

// DD_APPSEC_MAX_BODY_BUFF_SIZE's default (configuration_table.h)
enum { DD_DEFAULT_MAX_BODY_SIZE = 524288 };

// curl_prereq_callback
typedef int (*dd_curl_prereq_fn)(void *clientp, char *conn_primary_ip,
    char *conn_local_ip, int conn_primary_port, int conn_local_port);

// Something no pointer can be: not canonical on x86_64 or aarch64. Checked
// on every state found through one of our tables before it is used.
#define DD_CURL_XFER_MAGIC UINT64_C(0xDDC0DE5AFEC0FFEE)

// The last (or current) transfer of a handle.
struct dd_curl_xfer {
    uint64_t magic;
    dd_curl_easy *nullable cp; // the handle our functions are installed on
    uintptr_t multi;           // multi it was added to; 0 for curl_exec
    // the multi libcurl has the handle in, whether its transfer is observed
    // or not (and also once it is done, until it is removed); 0 if none
    uintptr_t in_multi;
    // the multi whose next curl_multi_exec starts this handle's transfer
    // (G4.17): it was added, and no transfer was begun for it since; 0 if none
    uintptr_t pending_multi;
    uint64_t pending_seq; // order of the adds: the transfers start in it
    bool installed;
    bool prereq_installed; // our PREREQFUNCTION too (libcurl 7.80+)
    // our PREREQDATA while prereq_installed: a number never reused, not the
    // address of this struct, which a later state may take over
    uintptr_t prereq_id;
    bool in_exec;
    bool observed;               // our functions were installed for it
    const char *nullable reason; // why it was not observed
    // our functions are in the callback slots of cp, and this transfer is
    // registered in _slot_xfers under php_curl, the slots' data (G5.2)
    bool slots_installed;
    void *nullable php_curl;
    // ours may be in the handle's slots (and our PREREQFUNCTION): they were
    // installed on it, or it was copied from a handle that may hold them. The
    // next transfer start normalises the handle then, observed or not.
    bool may_hold_ours;
    // calls of our slot functions that found this transfer, and the bytes
    // they passed on (testing: curl_downstream_dump(); only counted with
    // DD_APPSEC_TESTING, see _slot_counting)
    uint64_t slot_calls[DD_CURL_ISLOT_COUNT];
    uint64_t slot_bytes[DD_CURL_ISLOT_COUNT];
    // what the interposed header and write functions and our PREREQ calls
    // collected: the head of the current hop and the last hop's body
    struct dd_curl_collect coll;
    struct dd_curl_eval eval;
};

struct dd_curl_hstate {
    struct dd_curl_xfer xfer; // first member: see _hstate_of
    struct dd_curl_opts opts;
#if PHP_VERSION_ID >= 80000
    zend_object *obj; // the CurlHandle (this state goes away with it)
#else
    // liveness of the resource: its entry in EG(regular_list)
    zend_ulong res_handle;
    zend_resource *res;
    void *php_curl;
#endif
};

// PHP curl handle -> struct dd_curl_hstate *. On PHP 8 a weak map keyed by
// the CurlHandle object: the entry goes away when the object is freed, which
// is after ext/curl's curl_easy_cleanup(). On PHP 7, keyed by resource id;
// entries of dead resources are found by checking EG(regular_list).
static THREAD_LOCAL_ON_ZTS HashTable *_hstates;
// CURL * -> struct dd_curl_xfer * for the transfers with our functions
// installed.
static THREAD_LOCAL_ON_ZTS HashTable *_installed;
// prereq_id -> struct dd_curl_xfer *, for the transfers with our PREREQFUNCTION
// installed. Its callback gets no CURL *, only PREREQDATA (the id), which it
// only resolves here: a copy of a handle (libcurl's own, or one whose CURL *
// we could not validate) may still carry the PREREQDATA of a state that is
// gone. Ids are never reused, so it cannot resolve to another state.
static THREAD_LOCAL_ON_ZTS HashTable *_prereqs;
static THREAD_LOCAL_ON_ZTS uintptr_t _prereq_last_id;
// php_curl * -> struct dd_curl_xfer *, for the transfers with our functions
// in their callback slots. Those functions get the slot's data, the
// php_curl * ext/curl set there, and use it only as a key into this table
// (G5.4): a copy, a pushed handle or a DoH handle may carry our functions
// with data that is not registered.
static THREAD_LOCAL_ON_ZTS HashTable *_slot_xfers;
// libcurl's slots and the functions of ext/curl found in them: one for the
// process (set up at startup, which the engine serialises: it runs once per
// process from the zend_startup hook, before any thread; ext/curl's functions
// are the same in every thread)
static struct dd_curl_slots _slots;
static bool _slots_ready;
// DD_APPSEC_TESTING: our slot functions count the calls and bytes of the
// transfer they belong to (curl_downstream_dump()). It gates only those
// counters: the write and header functions always look up their transfer,
// to feed its collector, and the read function, for its gate (G6.7).
static bool _slot_counting;
static THREAD_LOCAL_ON_ZTS bool _req_active;
// the number of handles with pending_multi set: the exec of a multi need not
// scan the handles when it is 0
static THREAD_LOCAL_ON_ZTS uint32_t _pending_count;
static THREAD_LOCAL_ON_ZTS uint64_t _pending_last_seq;
// Testing (curl_downstream_collect_all()): transfers are observed and their
// bodies kept even when no WAF call needs them, for curl_downstream_dump()
static THREAD_LOCAL_ON_ZTS bool _collect_all;
#ifdef TESTING
// curl_downstream_coll_refuse(): the collector's allocations are refused, as
// at the memory limit
static THREAD_LOCAL_ON_ZTS bool _coll_refuse;
// curl_downstream_install_refuse(): libcurl refuses our functions for every
// handle, as when it cannot be interposed
static THREAD_LOCAL_ON_ZTS bool _install_refuse;
// curl_downstream_coll_refusals(): collector allocations refused so far
static THREAD_LOCAL_ON_ZTS zend_long _coll_refusals;
#endif
#if PHP_VERSION_ID >= 80000
// CurlMultiHandle -> its key (a number never reused in the request). A weak
// map: the transfers of a multi handle freed without curl_multi_close() are
// ended when the entry goes, and a new multi handle at the same address gets
// a new key.
static THREAD_LOCAL_ON_ZTS HashTable *_multis;
static THREAD_LOCAL_ON_ZTS uintptr_t _multi_last_key;
#else
static THREAD_LOCAL_ON_ZTS uint32_t _sweep_at;
static int _le_curl_multi;
#endif

static bool _tracking(void);
static size_t _body_cap(void);
static void *_coll_realloc(void *ptr, size_t size);
static void _coll_free(void *ptr);
// the collectors' allocator: their growth counts towards the memory limit,
// and is refused rather than raising a fatal error (G9.5)
static const struct dd_curl_alloc _coll_alloc = {_coll_realloc, _coll_free};
static struct dd_curl_hstate *_hstate_of(struct dd_curl_xfer *x);
static void _hstate_dtor(zval *zv);
static void _hstate_free(struct dd_curl_hstate *hs);
static bool _hstate_alive(struct dd_curl_hstate *hs);
#if PHP_VERSION_ID < 80000
static void _sweep_dead(void);
#endif
#if PHP_VERSION_ID >= 80000
static void _multi_dtor(zval *zv);
#endif
static void _release(struct dd_curl_hstate *hs);
static void _release_with(struct dd_curl_hstate *hs, bool touch_libcurl);
static bool _multi_alive(uintptr_t multi);
static void _multi_emptied(uintptr_t multi);
static void _set_pending(struct dd_curl_hstate *hs, uintptr_t multi);
static struct dd_curl_hstate *_find_pending(uintptr_t multi);
static void _log_skip(const char *reason);
static void _normalise(
    struct dd_curl_xfer *x, const struct dd_curl_opts *opts, dd_curl_easy *cp);
static bool _install(struct dd_curl_xfer *x, dd_curl_easy *cp, void *php_curl,
    char why[DD_CURL_SLOTS_REASON_SIZE]);
static bool _install_slots(struct dd_curl_xfer *x, dd_curl_easy *cp,
    void *php_curl, char why[DD_CURL_SLOTS_REASON_SIZE]);
static void _restore_slots(struct dd_curl_xfer *x);
static void _unregister_slots(struct dd_curl_xfer *x);
static size_t _write_cb(char *ptr, size_t size, size_t nmemb, void *userdata);
static size_t _header_cb(char *ptr, size_t size, size_t nmemb, void *userdata);
static size_t _read_cb(char *ptr, size_t size, size_t nmemb, void *userdata);
static size_t _gate_read(struct dd_curl_xfer *x, char *ptr, size_t size,
    size_t nmemb, void *userdata);
static size_t _slot_taken(
    enum dd_curl_islot slot, size_t ret, size_t size, size_t nmemb);
static void _set_prereq(struct dd_curl_xfer *x);
static void _forget_prereq(struct dd_curl_xfer *x);
static void _remove_prereq(dd_curl_easy *cp);
static void _settle(struct dd_curl_hstate *hs);
static void _unregister(struct dd_curl_xfer *x);
// NOLINTBEGIN(bugprone-easily-swappable-parameters,readability-non-const-parameter)
static int _prereq_cb(void *clientp, char *conn_primary_ip, char *conn_local_ip,
    int conn_primary_port, int conn_local_port);
// NOLINTEND(bugprone-easily-swappable-parameters,readability-non-const-parameter)

static const char *_handle_status_reason(dd_curl_handle_status st);

#ifdef TESTING
static void _register_testing_objects(void);
#endif

void dd_curl_observe_minit(void)
{
    dd_curl_eval_minit();
#ifdef TESTING
    _register_testing_objects();
#endif
}

bool dd_curl_observe_startup(void)
{
    if (!dd_curl_downstream_enabled()) {
        return false;
    }
#if PHP_VERSION_ID >= 80000
    // the handle type was found: dd_curl_downstream_enabled()
#else
    _le_curl_multi = zend_fetch_list_dtor_id("curl_multi");
    if (!_le_curl_multi) {
        return false;
    }
#endif
    dd_curl_opts_startup();
    const struct dd_curl_functions *funcs = dd_curl_linkage_funcs();
    if (!funcs) {
        return false; // dd_curl_downstream_enabled() ruled it out
    }
    if (!_slots_ready) {
        // once: the ext/curl functions learnt are kept (a second startup
        // must not forget them while handles may still hold ours)
        static const dd_curl_slot_fn ours[DD_CURL_ISLOT_COUNT] = {
            [DD_CURL_ISLOT_WRITE] = _write_cb,
            [DD_CURL_ISLOT_HEADER] = _header_cb,
            [DD_CURL_ISLOT_READ] = _read_cb,
        };
        dd_curl_slots_init(&_slots, dd_curl_calibration_get(),
            dd_curl_linkage_version_num(), funcs->curl_easy_setopt, ours);
        _slots_ready = true;
    }
    _slot_counting = get_global_DD_APPSEC_TESTING();
    return true;
}

void dd_curl_observe_rinit(void)
{
    _req_active = true;
    _pending_count = 0;
    dd_curl_eval_rinit();
}

void dd_curl_observe_rshutdown(void)
{
    _req_active = false;
#if PHP_VERSION_ID < 80000
    if (_hstates) {
        _sweep_dead();
    }
#endif
    if (_installed) {
        struct dd_curl_xfer *x;
        ZEND_HASH_FOREACH_PTR(_installed, x)
        {
            if (_hstate_alive(_hstate_of(x))) {
                _restore_slots(x);
                if (x->prereq_installed &&
                    !_hstate_of(x)->opts.prereqfunction) {
                    _remove_prereq(x->cp);
                }
            }
            x->prereq_installed = false;
            x->slots_installed = false;
            x->php_curl = NULL;
            x->installed = false;
            x->cp = NULL;
        }
        ZEND_HASH_FOREACH_END();
        zend_hash_destroy(_installed);
        FREE_HASHTABLE(_installed);
        _installed = NULL;
    }
    if (_slot_xfers) {
        zend_hash_destroy(_slot_xfers);
        FREE_HASHTABLE(_slot_xfers);
        _slot_xfers = NULL;
    }
    if (_prereqs) {
        zend_hash_destroy(_prereqs);
        FREE_HASHTABLE(_prereqs);
        _prereqs = NULL;
    }
#if PHP_VERSION_ID >= 80000
    if (_multis) {
        // _req_active is false: the entries' destructor does nothing
        zend_ulong key;
        ZEND_HASH_FOREACH_NUM_KEY(_multis, key)
        {
            zend_weakrefs_hash_del(_multis, zend_weakref_key_to_object(key));
        }
        ZEND_HASH_FOREACH_END();
        zend_hash_destroy(_multis);
        FREE_HASHTABLE(_multis);
        _multis = NULL;
    }
    _multi_last_key = 0;
#endif
    if (_hstates) {
#if PHP_VERSION_ID >= 80000
        zend_ulong key;
        ZEND_HASH_FOREACH_NUM_KEY(_hstates, key)
        {
            zend_weakrefs_hash_del(_hstates, zend_weakref_key_to_object(key));
        }
        ZEND_HASH_FOREACH_END();
#endif
        zend_hash_destroy(_hstates);
        FREE_HASHTABLE(_hstates);
        _hstates = NULL;
    }
    dd_curl_eval_rshutdown();
}

struct dd_curl_hstate *nullable dd_curl_hstate_find(zval *nonnull zch)
{
    if (!_hstates) {
        return NULL;
    }
    ZVAL_DEREF(zch);
#if PHP_VERSION_ID >= 80000
    if (Z_TYPE_P(zch) != IS_OBJECT) {
        return NULL;
    }
    return zend_hash_index_find_ptr(
        _hstates, zend_object_to_weakref_key(Z_OBJ_P(zch)));
#else
    if (Z_TYPE_P(zch) != IS_RESOURCE) {
        return NULL;
    }
    struct dd_curl_hstate *hs =
        zend_hash_index_find_ptr(_hstates, (zend_ulong)Z_RES_HANDLE_P(zch));
    if (hs && (hs->res != Z_RES_P(zch) || !_hstate_alive(hs))) {
        zend_hash_index_del(_hstates, hs->res_handle);
        return NULL;
    }
    return hs;
#endif
}

struct dd_curl_hstate *nullable dd_curl_hstate_create(zval *nonnull zch)
{
    if (!_tracking()) {
        return NULL;
    }
    ZVAL_DEREF(zch);
    // Its CURL * is validated when a transfer starts (dd_curl_xfer_begin):
    // most handles are created while AppSec is not active (remote config)
    // or never make an evaluated transfer.
    if (!dd_curl_handle_is_curl(zch)) {
        return NULL;
    }
    if (!_hstates) {
        ALLOC_HASHTABLE(_hstates);
        zend_hash_init(_hstates, 8, NULL, _hstate_dtor, 0);
#if PHP_VERSION_ID < 80000
        _sweep_at = 32; // NOLINT(readability-magic-numbers)
#endif
    }

    struct dd_curl_hstate *hs = ecalloc(1, sizeof(*hs));
    hs->xfer.magic = DD_CURL_XFER_MAGIC;
    dd_curl_collect_init(&hs->xfer.coll, &_coll_alloc, 0);
    dd_curl_eval_init(&hs->xfer.eval);
    dd_curl_opts_init(&hs->opts);

#if PHP_VERSION_ID >= 80000
    zend_object *obj = Z_OBJ_P(zch);
    hs->obj = obj;
    if (zend_hash_index_exists(_hstates, zend_object_to_weakref_key(obj))) {
        zend_weakrefs_hash_del(_hstates, obj);
    }
    zend_weakrefs_hash_add_ptr(_hstates, obj, hs);
#else
    if (zend_hash_num_elements(_hstates) >= _sweep_at) {
        _sweep_dead();
        _sweep_at = MAX(32, 2 * zend_hash_num_elements(_hstates));
    }
    hs->res = Z_RES_P(zch);
    hs->res_handle = (zend_ulong)Z_RES_HANDLE_P(zch);
    hs->php_curl = Z_RES_VAL_P(zch);
    zend_hash_index_update_ptr(_hstates, hs->res_handle, hs);
#endif
    return hs;
}

struct dd_curl_opts *nonnull dd_curl_hstate_opts(
    struct dd_curl_hstate *nonnull hs)
{
    return &hs->opts;
}

uintptr_t dd_curl_multi_key(zval *nonnull zmh, bool create)
{
    ZVAL_DEREF(zmh);
#if PHP_VERSION_ID >= 80000
    // not the object's address: that is reused once the object is freed
    if (Z_TYPE_P(zmh) != IS_OBJECT) {
        return 0;
    }
    zend_object *obj = Z_OBJ_P(zmh);
    if (_multis) {
        zval *zkey =
            zend_hash_index_find(_multis, zend_object_to_weakref_key(obj));
        if (zkey) {
            return (uintptr_t)Z_PTR_P(zkey);
        }
    }
    if (!create || !_tracking()) {
        return 0;
    }
    if (!_multis) {
        ALLOC_HASHTABLE(_multis);
        zend_hash_init(_multis, 8, NULL, _multi_dtor, 0);
    }
    uintptr_t key = ++_multi_last_key;
    zend_weakrefs_hash_add_ptr(_multis, obj, (void *)key);
    return key;
#else
    (void)create;
    if (Z_TYPE_P(zmh) == IS_RESOURCE) {
        // resource ids can be 0; keep 0 for "no multi"
        return (uintptr_t)Z_RES_HANDLE_P(zmh) + 1;
    }
#endif
    return 0;
}

struct dd_curl_hstate *nullable dd_curl_xfer_begin(
    zval *nonnull zch, uintptr_t multi)
{
    dd_curl_eval_hook_enter();
    if (!_tracking()) {
        return NULL;
    }
    struct dd_curl_hstate *hs = dd_curl_hstate_find(zch);
    if (!hs) {
        return NULL;
    }
    struct dd_curl_xfer *x = &hs->xfer;
    if (x->in_exec) {
        // curl_exec() of this handle from one of its own callbacks
        return NULL;
    }
    if (x->in_multi && !_multi_alive(x->in_multi)) {
        x->in_multi = 0;
    }
    if (x->in_multi && x->in_multi != multi) {
        // Still in a multi (its transfer observed or not, e.g. added before
        // AppSec was active, or reported done): libcurl refuses to add it to
        // a multi again (CURLM_ADDED_ALREADY) and to curl_exec() it
        // (CURLE_FAILED_INIT). A transfer observed goes on, still observed.
        // (Starting the transfer of a pending handle is not that: its multi
        // is the one it is being started in.)
        return NULL;
    }
    if (x->installed) {
        // its multi is gone (PHP 7: freed without curl_multi_close()): no
        // WAF call for what it left behind (G4.5)
        _release(hs);
        dd_curl_eval_destroy(&x->eval);
    }

    dd_curl_collect_destroy(&x->coll);
    dd_curl_collect_init(&x->coll, &_coll_alloc, _body_cap());
    // bodies are copied only once W1 decided the transfer is sampled
    // (dd_curl_xfer_presend); testing keeps them all
    x->coll.sampled = _collect_all;
    memset(x->slot_calls, 0, sizeof(x->slot_calls));
    memset(x->slot_bytes, 0, sizeof(x->slot_bytes));
    x->multi = multi;
    x->observed = false;
    x->reason = NULL;

    const char *reason = NULL;
    if (!DDAPPSEC_G(active)) {
        reason = "appsec_inactive";
    } else {
        reason = dd_curl_opts_skip_reason(&hs->opts);
    }
    if (!reason && !_collect_all) {
        // not http(s), or no request being analysed
        reason = dd_curl_eval_skip_reason(&hs->opts);
    }
    if (reason && strcmp(reason, "appsec_inactive") != 0 &&
        strcmp(reason, "not_evaluated") != 0) {
        // the reasons of G3.9; the two above are not
        _log_skip(reason);
    }
    // Every transfer start normalises the handle, observed or not (G5.2).
    // One that is skipped already needs it only if it may hold ours (a
    // copy); its CURL * is then validated without a second log line.
    dd_curl_easy *cp = NULL;
    void *php_curl = NULL;
    bool have_cp = false;
    if (!reason) {
        dd_curl_handle_status st =
            dd_curl_handle_get_ex(zch, &cp, &php_curl, true);
        if (st != DD_CURL_HANDLE_OK) {
            reason = _handle_status_reason(st);
        } else {
            have_cp = true;
        }
    } else if (x->may_hold_ours) {
        have_cp = dd_curl_handle_get_ex(zch, &cp, &php_curl, false) ==
                  DD_CURL_HANDLE_OK;
    }
    if (have_cp) {
        _normalise(x, &hs->opts, cp);
    }
    char why[DD_CURL_SLOTS_REASON_SIZE] = "";
    if (!reason && !_install(x, cp, php_curl, why)) {
        // the guard failed, or libcurl refused our functions: the handle
        // cannot be interposed (G3.9, G5.2)
        reason = "cannot_interpose";
        mlog_no_php(dd_log_debug, "Not observing a curl transfer: %s (%s)",
            reason, why);
    }
    x->reason = reason;
    x->observed = reason == NULL;
    if (x->installed) {
        dd_curl_eval_start(&x->eval, &hs->opts, _body_cap());
        // the hops of the collector start at our PREREQ calls (G5.12), so
        // it is installed on every observed transfer (an app's own skips the
        // transfer, G5.13)
        if (!hs->opts.prereqfunction &&
            dd_curl_linkage_version_num() >= DD_CURL_PREREQ_MIN_VERSION) {
            _set_prereq(x);
        }
    } else {
        dd_curl_eval_destroy(&x->eval);
    }
    return x->installed ? hs : NULL;
}

bool dd_curl_xfer_presend(struct dd_curl_hstate *nonnull hs)
{
    struct dd_curl_xfer *x = &hs->xfer;
    bool block = dd_curl_eval_presend(&x->eval, &hs->opts);
    // the sampling decision is taken by W1; nothing has been written yet
    x->coll.sampled = _collect_all || dd_curl_eval_sampled(&x->eval);
    return block;
}

void dd_curl_xfer_end_bailout(struct dd_curl_hstate *nonnull hs)
{
    struct dd_curl_xfer *x = &hs->xfer;
    x->in_exec = false;
    x->eval.active = false;
    if (!x->installed) {
        return;
    }
    _release_with(hs, false);
}

bool dd_curl_xfer_blocked(struct dd_curl_hstate *nonnull hs)
{
    return dd_curl_eval_blocked(&hs->xfer.eval);
}

void dd_curl_xfer_settle(struct dd_curl_hstate *nonnull hs) { _settle(hs); }

void dd_curl_xfer_settle_zch(zval *nonnull zch)
{
    struct dd_curl_hstate *hs = dd_curl_hstate_find(zch);
    if (hs) {
        _settle(hs);
    }
}

void dd_curl_xfer_settle_multi(uintptr_t multi)
{
    if (!multi) {
        return;
    }
    // settling may not return; and if it does, _installed may have changed
    while (_installed) {
        struct dd_curl_xfer *found = NULL;
        struct dd_curl_xfer *x;
        ZEND_HASH_FOREACH_PTR(_installed, x)
        {
            if (x->multi == multi && dd_curl_eval_settleable(&x->eval)) {
                found = x;
                break;
            }
        }
        ZEND_HASH_FOREACH_END();
        if (!found) {
            break;
        }
        _settle(_hstate_of(found));
    }
}

void dd_curl_xfer_end(struct dd_curl_hstate *nonnull hs, bool completed)
{
    struct dd_curl_xfer *x = &hs->xfer;
    x->in_exec = false;
    if (!x->installed) {
        return;
    }
    if (x->prereq_installed && hs->opts.prereqfunction) {
        // The app replaced our PREREQ during the transfer (from a callback,
        // or between two curl_multi_exec calls): the hops after it started
        // none of ours (G5.12, G5.13), so what the collector holds may mix
        // the current hop's head with a later hop's body. The current hop
        // is closed without data instead.
        dd_curl_collect_stop(&x->coll);
    }
    dd_curl_collect_end(&x->coll, completed);
    // the last WAF calls, before our functions go
    dd_curl_eval_end(&x->eval, &x->coll);
    _release(hs);
}

// Ends the observation of a transfer without any WAF call: our functions go,
// the collector's buffers are released (G4.5, G9.10). The eval state is the
// caller's: destroying it makes no call either
static void _release(struct dd_curl_hstate *hs) { _release_with(hs, true); }

// touch_libcurl false: the handle's options stay as they are
static void _release_with(struct dd_curl_hstate *hs, bool touch_libcurl)
{
    struct dd_curl_xfer *x = &hs->xfer;
    bool alive = touch_libcurl && _hstate_alive(hs);
    if (!_collect_all) {
        // the buffers are released; what is known of the hops stays
        dd_curl_collect_destroy(&x->coll);
    }
    if (alive) {
        _restore_slots(x);
        // unless the app has set its own since
        if (x->prereq_installed && !hs->opts.prereqfunction) {
            _remove_prereq(x->cp);
        }
    }
    _forget_prereq(x);
    _unregister(x);
    x->multi = 0;
}

void dd_curl_xfer_set_in_exec(struct dd_curl_hstate *nonnull hs, bool in_exec)
{
    hs->xfer.in_exec = in_exec;
}

void dd_curl_xfer_reassert_multi(uintptr_t multi)
{
    if (!multi) {
        return;
    }
    // Those the app started using the debug facilities on mid-transfer are
    // no longer observed. Ending one changes _installed: one at a time, with
    // a new scan after each.
    while (_installed) {
        struct dd_curl_xfer *found = NULL;
        const char *reason = NULL;
        struct dd_curl_xfer *x;
        ZEND_HASH_FOREACH_PTR(_installed, x)
        {
            if (x->multi != multi || !_hstate_alive(_hstate_of(x))) {
                continue;
            }
            reason = dd_curl_opts_skip_reason(&_hstate_of(x)->opts);
            if (reason) {
                _log_skip(reason);
                found = x;
                break;
            }
        }
        ZEND_HASH_FOREACH_END();
        if (!found) {
            break;
        }
        dd_curl_xfer_end(_hstate_of(found), false);
        found->reason = reason;
        found->observed = false;
    }
    if (!_installed) {
        return;
    }
    // no app code from here on
    struct dd_curl_xfer *x;
    ZEND_HASH_FOREACH_PTR(_installed, x)
    {
        if (x->multi != multi) {
            continue;
        }
        struct dd_curl_hstate *hs = _hstate_of(x);
        if (!_hstate_alive(hs)) {
            _unregister(x); // the handle is gone; so is its CURL *
            continue;
        }
        // one whose app set its own PREREQ was ended by the scan above (skip
        // reason prereqfunction)
        if (x->prereq_installed) {
            _set_prereq(x);
        }
    }
    ZEND_HASH_FOREACH_END();
}

void dd_curl_xfer_end_multi(uintptr_t multi)
{
    if (!multi) {
        return;
    }
    _multi_emptied(multi);
    dd_curl_xfer_flush_multi(multi);
}

void dd_curl_xfer_flush_multi(uintptr_t multi)
{
    if (!multi) {
        return;
    }
    // ending one may block (and not return), so one at a time
    while (_installed) {
        struct dd_curl_xfer *found = NULL;
        struct dd_curl_xfer *x;
        ZEND_HASH_FOREACH_PTR(_installed, x)
        {
            if (x->multi == multi) {
                found = x;
                break;
            }
        }
        ZEND_HASH_FOREACH_END();
        if (!found) {
            break;
        }
        struct dd_curl_hstate *hs = _hstate_of(found);
        dd_curl_xfer_end(hs, false); // abandoned
        _settle(hs);
    }
}

void dd_curl_xfer_added_to_multi(zval *nonnull zch, uintptr_t multi)
{
    struct dd_curl_hstate *hs = dd_curl_hstate_find(zch);
    if (hs && multi) {
        hs->xfer.in_multi = multi;
        // libcurl reads the options at the first curl_multi_perform: the
        // transfer starts (is evaluated) in the first curl_multi_exec
        if (!hs->xfer.in_exec && _tracking()) {
            _set_pending(hs, multi);
        }
    }
}

void dd_curl_xfer_add_failed(zval *nonnull zch, uintptr_t multi)
{
    struct dd_curl_hstate *hs = dd_curl_hstate_find(zch);
    if (hs && multi && hs->xfer.pending_multi == multi) {
        _set_pending(hs, 0);
    }
}

bool dd_curl_xfer_start_pending(uintptr_t multi)
{
    // a start may run app code or block: one at a time, scanning again
    struct dd_curl_hstate *hs;
    while (multi && _pending_count > 0 && (hs = _find_pending(multi))) {
        _set_pending(hs, 0);
        zval zch;
#if PHP_VERSION_ID >= 80000
        ZVAL_OBJ(&zch, hs->obj);
#else
        if (!_hstate_alive(hs)) {
            continue;
        }
        ZVAL_RES(&zch, hs->res);
#endif
        struct dd_curl_hstate *started = dd_curl_xfer_begin(&zch, multi);
        if (started && dd_curl_xfer_presend(started)) {
            dd_curl_xfer_end(started, false);
            // if the request is not ended by the block, the handle must not
            // run unevaluated by a later exec
            _set_pending(started, multi);
            dd_curl_xfer_settle(started);
            return true;
        }
    }
    return false;
}

void dd_curl_xfer_drop_pending(uintptr_t multi)
{
    struct dd_curl_hstate *hs;
    while (multi && _pending_count > 0 && (hs = _find_pending(multi))) {
        _set_pending(hs, 0);
    }
}

void dd_curl_xfer_drop_pending_handle(zval *nonnull zch, uintptr_t multi)
{
    struct dd_curl_hstate *hs = dd_curl_hstate_find(zch);
    if (hs && multi && hs->xfer.pending_multi == multi) {
        hs->xfer.in_multi = 0;
        _set_pending(hs, 0);
    }
}

void dd_curl_xfer_removed_from_multi(zval *nonnull zch, uintptr_t multi)
{
    struct dd_curl_hstate *hs = dd_curl_hstate_find(zch);
    if (hs && multi && hs->xfer.in_multi == multi) {
        hs->xfer.in_multi = 0;
        _set_pending(hs, 0);
    }
}

void dd_curl_xfer_end_in_multi(
    zval *nonnull zch, uintptr_t multi, bool completed)
{
    struct dd_curl_hstate *hs = dd_curl_hstate_find(zch);
    if (hs && hs->xfer.installed && multi && hs->xfer.multi == multi) {
        dd_curl_xfer_end(hs, completed);
    }
}

void dd_curl_xfer_after_copy(struct dd_curl_hstate *nonnull src,
    zval *nonnull zcopy, struct dd_curl_hstate *nullable copy)
{
    // The callback slots are not touched (G4.14, G5.2): a copy made while
    // ours are installed carries our functions, with its own php_curl * as
    // their data. They forward its calls (G5.4), and its first transfer
    // start puts ext/curl's back.
    if (copy) {
        copy->xfer.may_hold_ours =
            src->xfer.may_hold_ours || src->xfer.slots_installed;
    }
    if (!src->xfer.prereq_installed || src->opts.prereqfunction) {
        return;
    }
    // curl_easy_duphandle() copied our PREREQFUNCTION/PREREQDATA, which
    // ext/curl leaves alone unless the app has its own. A copy whose CURL *
    // is not validated (the analysis was disabled since, or the probe is
    // refused) keeps them until its next transfer start normalises it
    // (may_hold_ours); once the source's transfer is over, its PREREQDATA
    // resolves to nothing.
    dd_curl_easy *cp;
    if (dd_curl_handle_get(zcopy, &cp) == DD_CURL_HANDLE_OK) {
        _remove_prereq(cp);
    }
}

bool dd_curl_has_effective_url(zval *nonnull zch)
{
    const struct dd_curl_functions *funcs = dd_curl_linkage_funcs();
    dd_curl_easy *cp;
    if (!funcs || dd_curl_handle_get(zch, &cp) != DD_CURL_HANDLE_OK) {
        return false;
    }
    char *url = NULL;
    return funcs->curl_easy_getinfo(cp, DD_CURLINFO_EFFECTIVE_URL, &url) ==
               DD_CURLE_OK &&
           url && *url;
}

bool dd_curl_observe_tracking(void) { return _tracking(); }

static bool _tracking(void)
{
    return _req_active && dd_curl_downstream_enabled();
}

static size_t _body_cap(void)
{
    zend_long size = get_DD_APPSEC_MAX_BODY_BUFF_SIZE();
    return size > 0 ? (size_t)size : DD_DEFAULT_MAX_BODY_SIZE;
}

// NULL (the collector stops: the transfer is no longer analysed) rather than
// a fatal error at the memory limit, which would end a request that would
// have gone on without us, possibly from inside libcurl
static void *_coll_realloc(void *ptr, size_t size)
{
#ifdef TESTING
    if (_coll_refuse) {
        _coll_refusals++;
        return NULL;
    }
#endif
    if (!dd_emalloc_fits(size)) {
#ifdef TESTING
        _coll_refusals++;
#endif
        return NULL;
    }
    return erealloc(ptr, size);
}

static void _coll_free(void *ptr) { efree(ptr); }

static struct dd_curl_hstate *_hstate_of(struct dd_curl_xfer *x)
{
    _Static_assert(offsetof(struct dd_curl_hstate, xfer) == 0,
        "xfer must be the first member of dd_curl_hstate");
    return (struct dd_curl_hstate *)((char *)x -
                                     offsetof(struct dd_curl_hstate, xfer));
}

static void _hstate_dtor(zval *zv) { _hstate_free(Z_PTR_P(zv)); }

// Called when the handle is gone (its CURL * was cleaned up already) or at
// request shutdown (after our options were removed): never calls libcurl.
static void _hstate_free(struct dd_curl_hstate *hs)
{
    _set_pending(hs, 0);
    _unregister(&hs->xfer);
    hs->xfer.magic = 0;
    dd_curl_collect_destroy(&hs->xfer.coll);
    dd_curl_eval_destroy(&hs->xfer.eval);
    dd_curl_opts_dtor(&hs->opts);
    efree(hs);
}

static bool _hstate_alive(struct dd_curl_hstate *hs)
{
#if PHP_VERSION_ID >= 80000
    // entries of freed objects are removed by the weak map
    (void)hs;
    return true;
#else
    zval *zv = zend_hash_index_find(&EG(regular_list), hs->res_handle);
    return zv && Z_TYPE_P(zv) == IS_RESOURCE && Z_RES_P(zv) == hs->res &&
           dd_curl_handle_is_curl(zv) && Z_RES_VAL_P(zv) == hs->php_curl;
#endif
}

#if PHP_VERSION_ID < 80000
static void _sweep_dead(void)
{
    struct dd_curl_hstate *hs;
    ZEND_HASH_FOREACH_PTR(_hstates, hs)
    {
        if (!_hstate_alive(hs)) {
            zend_hash_index_del(_hstates, hs->res_handle);
        }
    }
    ZEND_HASH_FOREACH_END();
}
#endif

#if PHP_VERSION_ID >= 80000
// The CurlMultiHandle is being freed; ext/curl has already removed its easy
// handles (curl_multi_cleanup()), so they are no longer in any multi. The
// weak map is notified by zend_object_std_dtor(), the last thing curl_multi_
// free_obj() does, after curl_multi_cleanup() (checked in 8.0, 8.3, 8.5).
static void _multi_dtor(zval *zv)
{
    if (!_req_active) {
        return; // request shutdown: the transfers are gone already
    }
    uintptr_t multi = (uintptr_t)Z_PTR_P(zv);
    _multi_emptied(multi);
    // Not dd_curl_xfer_end_multi(): a destructor makes no WAF call (G4.5),
    // it only releases what the transfers hold.
    while (_installed) {
        struct dd_curl_xfer *found = NULL;
        struct dd_curl_xfer *x;
        ZEND_HASH_FOREACH_PTR(_installed, x)
        {
            if (x->multi == multi) {
                found = x;
                break;
            }
        }
        ZEND_HASH_FOREACH_END();
        if (!found) {
            break;
        }
        _release(_hstate_of(found));
        dd_curl_eval_destroy(&_hstate_of(found)->xfer.eval);
    }
}
#endif

// Whether the multi handle with this key still exists. PHP 8: the transfers
// of freed ones were ended (_multi_dtor). PHP 7: the key is the resource id
// plus one; ids are not reused within a request.
static bool _multi_alive(uintptr_t multi)
{
#if PHP_VERSION_ID >= 80000
    (void)multi;
    return true;
#else
    zval *zv = zend_hash_index_find(&EG(regular_list), (zend_ulong)(multi - 1));
    return zv && Z_TYPE_P(zv) == IS_RESOURCE &&
           Z_RES_TYPE_P(zv) == _le_curl_multi;
#endif
}

// curl_multi_close() or the multi handle is freed: libcurl has none of its
// handles in it anymore
static void _multi_emptied(uintptr_t multi)
{
    if (!_hstates) {
        return;
    }
    struct dd_curl_hstate *hs;
    ZEND_HASH_FOREACH_PTR(_hstates, hs)
    {
        if (hs->xfer.in_multi == multi) {
            hs->xfer.in_multi = 0;
            _set_pending(hs, 0);
        }
    }
    ZEND_HASH_FOREACH_END();
}

static void _set_pending(struct dd_curl_hstate *hs, uintptr_t multi)
{
    if (hs->xfer.pending_multi && !multi) {
        _pending_count--;
    } else if (!hs->xfer.pending_multi && multi) {
        _pending_count++;
        hs->xfer.pending_seq = ++_pending_last_seq;
    }
    hs->xfer.pending_multi = multi;
}

static struct dd_curl_hstate *_find_pending(uintptr_t multi)
{
    struct dd_curl_hstate *hs;
    struct dd_curl_hstate *first = NULL;
    if (!_hstates) {
        return NULL;
    }
    ZEND_HASH_FOREACH_PTR(_hstates, hs)
    {
        if (hs->xfer.pending_multi == multi &&
            (!first || hs->xfer.pending_seq < first->xfer.pending_seq)) {
            first = hs;
        }
    }
    ZEND_HASH_FOREACH_END();
    return first;
}

// One debug line per skipped transfer, not through PHP: its error handler
// could raise from inside a libcurl callback (G3.9)
static void _log_skip(const char *reason)
{
    mlog_no_php(dd_log_debug, "Not observing a curl transfer: %s", reason);
}

// A transfer start, observed or not (G5.2): a slot that holds our function
// gets ext/curl's back, and our PREREQFUNCTION/PREREQDATA go (unless the app
// has its own, which the transfer is skipped for). A copy made while ours
// were installed carries them, and its PREREQ calls would come with the
// source's PREREQDATA.
static void _normalise(
    struct dd_curl_xfer *x, const struct dd_curl_opts *opts, dd_curl_easy *cp)
{
    bool slots_ok = dd_curl_slots_normalise(&_slots, cp);
    if (!slots_ok) {
        // ours stay in that slot; they only forward (G5.4). Still flagged,
        // so that the next transfer start tries again even if it is skipped.
        mlog_no_php(dd_log_debug,
            "libcurl refused to put ext/curl's callback functions back");
    }
    if (x->may_hold_ours && !opts->prereqfunction &&
        dd_curl_linkage_version_num() >= DD_CURL_PREREQ_MIN_VERSION) {
        _remove_prereq(cp);
    }
    x->may_hold_ours = !slots_ok;
}

static bool _install(struct dd_curl_xfer *x, dd_curl_easy *cp, void *php_curl,
    char why[DD_CURL_SLOTS_REASON_SIZE])
{
    if (!_installed) {
        ALLOC_HASHTABLE(_installed);
        zend_hash_init(_installed, 8, NULL, NULL, 0);
    }
    x->cp = cp;
    x->installed = true;
    zend_hash_index_update_ptr(_installed, (zend_ulong)(uintptr_t)cp, x);

    if (!_install_slots(x, cp, php_curl, why)) {
        _unregister(x);
        return false;
    }
    return true;
}

// The guard, then our functions in the slots, before the first perform: the
// read slot is copied into libcurl's transfer state once, when the transfer
// starts (G5.2)
static bool _install_slots(struct dd_curl_xfer *x, dd_curl_easy *cp,
    void *php_curl, char why[DD_CURL_SLOTS_REASON_SIZE])
{
    if (!dd_curl_slots_guard(&_slots, cp, php_curl, why)) {
        return false;
    }
    // registered first: libcurl may call them as soon as they are set
    if (!_slot_xfers) {
        ALLOC_HASHTABLE(_slot_xfers);
        zend_hash_init(_slot_xfers, 8, NULL, NULL, 0);
    }
    zend_hash_index_update_ptr(_slot_xfers, (zend_ulong)(uintptr_t)php_curl, x);
    x->php_curl = php_curl;
    x->slots_installed = true;
    bool refuse = false;
#ifdef TESTING
    refuse = _install_refuse;
#endif
    if (refuse || !dd_curl_slots_install(&_slots, cp)) {
        (void)snprintf(why, DD_CURL_SLOTS_REASON_SIZE,
            "libcurl refused our slot functions");
        _unregister_slots(x);
        return false;
    }
    x->may_hold_ours = true;
    return true;
}

// The end of the transfer: ext/curl's functions back where ours still are
// (a curl_reset() in flight has put them back already)
static void _restore_slots(struct dd_curl_xfer *x)
{
    if (x->slots_installed && x->cp) {
        dd_curl_slots_restore(&_slots, x->cp);
    }
    _unregister_slots(x);
}

static void _unregister_slots(struct dd_curl_xfer *x)
{
    if (!x->slots_installed) {
        return;
    }
    if (_slot_xfers) {
        zend_ulong key = (zend_ulong)(uintptr_t)x->php_curl;
        if (zend_hash_index_find_ptr(_slot_xfers, key) == x) {
            zend_hash_index_del(_slot_xfers, key);
        }
    }
    x->slots_installed = false;
    x->php_curl = NULL;
}

// PREREQDATA first: our function is useless without it. The function has
// no CURL * argument, so the callback finds the transfer only via PREREQDATA.
static void _set_prereq(struct dd_curl_xfer *x)
{
    const struct dd_curl_functions *funcs = dd_curl_linkage_funcs();
    if (!funcs) {
        return;
    }
    // registered first: libcurl may call it as soon as it is set
    if (!_prereqs) {
        ALLOC_HASHTABLE(_prereqs);
        zend_hash_init(_prereqs, 8, NULL, NULL, 0);
    }
    if (!x->prereq_id) {
        if (!++_prereq_last_id) {
            ++_prereq_last_id; // wrapped around
        }
        x->prereq_id = _prereq_last_id;
    }
    zend_hash_index_update_ptr(_prereqs, (zend_ulong)x->prereq_id, x);
    x->prereq_installed =
        funcs->curl_easy_setopt(x->cp, DD_CURLOPT_PREREQDATA,
            (void *)x->prereq_id) == DD_CURLE_OK &&
        funcs->curl_easy_setopt(x->cp, DD_CURLOPT_PREREQFUNCTION,
            (dd_curl_prereq_fn)_prereq_cb) == DD_CURLE_OK;
    if (!x->prereq_installed) {
        _remove_prereq(x->cp);
        _forget_prereq(x);
    }
}

// Our PREREQFUNCTION is no longer (or was never) installed for x
static void _forget_prereq(struct dd_curl_xfer *x)
{
    x->prereq_installed = false;
    if (_prereqs && x->prereq_id) {
        zend_hash_index_del(_prereqs, (zend_ulong)x->prereq_id);
    }
}

static void _remove_prereq(dd_curl_easy *cp)
{
    const struct dd_curl_functions *funcs = dd_curl_linkage_funcs();
    if (!funcs || !cp) {
        return;
    }
    (void)funcs->curl_easy_setopt(
        cp, DD_CURLOPT_PREREQFUNCTION, (dd_curl_prereq_fn)NULL);
    (void)funcs->curl_easy_setopt(cp, DD_CURLOPT_PREREQDATA, (void *)NULL);
}

// Back in a PHP function hook: re-raises a bailout caught in one of our
// libcurl callbacks, or blocks the request if the transfer got a blocking
// verdict.
static void _settle(struct dd_curl_hstate *hs)
{
    dd_curl_eval_reraise(&hs->xfer.eval);
    dd_curl_eval_block(&hs->xfer.eval);
}

static void _unregister(struct dd_curl_xfer *x)
{
    // also for the state being freed
    _forget_prereq(x);
    _unregister_slots(x);
    if (!x->installed) {
        return;
    }
    if (_installed) {
        zend_ulong key = (zend_ulong)(uintptr_t)x->cp;
        if (zend_hash_index_find_ptr(_installed, key) == x) {
            zend_hash_index_del(_installed, key);
        }
    }
    x->installed = false;
    x->cp = NULL;
}

// CURLOPT_PREREQFUNCTION: runs inside libcurl before each request of the
// transfer is sent (after the connection is ready): a hop starts (G5.12).
// clientp is our state if found in _prereqs (by id): we only set it on
// transfers we registered, and remove it from the copies and clones we know
// of. The evaluator finishes the hop that is over from what the collector
// holds, the collector then starts the new hop (new head, previous body
// dropped), and the evaluator makes the new hop's W2.
// the signature is libcurl's curl_prereq_callback
// NOLINTBEGIN(bugprone-easily-swappable-parameters,readability-non-const-parameter)
static int _prereq_cb(void *clientp, char *conn_primary_ip, char *conn_local_ip,
    int conn_primary_port, int conn_local_port)
// NOLINTEND(bugprone-easily-swappable-parameters,readability-non-const-parameter)
{
    (void)conn_primary_ip;
    (void)conn_local_ip;
    (void)conn_primary_port;
    (void)conn_local_port;
    HashTable *installed = _installed;
    HashTable *prereqs = _prereqs;
    if (!installed || !prereqs || !clientp) {
        return DD_CURL_PREREQFUNC_OK;
    }
    // clientp is an id, not a pointer: live state if found
    struct dd_curl_xfer *x =
        zend_hash_index_find_ptr(prereqs, (zend_ulong)(uintptr_t)clientp);
    if (!x || x->magic != DD_CURL_XFER_MAGIC || !x->installed ||
        zend_hash_index_find_ptr(installed, (zend_ulong)(uintptr_t)x->cp) !=
            x) {
        return DD_CURL_PREREQFUNC_OK;
    }
    dd_curl_eval_prereq_hop_over(&x->eval, &x->coll);
    dd_curl_collect_prereq(&x->coll);
    bool abort = dd_curl_eval_prereq_hop_start(&x->eval, x->cp);
    return abort ? DD_CURL_PREREQFUNC_ABORT : DD_CURL_PREREQFUNC_OK;
}

// Our write, header and read functions. They run inside libcurl, for any
// handle that carries them: one observed now (found by its php_curl *), or a
// copy, a pushed handle, a handle whose transfer is over. userdata is only
// a key until found (G5.4); an unregistered call is only forwarded. Nothing
// is held back or altered, and no PHP code or WAF call of ours runs here.
static struct dd_curl_xfer *_slot_xfer(void *userdata)
{
    HashTable *xfers = _slot_xfers;
    if (!xfers || !userdata) {
        return NULL;
    }
    struct dd_curl_xfer *x =
        zend_hash_index_find_ptr(xfers, (zend_ulong)(uintptr_t)userdata);
    if (!x || x->magic != DD_CURL_XFER_MAGIC || !x->slots_installed ||
        x->php_curl != userdata) {
        return NULL;
    }
#if PHP_VERSION_ID < 80000
    // The state of a freed resource is dropped lazily (its own lookup, a
    // sweep, rshutdown): its transfer may still be registered under its
    // freed php_curl *, which a new handle can have now (a copy carrying
    // ours, never normalised when run from a callback, G4.3). Dropping the
    // registration only removes a hash entry: nothing is allocated.
    if (!_hstate_alive(_hstate_of(x))) {
        _unregister_slots(x);
        return NULL;
    }
#endif
    return x;
}

static void _collect(enum dd_curl_islot slot, struct dd_curl_xfer *x,
    const char *ptr, size_t len);

// Every call goes at once, with the same arguments, to ext/curl's function,
// whose return value is returned unchanged (G5.3). What it took is then
// appended to the transfer's collector (write and header) and counted
// (testing). The state is found again after the original function: the app's
// callback, which it may have run, may have ended the transfer (curl_reset(),
// a freed handle on PHP 7).
static size_t _forward(enum dd_curl_islot slot, char *ptr, size_t size,
    size_t nmemb, void *userdata)
{
    dd_curl_slot_fn orig = dd_curl_slots_pristine(&_slots, slot);
    if (UNEXPECTED(!orig)) {
        // never: ours are installed only once ext/curl's are known
        return slot == DD_CURL_ISLOT_READ ? DD_CURL_READFUNC_ABORT : 0;
    }
    if (slot == DD_CURL_ISLOT_READ && EXPECTED(!_slot_counting)) {
        return orig(ptr, size, nmemb, userdata); // the gate is not armed
    }
    struct dd_curl_xfer *x = _slot_xfer(userdata);
    size_t ret = orig(ptr, size, nmemb, userdata);
    if (x && _slot_xfer(userdata) == x) {
        size_t taken = _slot_taken(slot, ret, size, nmemb);
        if (_slot_counting) {
            x->slot_calls[slot]++;
            x->slot_bytes[slot] += taken;
        }
        if (taken > 0) {
            _collect(slot, x, ptr, taken);
        }
    }
    return ret;
}

// Pure C, no PHP code, no WAF call, no zend_try (G9.1): the collector only
// appends to its capped buffers, through the fit-checked allocator, which
// refuses instead of raising a fatal error (G9.5). A header call is one
// line, as libcurl delivers it.
static void _collect(enum dd_curl_islot slot, struct dd_curl_xfer *x,
    const char *ptr, size_t len)
{
    switch (slot) {
    case DD_CURL_ISLOT_WRITE:
        dd_curl_collect_write(&x->coll, ptr, len);
        break;
    case DD_CURL_ISLOT_HEADER:
        dd_curl_collect_header_line(&x->coll, ptr, len);
        break;
    case DD_CURL_ISLOT_READ:
    case DD_CURL_ISLOT_COUNT:
    default:
        break;
    }
}

// The bytes of a call that were taken, given what ext/curl's function
// returned. A write or header call counts only if all its data was taken: a
// short return aborts the transfer, and CURL_WRITEFUNC_PAUSE (0x10000001)
// makes libcurl deliver the same data again once unpaused. A read call
// passed on what it returned, unless that is CURL_READFUNC_ABORT or
// CURL_READFUNC_PAUSE (both larger than the buffer).
static size_t _slot_taken(
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    enum dd_curl_islot slot, size_t ret, size_t size, size_t nmemb)
{
    // libcurl always passes size == 1 to these slots; a product that would
    // wrap is not a length it delivered
    if (size != 0 && nmemb > SIZE_MAX / size) {
        return 0;
    }
    size_t len = size * nmemb;
    if (slot == DD_CURL_ISLOT_READ) {
        return ret <= len ? ret : 0;
    }
    return ret == len ? len : 0;
}

static size_t _write_cb(char *ptr, size_t size, size_t nmemb, void *userdata)
{
    return _forward(DD_CURL_ISLOT_WRITE, ptr, size, nmemb, userdata);
}

static size_t _header_cb(char *ptr, size_t size, size_t nmemb, void *userdata)
{
    return _forward(DD_CURL_ISLOT_HEADER, ptr, size, nmemb, userdata);
}

// The read function: through the transfer's read-function gate while it is
// armed (G6.7), else straight to ext/curl's
static size_t _read_cb(char *ptr, size_t size, size_t nmemb, void *userdata)
{
    struct dd_curl_xfer *x = _slot_xfer(userdata);
    if (!x || !dd_curl_eval_gate_armed(&x->eval)) {
        return _forward(DD_CURL_ISLOT_READ, ptr, size, nmemb, userdata);
    }
    size_t ret = _gate_read(x, ptr, size, nmemb, userdata);
    if (_slot_counting && _slot_xfer(userdata) == x) {
        x->slot_calls[DD_CURL_ISLOT_READ]++;
        x->slot_bytes[DD_CURL_ISLOT_READ] +=
            _slot_taken(DD_CURL_ISLOT_READ, ret, size, nmemb);
    }
    return ret;
}

// The read-function gate (G6.7, G7.15): while it fills, the original read
// function (ext/curl's curl_read: the app's READFUNCTION, or fread() on the
// INFILE's FILE *) is called into libcurl's buffer, with libcurl's length
// (less, never past the upload size libcurl sends), and what it returns is
// held, until EOF, the body cap or the upload size; the gate then makes its
// WAF call (W3b) and the held bytes are served, then the rest of the body
// comes straight from the original function. Nothing reaches libcurl before
// W3b: a block (or a caught bailout) returns CURL_READFUNC_ABORT, applied
// (re-raised) once libcurl has returned to the hook (G7.16). ABORT, PAUSE
// and other values above the length asked are returned at once (but see
// dd_curl_eval_gate_take for one within libcurl's length).
static size_t _gate_read(struct dd_curl_xfer *x, char *ptr, size_t size,
    size_t nmemb, void *userdata)
{
    dd_curl_slot_fn orig = dd_curl_slots_pristine(&_slots, DD_CURL_ISLOT_READ);
    if (UNEXPECTED(!orig)) {
        return DD_CURL_READFUNC_ABORT; // never: see _forward
    }
    struct dd_curl_eval *ev = &x->eval;
    if (size != 0 && nmemb > SIZE_MAX / size) {
        return orig(ptr, size, nmemb, userdata); // not a length libcurl passes
    }
    size_t len = size * nmemb;
    while (dd_curl_eval_gate_filling(ev)) {
        size_t ask = dd_curl_eval_gate_ask(ev, x->cp, len);
        size_t ret = 0;
        if (ask > 0) {
            // the same arguments as libcurl's, unless less is left to read
            ret = ask == len ? orig(ptr, size, nmemb, userdata)
                             : orig(ptr, 1, ask, userdata);
            if (_slot_xfer(userdata) != x) {
                return ret; // the transfer's state is gone (cannot happen)
            }
        }
        enum dd_curl_gate_step step =
            dd_curl_eval_gate_take(ev, ptr, ret, ask, len);
        if (step == DD_CURL_GATE_STEP_PASS) {
            return ret;
        }
        if (step == DD_CURL_GATE_STEP_ABORT) {
            return DD_CURL_READFUNC_ABORT;
        }
        if (step == DD_CURL_GATE_STEP_READY && dd_curl_eval_gate_aborts(ev)) {
            return DD_CURL_READFUNC_ABORT;
        }
    }
    bool forward = false;
    size_t n = dd_curl_eval_gate_serve(ev, ptr, len, &forward);
    return forward ? orig(ptr, size, nmemb, userdata) : n;
}

static const char *_handle_status_reason(dd_curl_handle_status st)
{
    switch (st) {
    // The names of G3.9. curl_handle.c has logged the line of an invalid or
    // unverified handle, with the details of why; the others are no
    // transfers that G3.9 skips (not a handle, a pushed handle) or cannot
    // happen with the hooks installed (layer disabled): no line here.
    case DD_CURL_HANDLE_NOT_CURL:
    case DD_CURL_HANDLE_NULL_CP:
    case DD_CURL_HANDLE_INVALID:
        return "invalid_handle";
    case DD_CURL_HANDLE_UNVERIFIED:
        return "unverified_handle";
    case DD_CURL_HANDLE_DISABLED:
        return "layer_unavailable";
    case DD_CURL_HANDLE_OK:
    default:
        return "unknown";
    }
}

#ifdef TESTING
// What the collector holds: the head of its current hop (the last hop once
// the transfer is over) and the last hop's body copy
static void _coll_to_array(const struct dd_curl_collect *c, zval *arr)
{
    array_init(arr);
    if (c->status > 0) {
        add_assoc_long(arr, "status", c->status);
    } else {
        add_assoc_null(arr, "status");
    }
    zval headers;
    dd_curl_headers_to_array(c->head.ptr, c->head.len, &headers);
    add_assoc_zval(arr, "headers", &headers);
    add_assoc_bool(arr, "head_complete", c->head_complete);
    add_assoc_bool(arr, "head_truncated", c->head_truncated);
    add_assoc_long(arr, "interim_responses", (zend_long)c->interim);
    if (c->sampled) {
        add_assoc_stringl(arr, "body", c->body.buf.ptr ? c->body.buf.ptr : "",
            c->body.buf.len);
    } else {
        add_assoc_null(arr, "body"); // nothing is copied
    }
    add_assoc_bool(arr, "body_truncated", c->body.truncated);
    add_assoc_bool(
        arr, "body_complete", c->body.ended && c->body.framing_complete);
}

// curl_downstream_dump(CurlHandle|resource $ch): ?array
// What was collected for the last transfer of $ch; null if $ch isn't tracked.
// "response" (null before hop 0's PREREQ): the collector's head of the last
// hop and its body copy (null if bodies are not copied: not sampled).
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_dump)
{
    zval *zch;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &zch) == FAILURE) {
        RETURN_FALSE;
    }
    struct dd_curl_hstate *hs = dd_curl_hstate_find(zch);
    if (!hs) {
        RETURN_NULL();
    }
    const struct dd_curl_xfer *x = &hs->xfer;
    array_init(return_value);
    add_assoc_bool(return_value, "observed", x->observed);
    if (x->reason) {
        add_assoc_string(return_value, "reason", (char *)x->reason);
    } else {
        add_assoc_null(return_value, "reason");
    }
    add_assoc_bool(return_value, "installed", x->installed);
    zval calls;
    zval bytes;
    array_init(&calls);
    array_init(&bytes);
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        const char *name = dd_curl_islot_name((enum dd_curl_islot)i);
        add_assoc_long(&calls, name, (zend_long)x->slot_calls[i]);
        add_assoc_long(&bytes, name, (zend_long)x->slot_bytes[i]);
    }
    add_assoc_zval(return_value, "slot_calls", &calls);
    add_assoc_zval(return_value, "slot_bytes", &bytes);
    zval opts;
    dd_curl_opts_to_array(&hs->opts, &opts);
    add_assoc_zval(return_value, "options", &opts);
    // the read-function gate: "off", "filling" or "serving" (G6.7)
    add_assoc_string(
        return_value, "gate", (char *)dd_curl_eval_gate_state_name(&x->eval));
    // the PREREQ calls seen: hop n is the one started by the (n+1)-th
    add_assoc_long(return_value, "hops", (zend_long)x->coll.hops);
    add_assoc_bool(return_value, "hops_truncated", x->coll.hops_truncated);
    add_assoc_bool(return_value, "oom", x->coll.oom);
    if (x->coll.hops > 0) {
        zval resp;
        _coll_to_array(&x->coll, &resp);
        add_assoc_zval(return_value, "response", &resp);
    } else {
        add_assoc_null(return_value, "response");
    }
}

// curl_downstream_slots(CurlHandle|resource $ch): ?array
// What the callback slots of $ch's CURL * hold: for "write", "header" and
// "read", "ours", "pristine" (ext/curl's function, once learnt), "other" or
// "unused" (below libcurl 7.80, write and header); "data": "own" if the data
// of each used slot is $ch's php_curl *, else "other". Null if $ch's CURL *
// is not a validated libcurl handle.
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_slots)
{
    zval *zch;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &zch) == FAILURE) {
        RETURN_FALSE;
    }
    dd_curl_easy *cp;
    void *php_curl;
    if (!_slots_ready || dd_curl_handle_get_ex(zch, &cp, &php_curl, false) !=
                             DD_CURL_HANDLE_OK) {
        RETURN_NULL();
    }
    static const char *const names[] = {
        [DD_CURL_SLOT_UNUSED] = "unused",
        [DD_CURL_SLOT_OURS] = "ours",
        [DD_CURL_SLOT_PRISTINE] = "pristine",
        [DD_CURL_SLOT_OTHER] = "other",
    };
    array_init(return_value);
    bool own = true;
    for (int i = 0; i < DD_CURL_ISLOT_COUNT; i++) {
        enum dd_curl_slot_state st =
            dd_curl_slots_state(&_slots, cp, (enum dd_curl_islot)i);
        add_assoc_string(return_value,
            dd_curl_islot_name((enum dd_curl_islot)i), (char *)names[st]);
        if (st != DD_CURL_SLOT_UNUSED &&
            dd_curl_slots_data(&_slots, cp, (enum dd_curl_islot)i) !=
                php_curl) {
            own = false;
        }
    }
    add_assoc_string(return_value, "data", own ? "own" : "other");
}

// curl_downstream_collect_all(bool $on): void
// Whether transfers are observed and their bodies kept for
// curl_downstream_dump() even when no WAF call needs them (off by default,
// as in production).
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_collect_all)
{
    zend_bool on;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "b", &on) == FAILURE) {
        RETURN_FALSE;
    }
    _collect_all = on;
}

// curl_downstream_tracking(bool $on): void
// Whether new handles get a state, as they do while a request is active:
// off, a handle created has none (like one created before AppSec was
// active), for testing what the hooks do with a handle without state.
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_tracking)
{
    zend_bool on;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "b", &on) == FAILURE) {
        RETURN_FALSE;
    }
    _req_active = on;
}

// curl_downstream_coll_refuse(bool $on): void
// While on, every allocation of a collector is refused (as when it would
// reach the memory limit): the collector stops.
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_coll_refuse)
{
    zend_bool on;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "b", &on) == FAILURE) {
        RETURN_FALSE;
    }
    _coll_refuse = on;
}

// curl_downstream_coll_refusals(): int
// How many collector allocations were refused (G9.5) in the process so far.
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_coll_refusals)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    RETURN_LONG(_coll_refusals);
}

// curl_downstream_malloc_in_use(): int
// Bytes of the C heap (malloc) in use, libcurl's included and PHP's own heap
// excluded (it maps its chunks directly); -1 where glibc's mallinfo is
// missing. For tests that a torn transfer leaks nothing of libcurl's.
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_malloc_in_use)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
#    ifdef __GLIBC__
    // summed in 64 bits and clamped: on a 32-bit build zend_long is 32 bits,
    // and a sum past ZEND_LONG_MAX would come out negative (read as "no
    // mallinfo" by the tests, which would then skip instead of measuring)
#        if __GLIBC_PREREQ(2, 33)
    struct mallinfo2 mi = mallinfo2();
    uint64_t in_use = (uint64_t)mi.uordblks + (uint64_t)mi.hblkhd;
#        else
    struct mallinfo mi = mallinfo();
    uint64_t in_use = (uint64_t)(unsigned)mi.uordblks + (unsigned)mi.hblkhd;
#        endif
    RETURN_LONG(
        in_use > (uint64_t)ZEND_LONG_MAX ? ZEND_LONG_MAX : (zend_long)in_use);
#    else
    RETURN_LONG(-1);
#    endif
}

// curl_downstream_install_refuse(bool $on): void
// While on, libcurl refuses our functions for every transfer started: the
// handle cannot be interposed (G3.9).
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_install_refuse)
{
    zend_bool on;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "b", &on) == FAILURE) {
        RETURN_FALSE;
    }
    _install_refuse = on;
}

// curl_downstream_installed_count(): int
// Number of transfers with our functions currently installed.
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_installed_count)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    RETURN_LONG(_installed ? (zend_long)zend_hash_num_elements(_installed) : 0);
}

// curl_downstream_slot_registered_count(): int
// Number of transfers registered for our slot functions (by php_curl *).
static PHP_FUNCTION(
    datadog_appsec_testing_curl_downstream_slot_registered_count)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    RETURN_LONG(
        _slot_xfers ? (zend_long)zend_hash_num_elements(_slot_xfers) : 0);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(dump_arginfo, 0, 1, IS_ARRAY, 1)
ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    installed_count_arginfo, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(collect_all_arginfo, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, on, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(tracking_arginfo, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, on, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_dump", PHP_FN(datadog_appsec_testing_curl_downstream_dump), dump_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_installed_count", PHP_FN(datadog_appsec_testing_curl_downstream_installed_count), installed_count_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_slot_registered_count", PHP_FN(datadog_appsec_testing_curl_downstream_slot_registered_count), installed_count_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_slots",PHP_FN(datadog_appsec_testing_curl_downstream_slots), dump_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_collect_all", PHP_FN(datadog_appsec_testing_curl_downstream_collect_all), collect_all_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_tracking", PHP_FN(datadog_appsec_testing_curl_downstream_tracking), tracking_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_install_refuse", PHP_FN(datadog_appsec_testing_curl_downstream_install_refuse), tracking_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_coll_refuse", PHP_FN(datadog_appsec_testing_curl_downstream_coll_refuse), tracking_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_coll_refusals", PHP_FN(datadog_appsec_testing_curl_downstream_coll_refusals), installed_count_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_malloc_in_use", PHP_FN(datadog_appsec_testing_curl_downstream_malloc_in_use), installed_count_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void)
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }
    dd_phpobj_reg_funcs(testing_functions);
}
#endif
