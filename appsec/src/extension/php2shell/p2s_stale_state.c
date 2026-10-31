// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// CVE-2026-109619 ("state corruption via bailout"), two sub-mitigations:
//   (B) After a fast request shutdown, request-owned engine globals point into
//       the freed request heap. They are reset in post_deactivate (see
//       _reset_request_owned_globals); the last-error strings are released
//       from the sapi_module.deactivate wrapper, which runs after every
//       post_deactivate (see _clear_last_error).
//   (C) Session upload progress is turned off for the startup POST parse, so
//       the session rfc1867 callback does not decode session data (a full
//       unserialize) before RINIT. It is also off inside request_parse_body(),
//       where it could run user code.
//   Both are off on a patched runtime, whatever the mode.

#include "p2s_stale_state.h"

#include <SAPI.h>
#include <main/php_variables.h> // NUM_TRACK_VARS
#include <php.h>
#include <stdatomic.h>
#include <stdlib.h>
#include <string.h> // memset (sub-mitigation B)

#include "../compatibility.h"
#include "../configuration.h"
#include "../ddappsec.h" // TSRMLS_CACHE as local-dynamic: no STATIC_TLS
#include "../logging.h"
#include "../php_compat.h" // NOLINT (must come before any *_arginfo.h)
#include "../php_objects.h"
#include "p2s_hook.h"
#include "p2s_policy.h"

#ifdef TESTING
enum {
    // Recognisable sentinels the post_deactivate_reset testing function
    // writes into the request-owned globals before checking the reset clears
    // them.
    DD_RESET_PROBE_LONG = 0x5151,
    DD_RESET_PROBE_FILL = 0xA5,
    DD_RESET_PROBE_NUM_ERRORS = 7,
};
#endif

typedef typeof(sapi_module.deactivate) dd_sapi_deactivate_fn;
static dd_sapi_deactivate_fn _sapi_deactivate_inner;
// Whether dd_p2s_stale_state_install() ran (the deactivate slot is captured
// even when it held NULL). Written during startup and in MSHUTDOWN only.
static bool _deactivate_captured;
// Whether the displacement of the deactivate slot was logged; process-wide.
static atomic_bool _displaced_logged_deactivate;

#ifdef TESTING
// Sub-mitigation B diagnostics, read by the status() testing function: how
// many times the post_deactivate hook ran the reset (i.e. passed the gate),
// counted only with datadog.appsec.testing on. Debug builds always shut down
// slowly, which leaves the fields clean, so no behaviour shows whether the
// reset ran.
static atomic_uint _post_deactivate_resets_total;
#endif

static void _reset_request_owned_globals(void);
static void _clear_last_error(void);
static bool _sapi_deactivate_hook_active(void);
static int _sapi_deactivate(void);
static bool _sapi_deactivate_clears_last_error(void);
static zend_ini_entry *nullable _upload_progress_entry(void);
#ifdef TESTING
static PHP_FUNCTION(datadog_appsec_testing_php2shell_post_deactivate_reset);
#endif

// Called once, from the zend_extension startup hook (dd_php2shell_startup(),
// after every module MINIT), when the policy allows hooking.
void dd_p2s_stale_state_install(void)
{
    _deactivate_captured = true;
    // Unlike the sapi_module slots, a NULL deactivate is captured too: the
    // wrapper then only clears the last error (see _sapi_deactivate).
    if (sapi_module.deactivate != _sapi_deactivate) {
        _sapi_deactivate_inner = sapi_module.deactivate;
        sapi_module.deactivate = _sapi_deactivate;
    }
}

// Called from MSHUTDOWN (dd_php2shell_mshutdown()), after the guard has reset
// the heap's handlers.
void dd_p2s_stale_state_uninstall(void)
{
    DD_P2S_SLOT_RESTORE(
        sapi_module.deactivate, _sapi_deactivate, _sapi_deactivate_inner);
    _sapi_deactivate_inner = NULL;
    _deactivate_captured = false;
    atomic_store(&_displaced_logged_deactivate, false);
}

