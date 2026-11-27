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

#include <ext/compatibility.h>

#include "../configuration.h"
#include "../ddappsec.h"
#include "../logging.h"
#include "../php_objects.h"
#include "curl_eval.h"
#include "curl_handle.h"
#include "curl_hooks.h"
#include "curl_linkage.h"

// libcurl option numbers and values (curl.h; stable public ABI):
enum {
    DD_CURLE_OK = 0,
    DD_CURLINFO_EFFECTIVE_URL = 0x100001, // CURLINFO_STRING + 1
};

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
    bool in_exec;
    bool observed;               // our functions were installed for it
    const char *nullable reason; // why it was not observed
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
static THREAD_LOCAL_ON_ZTS bool _req_active;
// the number of handles with pending_multi set: the exec of a multi need not
// scan the handles when it is 0
static THREAD_LOCAL_ON_ZTS uint32_t _pending_count;
static THREAD_LOCAL_ON_ZTS uint64_t _pending_last_seq;
// Testing (curl_downstream_collect_all()): transfers are observed and their
// bodies kept even when no WAF call needs them, for curl_downstream_dump()
static THREAD_LOCAL_ON_ZTS bool _collect_all;
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
static void _install(struct dd_curl_xfer *x, dd_curl_easy *cp);
static void _unregister(struct dd_curl_xfer *x);

static const char *_handle_status_reason(dd_curl_handle_status st);

#ifdef TESTING
static void _register_testing_objects(void);
#endif

void dd_curl_observe_minit(void)
{
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
            x->installed = false;
            x->cp = NULL;
        }
        ZEND_HASH_FOREACH_END();
        zend_hash_destroy(_installed);
        FREE_HASHTABLE(_installed);
        _installed = NULL;
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
    }

    x->multi = multi;
    x->observed = false;
    x->reason = NULL;

    const char *reason = NULL;
    if (!DDAPPSEC_G(active)) {
        reason = "appsec_inactive";
    } else {
        reason = dd_curl_opts_skip_reason(&hs->opts);
    }
    if (reason && strcmp(reason, "appsec_inactive") != 0) {
        _log_skip(reason);
    }
    dd_curl_easy *cp = NULL;
    void *php_curl = NULL;
    if (!reason) {
        dd_curl_handle_status st =
            dd_curl_handle_get_ex(zch, &cp, &php_curl, true);
        if (st != DD_CURL_HANDLE_OK) {
            reason = _handle_status_reason(st);
        }
    }
    if (!reason) {
        _install(x, cp);
    }
    x->reason = reason;
    x->observed = reason == NULL;
    return x->installed ? hs : NULL;
}

void dd_curl_xfer_end(struct dd_curl_hstate *nonnull hs, bool completed)
{
    struct dd_curl_xfer *x = &hs->xfer;
    x->in_exec = false;
    if (!x->installed) {
        return;
    }
    (void)completed;
    _release(hs);
}

// Ends the observation of a transfer: our functions go (G4.5).
static void _release(struct dd_curl_hstate *hs) { _release_with(hs, true); }

// touch_libcurl false: the handle's options stay as they are
static void _release_with(struct dd_curl_hstate *hs, bool touch_libcurl)
{
    struct dd_curl_xfer *x = &hs->xfer;
    (void)touch_libcurl;
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
        (void)dd_curl_xfer_begin(&zch, multi);
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

static void _install(struct dd_curl_xfer *x, dd_curl_easy *cp)
{
    if (!_installed) {
        ALLOC_HASHTABLE(_installed);
        zend_hash_init(_installed, 8, NULL, NULL, 0);
    }
    x->cp = cp;
    x->installed = true;
    zend_hash_index_update_ptr(_installed, (zend_ulong)(uintptr_t)cp, x);
}

static void _unregister(struct dd_curl_xfer *x)
{
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
// curl_downstream_dump(CurlHandle|resource $ch): ?array
// What was collected for the last transfer of $ch; null if $ch isn't tracked.
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
    zval opts;
    dd_curl_opts_to_array(&hs->opts, &opts);
    add_assoc_zval(return_value, "options", &opts);
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

// curl_downstream_installed_count(): int
// Number of transfers with our functions currently installed.
static PHP_FUNCTION(datadog_appsec_testing_curl_downstream_installed_count)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    RETURN_LONG(_installed ? (zend_long)zend_hash_num_elements(_installed) : 0);
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
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_collect_all", PHP_FN(datadog_appsec_testing_curl_downstream_collect_all), collect_all_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_downstream_tracking", PHP_FN(datadog_appsec_testing_curl_downstream_tracking), tracking_arginfo, 0, NULL, NULL)
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