void dd_p2s_stale_state_check_deactivate(void)
{
    bool ours = _sapi_deactivate_hook_active();
    // A NULL deactivate slot is temporary: opcache's preload
    // (accel_finish_startup_preload, PHP 7.4+, after our startup hook) sets it
    // to NULL while it runs the preload script in a request, then restores
    // it. That request's post_deactivate sees NULL. It is the only known
    // writer of a temporary NULL, so NULL is not logged (or latched) as
    // displaced. MINFO still reads the live slot.
    if (!ours &&
        __atomic_load_n(&sapi_module.deactivate, __ATOMIC_RELAXED) == NULL) {
        ours = true;
    }
    dd_p2s_hook_check_displaced(_deactivate_captured, ours,
        &_displaced_logged_deactivate, "sapi_module.deactivate",
        DD_P2S_EFFECT_OFF);
}

void dd_p2s_stale_state_minfo_deactivate(void)
{
    dd_p2s_hook_minfo_row("PHP2Shell sapi_module.deactivate hook",
        _deactivate_captured, _sapi_deactivate_hook_active());
}

// Sub-mitigation B (CVE-2026-109619). On an unpatched engine, a release
// build's fast request shutdown leaves the request-owned engine globals reset
// below pointing into the freed request heap, after every request and not
// only after an OOM bailout; an error raised before the next init_executor()
// can then read them. Upstream resets them in shutdown; here it is done in the
// module post_deactivate hook, after shutdown_executor() and while the memory
// manager is still live. Only the fields are written (no free, no destructor),
// so the writes are idempotent after a slow shutdown, which leaves them clean.
// The fields the upstream fix added (delayed_oom, interned_strings_fallible)
// and PG(modules_activated) are left alone. The last-error state is cleared
// later, from the sapi_module.deactivate wrapper (_sapi_deactivate).
//
// B is off on a patched runtime, whatever the mode: the engine does it itself.
void dd_p2s_stale_state_post_deactivate(void)
{
    if (dd_p2s_policy_patched()) {
        return;
    }
    _reset_request_owned_globals();
#ifdef TESTING
    // Only the module hook counts, so post_deactivate_resets reflects real
    // request shutdowns only.
    if (get_global_DD_APPSEC_TESTING()) {
        atomic_fetch_add(&_post_deactivate_resets_total, 1);
    }
#endif
}

// Sub-mitigation C (see dd_p2s_startup_parse_run_window): turn session upload
// progress off by running the session.upload_progress.enabled entry's own
// on_modify handler with "0". That clears the global the session extension
// keeps for it (PS(rfc1867_enabled)), which php_session_rfc1867_callback checks
// first, right after forwarding to the callback it displaced. The entry itself
// (value, modification state) is left alone, so ini_get() and any per-directory
// value are unaffected and nothing is recorded for the INI deactivation;
// dd_p2s_stale_state_upload_progress_restore() runs the handler again with the
// entry's current value. Without the session extension the entry is absent and
// there is nothing to turn off.
//
// Returns whether progress was turned off, i.e. whether the caller must
// restore it with dd_p2s_stale_state_upload_progress_restore().
bool dd_p2s_stale_state_upload_progress_off(void)
{
    zend_ini_entry *entry = _upload_progress_entry();
    if (entry == NULL || entry->value == NULL || entry->on_modify == NULL) {
        return false;
    }
    zend_string *off = zend_string_init(ZEND_STRL("0"), 0);
    bool ok = entry->on_modify(entry, off, entry->mh_arg1, entry->mh_arg2,
                  entry->mh_arg3, ZEND_INI_STAGE_RUNTIME) == SUCCESS;
    zend_string_release(off);
    if (!ok) {
        mlog(dd_log_debug, "PHP2Shell mitigation: could not turn off "
                           "session.upload_progress.enabled");
    }
    return ok;
}

void dd_p2s_stale_state_upload_progress_restore(void)
{
    zend_ini_entry *entry = _upload_progress_entry();
    if (entry == NULL || entry->value == NULL || entry->on_modify == NULL) {
        return;
    }
    if (entry->on_modify(entry, entry->value, entry->mh_arg1, entry->mh_arg2,
            entry->mh_arg3, ZEND_INI_STAGE_RUNTIME) != SUCCESS) {
        mlog(dd_log_warning, "PHP2Shell mitigation: could not restore "
                             "session.upload_progress.enabled");
    }
}

#ifdef TESTING
void dd_p2s_stale_state_testing_status(zval *nonnull status)
{
    add_assoc_long(status, "post_deactivate_resets",
        (zend_long)atomic_load(&_post_deactivate_resets_total));
}
#endif

// The raw resets, mirroring upstream's zend_shutdown_executor_values() and
// php_request_shutdown() for the fields that exist on an unpatched engine. Kept
// separate from the gate so the testing probe can exercise it directly.
static void _reset_request_owned_globals(void)
{
    // Error/exception handlers and their stacks: on fast shutdown
    // zend_shutdown_executor_values() skips the branch that clears these, so
    // zend_error_zstr_at() could read a freed handler before the next
    // init_executor. memset (not zend_stack_destroy) because fast shutdown
    // already released the stack elements in bulk; init_executor re-inits.
    ZVAL_UNDEF(&EG(user_error_handler));
    ZVAL_UNDEF(&EG(user_exception_handler));
    memset(&EG(user_error_handlers_error_reporting), 0,
        sizeof(EG(user_error_handlers_error_reporting)));
    memset(&EG(user_error_handlers), 0, sizeof(EG(user_error_handlers)));
    memset(
        &EG(user_exception_handlers), 0, sizeof(EG(user_exception_handlers)));

    EG(error_handling) = EH_NORMAL;
    EG(exception) = NULL;

#if PHP_VERSION_ID >= 80100
    // Error recording (named-args/attribute diagnostics) -- a stale
    // record_errors with a freed EG(errors) would make the next
    // zend_error_zstr_at erealloc() a dangling pointer. Added in PHP 8.1.
    EG(record_errors) = false;
#    if PHP_VERSION_ID >= 80600
    // A buffer with its capacity now: a stale capacity over a NULL array
    // would make the next record write through it.
    memset(&EG(errors), 0, sizeof(EG(errors)));
#    else
    EG(num_errors) = 0;
    EG(errors) = NULL;
#    endif
#endif

#if PHP_VERSION_ID >= 80500
    ZVAL_UNDEF(&EG(last_fatal_error_backtrace));
#endif

    // Super-globals: php_request_shutdown dtored them but (pre-fix) left the
    // slots dangling. Core already ran the destructors, so only clear the
    // slots -- a second dtor here would double-free.
    for (int i = 0; i < NUM_TRACK_VARS; i++) {
        ZVAL_UNDEF(&PG(http_globals)[i]);
    }

    // Object store: mirrors upstream's fast-shutdown branch in
    // shutdown_executor(). Fast shutdown skips zend_objects_store_destroy(), so
    // object_buckets keeps pointing into the freed request heap; a fatal error
    // before the next init_executor() would have php_error_cb() run
    // zend_objects_store_mark_destructed() over those stale buckets. Only
    // overwrite the fields -- fast shutdown already freed the bucket array in
    // bulk, so no efree/zend_objects_store_destroy here. On a slow shutdown the
    // store is already destroyed and these writes are idempotent;
    // init_executor() -> zend_objects_store_init() re-inits both fields.
    EG(objects_store).object_buckets = NULL;
    EG(objects_store).top = 1;
}

// Last-error state (sub-mitigation B). From PHP 8.0 the message (and from 8.1
// the file) is a zend_string on the request heap, so upstream clears it right
// before shutdown_memory_manager(). php_request_shutdown() already clears it
// before the module post_deactivate hooks; an error raised after that, by a
// post_deactivate hook or sapi_deactivate_module(), would outlive the heap
// reset. This runs from the sapi_module.deactivate wrapper, which
// sapi_deactivate_module() calls last, also when the SAPI's own deactivate
// bails out. Errors raised later, in sapi_deactivate_destroy() or
// php_shutdown_stream_hashes(), are not cleared.
//
// Mirrors main.c's static clear_last_error() for each engine version: before
// 8.0 both fields are strdup()'d char*; in 8.0 the message is a zend_string and
// the file a strdup()'d char*; from 8.1 both are zend_strings.
static void _clear_last_error(void)
{
#if PHP_VERSION_ID < 80000
    if (PG(last_error_message)) {
        free(PG(last_error_message));
        PG(last_error_message) = NULL;
    }
#else
    if (PG(last_error_message)) {
        zend_string_release(PG(last_error_message));
        PG(last_error_message) = NULL;
    }
#endif
#if PHP_VERSION_ID < 80100
    if (PG(last_error_file)) {
        free(PG(last_error_file));
        PG(last_error_file) = NULL;
    }
#else
    if (PG(last_error_file)) {
        zend_string_release(PG(last_error_file));
        PG(last_error_file) = NULL;
    }
#endif
}

// Whether our wrapper occupies the sapi_module.deactivate slot right now.
static bool _sapi_deactivate_hook_active(void)
{
    return __atomic_load_n(&sapi_module.deactivate, __ATOMIC_RELAXED) ==
           _sapi_deactivate;
}

// The sapi_module.deactivate wrapper: releases the last-error strings
// (PG(last_error_*)) before the request heap is freed, so the next request
// cannot read them (sub-mitigation B).
//
// Request shutdown order: RSHUTDOWN, zend_deactivate() (which runs the
// zend_extension deactivate hooks), post_deactivate, then
// sapi_deactivate_module(), which calls this slot last. An error raised in any
// of these is cleared; one raised after, in sapi_deactivate_destroy() or
// php_shutdown_stream_hashes(), is not.
//
// php_module_startup() also calls this slot, outside any request: then it only
// forwards, and must not resolve the mode (php-fpm applies pool env[] later,
// in the workers). SG(sapi_started) is set only once a request has started.
//
// The inner handler can bail out (php-cgi and php-fpm abort when the final
// flush fails, unless ignore_user_abort is set): hence the zend_try, so the
// error is cleared on that path too.
static int _sapi_deactivate(void)
{
    // volatile: assigned inside zend_try (setjmp) and read after it.
    volatile int ret = SUCCESS;
    zend_try
    {
        if (_sapi_deactivate_inner != NULL) {
            ret = _sapi_deactivate_inner();
        }
    }
    zend_catch
    {
        if (_sapi_deactivate_clears_last_error()) {
            _clear_last_error();
        }
        zend_bailout();
    }
    zend_end_try();

    if (_sapi_deactivate_clears_last_error()) {
        _clear_last_error();
    }
    return ret;
}

// The B gate, as in dd_php2shell_post_deactivate, restricted to request
// shutdown (SG(sapi_started)).
static bool _sapi_deactivate_clears_last_error(void)
{
    return !dd_p2s_policy_patched() && SG(sapi_started) &&
           dd_p2s_policy_active();
}

static zend_ini_entry *nullable _upload_progress_entry(void)
{
    return zend_hash_str_find_ptr(
        EG(ini_directives), ZEND_STRL("session.upload_progress.enabled"));
}

#ifdef TESTING
// Test-only, registered only with datadog.appsec.testing on:
// post_deactivate_reset() observes the effect of the B resets, which no real
// request can.
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    _php2shell_reset_arginfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry _php2shell_stale_state_testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "php2shell_mitigation_post_deactivate_reset", PHP_FN(datadog_appsec_testing_php2shell_post_deactivate_reset), _php2shell_reset_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

void dd_p2s_stale_state_register_testing(void)
{
    dd_phpobj_reg_funcs(_php2shell_stale_state_testing_functions);
}

// White-box exercise of sub-mitigation B's post_deactivate reset
// (_reset_request_owned_globals). We cannot reproduce a real post-bailout stale
// state from userland (a debug build always slow-shuts-down, and init_executor
// re-inits every field before any reachable read), so this snapshots the
// request-owned globals, writes recognisable sentinels into them, runs the
// reset, records whether each field came back clean, and RESTORES the
// snapshot so the live request is left untouched. It proves the reset clears
// exactly the fields it must. The last-error clear (_clear_last_error) is not
// covered here: a real request reaches it, see
// php2shell_mitigation_sapi_deactivate_clear_last_error.phpt. No diagnostics
// are emitted between dirtying and restoring, and EG(errors) is pointed at a
// real heap block (never a bogus pointer), so nothing here can fault.
static PHP_FUNCTION(datadog_appsec_testing_php2shell_post_deactivate_reset)
{
    // Calls the raw reset directly. It does not bump the
    // post_deactivate_resets counter: that counts only the module hook at real
    // request shutdowns.
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    // --- snapshot ---
    zval s_err_h = EG(user_error_handler);
    zval s_exc_h = EG(user_exception_handler);
    zend_stack s_errrep = EG(user_error_handlers_error_reporting);
    zend_stack s_errh = EG(user_error_handlers);
    zend_stack s_exch = EG(user_exception_handlers);
    zend_error_handling_t s_eh = EG(error_handling);
    zend_object *s_exc = EG(exception);
    zval s_http[NUM_TRACK_VARS];
    for (int i = 0; i < NUM_TRACK_VARS; i++) {
        s_http[i] = PG(http_globals)[i];
    }
    zend_object **s_obj_buckets = EG(objects_store).object_buckets;
    uint32_t s_obj_top = EG(objects_store).top;
#    if PHP_VERSION_ID >= 80100
    bool s_rec = EG(record_errors);
#        if PHP_VERSION_ID >= 80600
    zend_err_buf s_errs = EG(errors);
#        else
    uint32_t s_num = EG(num_errors);
    zend_error_info **s_errs = EG(errors);
#        endif
#    endif
#    if PHP_VERSION_ID >= 80500
    zval s_lfeb = EG(last_fatal_error_backtrace);
#    endif

    // --- dirty: write sentinels ---
    ZVAL_LONG(&EG(user_error_handler), DD_RESET_PROBE_LONG);
    ZVAL_LONG(&EG(user_exception_handler), DD_RESET_PROBE_LONG);
    memset(&EG(user_error_handlers_error_reporting), DD_RESET_PROBE_FILL,
        sizeof(EG(user_error_handlers_error_reporting)));
    memset(&EG(user_error_handlers), DD_RESET_PROBE_FILL,
        sizeof(EG(user_error_handlers)));
    memset(&EG(user_exception_handlers), DD_RESET_PROBE_FILL,
        sizeof(EG(user_exception_handlers)));
    EG(error_handling) = EH_THROW;
    EG(exception) = (zend_object *)(uintptr_t)1; // sentinel, never dereferenced
    for (int i = 0; i < NUM_TRACK_VARS; i++) {
        ZVAL_LONG(&PG(http_globals)[i], DD_RESET_PROBE_LONG);
    }
    // Sentinel pointer, never dereferenced: nothing between here and the
    // restore creates, releases or walks objects.
    EG(objects_store).object_buckets = (zend_object **)(uintptr_t)1;
    EG(objects_store).top = DD_RESET_PROBE_LONG;
#    if PHP_VERSION_ID >= 80100
    // Point EG(errors) at a heap block (never at a local) so the reset clears a
    // realistic non-NULL pointer and nothing dangling escapes this function.
    zend_error_info **dummy_errs = emalloc(sizeof(*dummy_errs));
    EG(record_errors) = true;
#        if PHP_VERSION_ID >= 80600
    EG(errors).size = DD_RESET_PROBE_NUM_ERRORS;
    EG(errors).capacity = DD_RESET_PROBE_NUM_ERRORS;
    EG(errors).errors = dummy_errs;
#        else
    EG(num_errors) = DD_RESET_PROBE_NUM_ERRORS;
    EG(errors) = dummy_errs;
#        endif
#    endif
#    if PHP_VERSION_ID >= 80500
    ZVAL_LONG(&EG(last_fatal_error_backtrace), DD_RESET_PROBE_LONG);
#    endif

    // --- run the reset under test ---
    _reset_request_owned_globals();

    // --- capture cleanliness ---
    bool ok_err_h = Z_TYPE(EG(user_error_handler)) == IS_UNDEF;
    bool ok_exc_h = Z_TYPE(EG(user_exception_handler)) == IS_UNDEF;
    // The reset memset the stacks to all-zero; the sentinel fill (0xA5) left a
    // large non-zero count, so an empty count is the observable proof the reset
    // ran. (memcmp on the struct would read padding bytes.)
    bool ok_errrep =
        zend_stack_count(&EG(user_error_handlers_error_reporting)) == 0;
    bool ok_errh = zend_stack_count(&EG(user_error_handlers)) == 0;
    bool ok_exch = zend_stack_count(&EG(user_exception_handlers)) == 0;
    bool ok_eh = EG(error_handling) == EH_NORMAL;
    bool ok_exc = EG(exception) == NULL;
    bool ok_http = true;
    for (int i = 0; i < NUM_TRACK_VARS; i++) {
        if (Z_TYPE(PG(http_globals)[i]) != IS_UNDEF) {
            ok_http = false;
        }
    }
    bool ok_objs =
        EG(objects_store).object_buckets == NULL && EG(objects_store).top == 1;
#    if PHP_VERSION_ID >= 80100
#        if PHP_VERSION_ID >= 80600
    bool ok_rec = (EG(record_errors) == false) && (EG(errors).size == 0) &&
                  (EG(errors).capacity == 0) && (EG(errors).errors == NULL);
#        else
    bool ok_rec = (EG(record_errors) == false) && (EG(num_errors) == 0) &&
                  (EG(errors) == NULL);
#        endif
#    endif
#    if PHP_VERSION_ID >= 80500
    bool ok_lfeb = Z_TYPE(EG(last_fatal_error_backtrace)) == IS_UNDEF;
#    endif

    // --- restore the snapshot so the live request is unaffected ---
    EG(user_error_handler) = s_err_h;
    EG(user_exception_handler) = s_exc_h;
    EG(user_error_handlers_error_reporting) = s_errrep;
    EG(user_error_handlers) = s_errh;
    EG(user_exception_handlers) = s_exch;
    EG(error_handling) = s_eh;
    EG(exception) = s_exc;
    for (int i = 0; i < NUM_TRACK_VARS; i++) {
        PG(http_globals)[i] = s_http[i];
    }
    EG(objects_store).object_buckets = s_obj_buckets;
    EG(objects_store).top = s_obj_top;
#    if PHP_VERSION_ID >= 80100
    EG(record_errors) = s_rec;
#        if PHP_VERSION_ID < 80600
    EG(num_errors) = s_num;
#        endif
    EG(errors) = s_errs;
    efree(dummy_errs);
#    endif
#    if PHP_VERSION_ID >= 80500
    EG(last_fatal_error_backtrace) = s_lfeb;
#    endif

    array_init(return_value);
    add_assoc_bool(return_value, "user_error_handler", ok_err_h);
    add_assoc_bool(return_value, "user_exception_handler", ok_exc_h);
    add_assoc_bool(
        return_value, "user_error_handlers_error_reporting", ok_errrep);
    add_assoc_bool(return_value, "user_error_handlers", ok_errh);
    add_assoc_bool(return_value, "user_exception_handlers", ok_exch);
    add_assoc_bool(return_value, "error_handling", ok_eh);
    add_assoc_bool(return_value, "exception", ok_exc);
    add_assoc_bool(return_value, "http_globals", ok_http);
    add_assoc_bool(return_value, "objects_store", ok_objs);
#    if PHP_VERSION_ID >= 80100
    add_assoc_bool(return_value, "error_recording", ok_rec);
#    endif
#    if PHP_VERSION_ID >= 80500
    add_assoc_bool(return_value, "last_fatal_error_backtrace", ok_lfeb);
#    endif
}
#endif // TESTING
