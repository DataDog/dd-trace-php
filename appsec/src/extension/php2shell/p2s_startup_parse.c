// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Windows. Two parses get a window of their own (see
// dd_p2s_startup_parse_run_window): the startup parse of POST, GET and COOKIE
// data through treat_data, before module RINIT; and request_parse_body() (PHP
// 8.4+, see p2s_request_parse_body.c), which builds directly into the $_POST
// and $_FILES slots. parse_str(), SERVER, ENV and treat_data calls after RINIT
// forward with no window (on PHP 7.1+ the request level covers them, see
// p2s_runtime.c). No user
// code runs inside a window: the user error handler is hidden and
// display_errors is off, so parse warnings only reach the log.
// The arming of a window, our Zend MM handlers and the raise are described at
// the top of p2s_oom_guard.c.
//
// Hard limit of a window, computed once at arm (see _hard_limit):
//   min(max(L, S + k x B + 8 MiB), S + max(1 GiB, 2S))
// S is memory_limit, at least one zend_mm chunk (see dd_p2s_guard_soft_limit);
// L is upstream's limit (2S, or S + 1 GiB from S = 1 GiB); B bounds the input
// (post_max_size, or the query string or cookie length; unbounded for
// post_max_size <= 0); k is 6. A multipart/form-data body parse uses k = 11
// and the cap S + max(1 GiB, 4S).

#include "p2s_startup_parse.h"

#include <SAPI.h>
#include <Zend/zend_API.h>
#include <main/php_variables.h>
#include <main/rfc1867.h> // php_rfc1867_callback, rfc1867_post_handler
#include <php.h>
#include <stdatomic.h>
#include <string.h> // strcspn, strlen

#include "../compatibility.h"
#include "../configuration.h"
#include "../ddappsec.h" // TSRMLS_CACHE as local-dynamic: no STATIC_TLS
#include "../logging.h"
#include "../php_compat.h" // NOLINT (must come before any *_arginfo.h)
#include "../php_objects.h"
#include "p2s_hook.h"
#include "p2s_policy.h"
#include "p2s_stale_state.h"

// Hard limit terms (see _hard_limit): growth past the soft limit per byte of
// parse input, and in a multipart/form-data parse (the fixed costs that do not
// scale with the input are DD_PHP2SHELL_HARD_MARGIN, in p2s_oom_guard.h); and
// the cap on the growth term: the larger of DD_PHP2SHELL_HARD_CAP_MIN and
// memory_limit times DD_PHP2SHELL_HARD_CAP_SCALE (_MULTIPART for a multipart
// parse).
#define DD_PHP2SHELL_HARD_K ((size_t)6)
#define DD_PHP2SHELL_HARD_K_MULTIPART ((size_t)11)
#define DD_PHP2SHELL_HARD_CAP_MIN DD_ONE_GIB
#define DD_PHP2SHELL_HARD_CAP_SCALE ((size_t)2)
#define DD_PHP2SHELL_HARD_CAP_SCALE_MULTIPART ((size_t)4)
// The input of the parse has no bound (post_max_size <= 0). No real bound
// equals it: a positive zend_long is at most SIZE_MAX / 2.
#define DD_INPUT_UNBOUNDED ((size_t)-1)

// The handlers we chain to, captured by dd_p2s_startup_parse_install() (see
// dd_php2shell_startup).
typedef void (*dd_treat_data_fn)(int arg, char *str, zval *dest_array);
static dd_treat_data_fn _treat_data_inner;
typedef typeof(sapi_module.input_filter) dd_input_filter_fn;
typedef typeof(sapi_module.input_filter_init) dd_input_filter_init_fn;
static dd_input_filter_fn _input_filter_inner;
static dd_input_filter_init_fn _input_filter_init_inner;
// main/rfc1867.h declares php_rfc1867_callback as returning int below PHP 8.3
// and zend_result from 8.3; the wrapper matches the engine's exact type.
#if PHP_VERSION_ID < 80300
typedef int dd_rfc1867_ret;
#else
typedef zend_result dd_rfc1867_ret;
#endif
typedef dd_rfc1867_ret (*dd_rfc1867_fn)(
    unsigned int event, void *event_data, void **extra);
static dd_rfc1867_fn _rfc1867_inner;
typedef typeof(sapi_module.read_post) dd_read_post_fn;
static dd_read_post_fn _read_post_inner;
// Whether dd_p2s_startup_parse_install() ran (the rfc1867 slot is captured
// even when it held NULL). Written during startup and in MSHUTDOWN only.
static bool _rfc1867_captured;
// Whether the displacement of each slot of this unit was logged; process-wide.
static atomic_bool _displaced_logged_treat_data;
static atomic_bool _displaced_logged_input_filter;
static atomic_bool _displaced_logged_rfc1867;
static atomic_bool _displaced_logged_read_post;
// Whether the expanding filter.default warning was logged; once per process.
static atomic_bool _filter_default_warned;

// The arguments of one treat_data call, passed through
// dd_p2s_startup_parse_run_window().
typedef struct {
    int arg;
    char *str;
    zval *dest;
} dd_treat_data_call;

// Per-request state of the input_filter wrapper, reset by _input_filter_init
// and when a window arms.
typedef struct {
    // Variables dropped in this request.
    size_t dropped;
} dd_input_filter_req;
ZEND_TLS dd_input_filter_req _input_filter_req;

// ---- forward declarations (implementation details) ------------------------

static bool _treat_data_hook_active(void);
static bool _input_filter_hook_active(void);
static bool _input_filter_init_hook_active(void);
static bool _read_post_hook_active(void);
static size_t _read_post(char *buffer, size_t count_bytes);
static bool _read_post_ends_body(void);
// hard limit
static size_t _hard_limit(size_t soft, size_t bound, bool multipart);
static void _warn_filter_default_once(void);
static bool _filter_default_expands(void);
static size_t _post_max_size_bound(zend_long post_max_size);
static size_t _treat_data_bound(int arg);
// armed windows
static void _treat_data(int arg, char *str, zval *dest_array);
static void _treat_data_parse(void *ctx);
// The type of PG(display_errors), which differs across PHP versions.
typedef typeof(((php_core_globals *)NULL)->display_errors) dd_display_errors_t;
static void _leave(bool progress, zval *nonnull error_handler,
    dd_display_errors_t display_errors);
static SAPI_INPUT_FILTER_FUNC(_input_filter);
static unsigned int _input_filter_init(void);
static bool _input_filter_should_drop(
    int arg, const size_t *nullable new_val_len);
static void _input_filter_count_drop(void);
static dd_rfc1867_ret _rfc1867_callback(
    unsigned int event, void *event_data, void **extra);
static bool _rfc1867_hook_active(void);
#ifdef TESTING
static PHP_FUNCTION(datadog_appsec_testing_php2shell_status);
#endif

// ---- hook installation and displacement ------------------------------------

// Called once, from the zend_extension startup hook (dd_php2shell_startup(),
// after every module MINIT), when the policy allows hooking.
void dd_p2s_startup_parse_install(void)
{
    _rfc1867_captured = true;
    dd_treat_data_fn treat_data = sapi_module.treat_data;
    ZEND_ASSERT(treat_data != NULL);
    if (treat_data != NULL && treat_data != _treat_data) {
        _treat_data_inner = treat_data;
        sapi_module.treat_data = _treat_data;
    }

    // input_filter and input_filter_init are saved as one pair, so that
    // ext/filter's per-request init keeps running. Assigned directly rather
    // than through sapi_register_input_filter().
    dd_input_filter_fn filter = sapi_module.input_filter;
    if (filter != NULL && filter != _input_filter) {
        dd_input_filter_init_fn init = sapi_module.input_filter_init;
        // php_module_startup() resets the init slot together with the filter
        // slot (sapi_register_input_filter(php_default_input_filter, NULL)),
        // so our init is never found here while the filter slot is not ours.
        // Should it be, chaining no init loses the displaced one, while
        // chaining ours would recurse without end.
        ZEND_ASSERT(init != _input_filter_init);
        _input_filter_inner = filter;
        _input_filter_init_inner = init == _input_filter_init ? NULL : init;
        sapi_module.input_filter_init = _input_filter_init;
        sapi_module.input_filter = _input_filter;
    }

    // A NULL callback is captured too: with an empty slot,
    // rfc1867_post_handler() raises no event at all, so there would be no
    // FILE_START to skip. The wrapper then answers SUCCESS to every event it
    // does not skip (see _rfc1867_callback). The session extension installs
    // its callback in its MINIT, so it is captured here and chained.
    if (php_rfc1867_callback != _rfc1867_callback) {
        _rfc1867_inner = php_rfc1867_callback;
        php_rfc1867_callback = _rfc1867_callback;
    }

    // Only the excluded SAPIs (cli, embed, phpdbg, see _sapi_excluded in
    // p2s_policy.c) have no read_post. Should another, the slot stays NULL
    // (sapi_read_post_block() treats it as a SAPI without a body) and has no
    // MINFO row.
    // php_module_startup() copied the SAPI's final slots (e.g. php-cgi's
    // FastCGI reader) before this runs.
    dd_read_post_fn read_post = sapi_module.read_post;
    ZEND_ASSERT(read_post != NULL);
    if (read_post != NULL && read_post != _read_post) {
        _read_post_inner = read_post;
        sapi_module.read_post = _read_post;
    }
}

// Called from MSHUTDOWN (dd_php2shell_mshutdown()), after the guard has reset
// the heap's handlers.
void dd_p2s_startup_parse_uninstall(void)
{
    DD_P2S_SLOT_RESTORE(
        sapi_module.treat_data, _treat_data, php_default_treat_data);
    DD_P2S_SLOT_RESTORE(
        sapi_module.input_filter, _input_filter, php_default_input_filter);
    DD_P2S_SLOT_RESTORE(sapi_module.input_filter_init, _input_filter_init,
        (dd_input_filter_init_fn)NULL);
    // NULL, not the captured callback: no request runs after MSHUTDOWN, and
    // the owner (the session extension) reinstalls its callback in its next
    // MINIT. When session shuts down first (e.g. PHP < 7.4 with session and
    // hash shared and hash.so loaded after ddappsec: session depends on hash),
    // it sees our wrapper, leaves the slot alone and clears its saved pointer;
    // writing its callback back would make it chain to itself (or to its
    // unmapped old copy) after a restart that keeps libphp's statics, e.g. an
    // Apache graceful restart. When session shuts down after us, it finds
    // NULL and leaves it, the same end state it produces itself.
    DD_P2S_SLOT_RESTORE(
        php_rfc1867_callback, _rfc1867_callback, (dd_rfc1867_fn)NULL);
    DD_P2S_SLOT_RESTORE(sapi_module.read_post, _read_post, _read_post_inner);
    _treat_data_inner = NULL;
    _input_filter_inner = NULL;
    _input_filter_init_inner = NULL;
    _rfc1867_inner = NULL;
    _read_post_inner = NULL;
    _rfc1867_captured = false;
    atomic_store(&_displaced_logged_treat_data, false);
    atomic_store(&_displaced_logged_input_filter, false);
    atomic_store(&_displaced_logged_rfc1867, false);
    atomic_store(&_displaced_logged_read_post, false);
}

void dd_p2s_startup_parse_check_treat_data(void)
{
    dd_p2s_hook_check_displaced(_treat_data_inner != NULL,
        _treat_data_hook_active(), &_displaced_logged_treat_data,
        "sapi_module.treat_data", DD_P2S_EFFECT_OFF);
}

void dd_p2s_startup_parse_check_input_filter(void)
{
    dd_p2s_hook_check_displaced(_input_filter_inner != NULL,
        _input_filter_hook_active() && _input_filter_init_hook_active(),
        &_displaced_logged_input_filter, "sapi_module.input_filter",
        DD_P2S_EFFECT_OFF);
}

void dd_p2s_startup_parse_check_rfc1867(void)
{
    dd_p2s_hook_check_displaced(_rfc1867_captured, _rfc1867_hook_active(),
        &_displaced_logged_rfc1867, "php_rfc1867_callback", DD_P2S_EFFECT_OFF);
}

void dd_p2s_startup_parse_check_read_post(void)
{
    dd_p2s_hook_check_displaced(_read_post_inner != NULL,
        _read_post_hook_active(), &_displaced_logged_read_post,
        "sapi_module.read_post", DD_P2S_EFFECT_OFF);
}

void dd_p2s_startup_parse_minfo_treat_data(void)
{
    dd_p2s_hook_minfo_row("PHP2Shell treat_data hook",
        _treat_data_inner != NULL, _treat_data_hook_active());
}

void dd_p2s_startup_parse_minfo_input_filter(void)
{
    dd_p2s_hook_minfo_row("PHP2Shell input_filter hook",
        _input_filter_inner != NULL,
        _input_filter_hook_active() && _input_filter_init_hook_active());
}

void dd_p2s_startup_parse_minfo_rfc1867(void)
{
    dd_p2s_hook_minfo_row("PHP2Shell php_rfc1867_callback hook",
        _rfc1867_captured, _rfc1867_hook_active());
}

void dd_p2s_startup_parse_minfo_read_post(void)
{
    // Not captured only on a SAPI without read_post, which none of those not
    // excluded is (see dd_p2s_startup_parse_install): no row then.
    if (_read_post_inner == NULL) {
        return;
    }
    dd_p2s_hook_minfo_row(
        "PHP2Shell sapi_module.read_post hook", true, _read_post_hook_active());
}

// ---- hard limit and windows -----------------------------------------------

// The hard limit of a window with soft limit `soft` (S), whose input is at
// most `bound` bytes (B) and which, with `multipart`, parses a
// multipart/form-data body. Computed once, at arm:
//
//   hard = min(max(L, S + k x B + margin), S + C)
//
// Growth past the soft limit is at most k bytes per input byte, plus fixed
// costs (DD_PHP2SHELL_HARD_MARGIN). k is DD_PHP2SHELL_HARD_K (6): with our
// input_filter wrapper in its slot and an unset or unsafe_raw filter.default,
// the worst growth measured is 6 bytes per input byte. A multipart parse uses
// DD_PHP2SHELL_HARD_K_MULTIPART (11): a file part whose name was read below the
// soft limit is registered whole, and with opcache its $_FILES entries make up
// to 11 more copies of the name (13 in all on PHP 8.1+, 12 on 7.x and 8.0);
// the name is at most B bytes. Later file parts are skipped (see
// _rfc1867_callback). A displaced input_filter wrapper and an expanding
// filter.default are not accounted for (see _warn_filter_default_once).
// L is upstream's limit (dd_p2s_guard_hard_limit_floor). C = max(1 GiB, 2S) is
// a constant cap; max(1 GiB, 4S) for a multipart parse, where the name of that
// file part can be up to about S/3 bytes. Every term saturates, so an unbounded
// B (DD_INPUT_UNBOUNDED, post_max_size <= 0), or one with k x B beyond a
// size_t, gives S + C: a larger B never lowers the limit. SIZE_MAX for "no
// limit" (soft == SIZE_MAX).
// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static size_t _hard_limit(size_t soft, size_t bound, bool multipart)
{
    if (soft == SIZE_MAX) {
        return SIZE_MAX;
    }
    size_t k = multipart ? DD_PHP2SHELL_HARD_K_MULTIPART : DD_PHP2SHELL_HARD_K;
    size_t growth =
        dd_p2s_sat_add(dd_p2s_sat_mul(k, bound), DD_PHP2SHELL_HARD_MARGIN);
    size_t cap = MAX(DD_PHP2SHELL_HARD_CAP_MIN,
        dd_p2s_sat_mul(multipart ? DD_PHP2SHELL_HARD_CAP_SCALE_MULTIPART
                                 : DD_PHP2SHELL_HARD_CAP_SCALE,
            soft));
    return MIN(
        MAX(dd_p2s_guard_hard_limit_floor(soft), dd_p2s_sat_add(soft, growth)),
        dd_p2s_sat_add(soft, cap));
}

// Whether the request body is multipart/form-data, i.e. parsed by the rfc1867
// POST handler, at startup or in request_parse_body(). The content type is
// matched as sapi_read_post_data() matches it to a POST handler: the media
// type up to the first ';', ',' or ' ', compared case-insensitively.
bool dd_p2s_startup_parse_multipart_body(void)
{
    const char *content_type = SG(request_info).content_type;
    if (content_type == NULL) {
        return false;
    }
    size_t len = strcspn(content_type, ";, ");
    return zend_binary_strcasecmp(
               content_type, len, ZEND_STRL("multipart/form-data")) == 0;
}

// Log, once per process, that the hard limit does not account for an
// expanding filter.default: ext/filter's copies of each value are not bounded
// by k, so a body within post_max_size can reach the hard limit (exit 137)
// instead of the deferred fatal. Called when a window arms, where the
// request's own value (including per-directory settings) is in force; after
// the first warning it is a single relaxed load.
static void _warn_filter_default_once(void)
{
    if (EXPECTED(atomic_load_explicit(
            &_filter_default_warned, memory_order_relaxed))) {
        return;
    }
    if (!_filter_default_expands() ||
        atomic_exchange_explicit(
            &_filter_default_warned, true, memory_order_relaxed)) {
        return;
    }
    mlog(dd_log_warning,
        "PHP2Shell mitigation: filter.default is set to a filter that can "
        "expand request values; the PHP2Shell memory bound does not account "
        "for it, so a request body within post_max_size can reach the hard "
        "memory limit");
}

// Whether ext/filter's default filter may make a value longer. ext/filter
// passes values through unchanged only for unsafe_raw (its default),
// whatever filter.default_flags says. Every other value is treated as
// expanding: the sanitizing filters that can (special_chars,
// full_special_chars, string, stripped, encoded, add_slashes) and,
// conservatively, the ones that cannot and the unknown names ext/filter maps
// to unsafe_raw. The entry is absent without ext/filter.
static bool _filter_default_expands(void)
{
    zend_ini_entry *entry =
        zend_hash_str_find_ptr(EG(ini_directives), ZEND_STRL("filter.default"));
    if (entry == NULL || entry->value == NULL || ZSTR_LEN(entry->value) == 0) {
        return false;
    }
    return !zend_string_equals_literal_ci(entry->value, "unsafe_raw");
}

// The input bound for a post_max_size value: "no limit" for <= 0.
static size_t _post_max_size_bound(zend_long post_max_size)
{
    return post_max_size > 0 ? (size_t)post_max_size : DD_INPUT_UNBOUNDED;
}

// The input bound of a POST body parse that applies `post_max_size`: that
// value, or the bytes of the body read so far if more. "No limit" stays so.
// A body already read can be larger than the value the parse applies:
// - sapi_activate() reads a urlencoded body under the php.ini post_max_size,
//   and only then does sapi_module.activate() apply the per-directory
//   settings (.user.ini, [PATH=] and [HOST=] sections), which can lower
//   post_max_size below what was read.
// - request_parse_body() with a lower post_max_size option: its reader warns
//   and returns without replacing a body read before (at startup or through
//   php://input), and the POST handler then parses that whole body.
// A multipart body is read during the parse, under the value in force then
// (nothing of it is read before).
size_t dd_p2s_startup_parse_post_body_bound(zend_long post_max_size)
{
    size_t bound = _post_max_size_bound(post_max_size);
    if (bound == DD_INPUT_UNBOUNDED || SG(read_post_bytes) <= 0) {
        return bound;
    }
    return MAX(bound, (size_t)SG(read_post_bytes));
}

// ---- the SAPI treat_data wrapper -------------------------------------------

static void _treat_data(int arg, char *str, zval *dest_array)
{
    // Only the startup parse of external request data (POST=0, GET=1,
    // COOKIE=2), before module RINIT, is armed. parse_str(), SERVER, ENV and
    // any later call forward unarmed, as does a parse nested in an armed one,
    // and every parse while the WAF's decision disarms (dd_p2s_policy_armed).
    if (arg > PARSE_COOKIE || PG(modules_activated) ||
        dd_p2s_guard_in_window() || !dd_p2s_policy_armed()) {
        _treat_data_inner(arg, str, dest_array);
        return;
    }

    dd_treat_data_call call = {.arg = arg, .str = str, .dest = dest_array};
    dd_p2s_startup_parse_run_window(_treat_data_parse, &call,
        arg == PARSE_POST && !dd_p2s_policy_patched(), _treat_data_bound(arg),
        arg == PARSE_POST && dd_p2s_startup_parse_multipart_body(),
        DD_PHASE_STARTUP);
}

static void _treat_data_parse(void *ctx)
{
    dd_treat_data_call *call = ctx;
    _treat_data_inner(call->arg, call->str, call->dest);
}

// The bound on the input bytes of one startup treat_data parse. post_max_size
// bounds only the POST body (see dd_p2s_startup_parse_post_body_bound); the
// query string and the cookie header are already in memory, with their exact
// length.
static size_t _treat_data_bound(int arg)
{
    ZEND_ASSERT(arg == PARSE_POST || arg == PARSE_GET || arg == PARSE_COOKIE);
    const char *input;
    switch (arg) {
    case PARSE_POST:
        return dd_p2s_startup_parse_post_body_bound(SG(post_max_size));
    case PARSE_GET:
        input = SG(request_info).query_string;
        break;
    default: // PARSE_COOKIE
        input = SG(request_info).cookie_data;
        break;
    }
    return input != NULL ? strlen(input) : 0;
}

// Run one request-data parse, the window, then raise the deferred
// out-of-memory error, if any, once `parse` has returned (a safe point: the
// parsed arrays are complete). The caller has checked the gate.
//
// No user code runs inside the window (option R): the user error handler is
// hidden and display_errors is off, so parse warnings (max_input_vars,
// max_input_nesting_level, request_parse_body() options, max_file_uploads)
// only reach the log. A displayed warning is output, and output can run user
// code (an output buffer callback), which could change memory_limit while the
// sentinel is in place. With `progress_off`, session upload progress is
// turned off for the duration of the parse (sub-mitigation C, and R for
// request_parse_body()); turning it off allocates, so it precedes the arm.
//
// `bound` and `multipart` describe the input, for the hard limit (see
// _hard_limit). The soft/hard emulation is armed only on a real zend_mm heap
// with a finite memory_limit: otherwise the engine enforces no limit and there
// is nothing to defer. The rest of the window applies regardless. A pending
// error is raised only by a window that armed; one carried in from the armed
// request level is raised at the window's end. `phase` names the window in
// the deferred metric and the log line.
// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void dd_p2s_startup_parse_run_window(dd_parse_fn parse, void *ctx,
    bool progress_off, size_t bound, bool multipart, dd_deferred_phase phase)
{
    bool progress = progress_off && dd_p2s_stale_state_upload_progress_off();
    zval error_handler;
    ZVAL_COPY_VALUE(&error_handler, &EG(user_error_handler));
    ZVAL_UNDEF(&EG(user_error_handler));
    dd_display_errors_t display_errors = PG(display_errors);
    PG(display_errors) = 0;
    // Whether the request level was armed when the window began; only a
    // request_parse_body() window can find it so.
    bool nested = false;
    bool armed = false;
    if (dd_p2s_guard_available() && PG(memory_limit) > 0) {
        _warn_filter_default_once();
        size_t soft = dd_p2s_guard_soft_limit();
        _input_filter_req.dropped = 0;
        armed = dd_p2s_guard_window_begin(
            soft, _hard_limit(soft, bound, multipart), &nested);
    }

    zend_try { parse(ctx); }
    zend_catch
    {
        // Some other fatal fired during the parse (e.g. the OS refused an
        // allocation): restore everything and let the original bailout
        // continue to the request handler. Usage may be above the soft limit
        // here, where the configured limit cannot be restored; shutdown code
        // (user shutdown functions after a runtime request_parse_body()) must
        // not run on the sentinel.
        if (armed) {
            dd_p2s_guard_window_abort();
        }
        _leave(progress, &error_handler, display_errors);
        zend_bailout();
    }
    zend_end_try();

    if (armed && dd_p2s_guard_error_pending()) {
        // $_POST (and friends) are fully built and internally consistent here.
        dd_p2s_guard_window_stop();
        _leave(progress, &error_handler, display_errors);
        dd_p2s_guard_raise(phase, _input_filter_req.dropped);
    }

    if (armed) {
        dd_p2s_guard_window_end(nested);
    }
    _leave(progress, &error_handler, display_errors);
}

// Undo what dd_p2s_startup_parse_run_window() changed on entry, other than the
// arming: the user error handler, display_errors and, if `progress`, session
// upload progress. Runs before every exit from the window, including the
// noreturn ones.
static void _leave(bool progress, zval *nonnull error_handler,
    dd_display_errors_t display_errors)
{
    ZVAL_COPY_VALUE(&EG(user_error_handler), error_handler);
    PG(display_errors) = display_errors;
    if (progress) {
        dd_p2s_stale_state_upload_progress_restore();
    }
}

// Whether our wrapper occupies the treat_data slot right now.
static bool _treat_data_hook_active(void)
{
    return DD_P2S_SLOT_HOLDS(sapi_module.treat_data, _treat_data);
}

// Whether our wrapper occupies the sapi_module.read_post slot right now.
static bool _read_post_hook_active(void)
{
    return DD_P2S_SLOT_HOLDS(sapi_module.read_post, _read_post);
}

// ---- the sapi_module.read_post wrapper -------------------------------------
//
// The unpatched multipart parser keeps every header line of a part until the
// next part, at far more than its size in the body (a 2-byte ":" line keeps
// about 300 bytes on 7.4 and 8.3), and calls neither input_filter nor
// the rfc1867 callback before the headers are complete. After a soft-limit
// crossing, such a header section would grow to the hard limit. Once the
// deferred error is pending in an armed multipart parse, the wrapper reports
// the end of the body (0) without forwarding: the parser consumes only the
// data already in its buffer (FILLUNIT, 5 KiB, for a usual boundary), the
// parse ends, and the deferred error is raised. Patched PHP also stops the
// header loop at the crossing.
//
// On PHP 7.0 to 8.5, rfc1867's fill_buffer() is the only reader of the body
// during a multipart parse, and it calls sapi_module.read_post directly. A 0
// is the short read a SAPI returns at the end of its input, as for a body the
// client cut short: every read loop of the parser stops on it, and the
// multipart buffer is freed when the handler returns. fill_buffer() counts
// only positive reads, so SG(read_post_bytes) and SG(post_read) are
// unchanged: if the request shuts down, sapi_deactivate_module() drains the
// rest of the body through the wrapper, which then forwards, as after the
// engine's own out-of-memory bailout; no unread input is left for a later
// request on the same connection.
static size_t _read_post(char *buffer, size_t count_bytes)
{
    if (_read_post_ends_body()) {
        return 0;
    }
    return _read_post_inner(buffer, count_bytes);
}

// Whether the wrapper reports the end of the body: an armed window with a
// pending deferred error, while the multipart parser reads the body.
// sapi_read_post_data() set the request's post entry before the handler ran.
// Every other read forwards, among them a urlencoded body that
// request_parse_body() reads inside its window (sapi_read_standard_form_data()
// through sapi_read_post_block(), which would mark the body as fully read).
static bool _read_post_ends_body(void)
{
    if (!dd_p2s_guard_error_pending()) {
        return false;
    }
    const sapi_post_entry *entry = SG(request_info).post_entry;
    return entry != NULL && entry->post_handler == rfc1867_post_handler;
}

// ---- the input_filter wrapper ----------------------------------------------
//
// The post-parse safe point leaves the parse running after the soft limit is
// crossed, so a body of many small variables keeps growing until the parse
// ends or the hard limit terminates the process. sapi_module.input_filter is
// called once per variable before it is registered (php_variables.c for
// GET/COOKIE/parse_str() and urlencoded POST, rfc1867.c for multipart form
// fields, mbstring's encoding handler). Once the deferred error is pending,
// the wrapper drops every later variable of the armed parse: it returns 0, so
// the caller frees the value and registers nothing, and the growth past
// memory_limit is bounded by the variable in flight when the limit was
// crossed. The deferred error is raised at the end of the parse as before, so
// no PHP code other than a shutdown function sees the partial arrays; patched
// PHP also leaves them partial. Dropped variables are not forwarded to the
// filter we displaced (ext/filter keeps its own copies of what it sees).
//
// The wrapper chains both slots: input_filter and input_filter_init are saved
// as one pair, so ext/filter's per-request init keeps running. It is installed
// once, at startup, and never re-installed (see
// dd_p2s_startup_parse_install). File parts never reach input_filter.

static SAPI_INPUT_FILTER_FUNC(_input_filter)
{
    if (_input_filter_should_drop(arg, new_val_len)) {
        // The caller owns *val and frees it; no caller reads *new_val_len
        // after a 0. Neither is touched, and nothing is allocated here.
        _input_filter_count_drop();
        return 0;
    }
    if (_input_filter_inner == NULL) {
        // As php_default_input_filter.
        if (new_val_len != NULL) {
            *new_val_len = val_len;
        }
        return 1;
    }
    // Exactly the inner result: ext/filter returns 0 for request data it
    // registered itself and 1 with a replaced *val for parse_str().
    return _input_filter_inner(arg, var, val, val_len, new_val_len);
}

// Whether the wrapper drops this variable. Only while an armed parse has a
// pending deferred error, and only request data (PARSE_POST, GET, COOKIE);
// parse_str(), SERVER, ENV and SESSION always pass through. A NULL
// new_val_len is sapi_getenv(), which ignores the result.
static bool _input_filter_should_drop(
    int arg, const size_t *nullable new_val_len)
{
    return dd_p2s_guard_error_pending() && arg <= PARSE_COOKIE &&
           new_val_len != NULL;
}

// Count one dropped variable in this request (reported by dd_p2s_guard_raise).
// Does not allocate or log.
static void _input_filter_count_drop(void) { _input_filter_req.dropped++; }

// sapi_module.input_filter_init, called once per request by sapi_activate()
// before any request variable is parsed: reset the per-request state, then run
// the init we displaced (ext/filter's resets its per-request arrays).
static unsigned int _input_filter_init(void)
{
    _input_filter_req = (dd_input_filter_req){0};
    if (_input_filter_init_inner != NULL) {
        return _input_filter_init_inner();
    }
    return 0;
}

// Whether our wrapper occupies the input_filter slot right now (MINFO and the
// displacement check).
static bool _input_filter_hook_active(void)
{
    return __atomic_load_n(&sapi_module.input_filter, __ATOMIC_RELAXED) ==
           _input_filter;
}

// Whether our init occupies the input_filter_init slot right now.
static bool _input_filter_init_hook_active(void)
{
    return __atomic_load_n(&sapi_module.input_filter_init, __ATOMIC_RELAXED) ==
           _input_filter_init;
}

// ---- the rfc1867 callback wrapper ------------------------------------------
//
// File parts never reach input_filter, so after a crossing the parts that
// follow still register $_FILES entries (and, up to max_file_uploads, create
// temp files). Empty-filename parts are not counted against max_file_uploads,
// so a body of many of them keeps growing the heap until the parse ends or the
// hard limit terminates the process. rfc1867_post_handler() raises
// MULTIPART_EVENT_FILE_START for every file part not already skipped, after
// parsing its headers and before it creates the temp file or registers
// anything for the part. On FAILURE it frees the part's name and filename and
// moves to the next part: the part's body is scanned and discarded, and the
// part gets no other event. Once the deferred error is pending in an armed
// window, the wrapper returns FAILURE at FILE_START without forwarding, so the
// growth past memory_limit is bounded by the file part in flight when the
// limit was crossed. A skipped part still counts against
// max_multipart_body_parts, and nothing is logged for it.
//
// Every other event, and FILE_START otherwise, is forwarded to the callback
// captured at startup, or answered with SUCCESS if there was none. A callback
// thus sees each file part whole (FILE_START, FILE_DATA..., FILE_END) or not
// at all, even when the error becomes pending in the middle of a part. On an
// unpatched runtime, sub-mitigation C keeps session upload progress off in an
// armed multipart parse, so the session callback returns before it handles any
// event there.
static dd_rfc1867_ret _rfc1867_callback(
    unsigned int event, void *event_data, void **extra)
{
    if (event == MULTIPART_EVENT_FILE_START && dd_p2s_guard_error_pending()) {
        return FAILURE;
    }
    if (_rfc1867_inner == NULL) {
        return SUCCESS;
    }
    return _rfc1867_inner(event, event_data, extra);
}

// Whether our wrapper occupies the php_rfc1867_callback slot right now.
static bool _rfc1867_hook_active(void)
{
    return __atomic_load_n(&php_rfc1867_callback, __ATOMIC_RELAXED) ==
           _rfc1867_callback;
}

// ---- testing functions -----------------------------------------------------

#ifdef TESTING
// Test-only, registered only with datadog.appsec.testing on: status()
// observes what no real request can, the hard limit (goal E keeps real bodies
// below it) and the post_deactivate counter.
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    _php2shell_status_arginfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry _php2shell_startup_parse_testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "php2shell_mitigation_status", PHP_FN(datadog_appsec_testing_php2shell_status), _php2shell_status_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

void dd_p2s_startup_parse_register_testing(void)
{
    dd_phpobj_reg_funcs(_php2shell_startup_parse_testing_functions);
}

static PHP_FUNCTION(datadog_appsec_testing_php2shell_status)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    // The hard limit a startup POST parse of this request's body would get
    // now. -1 for "no limit", including values a zend_long cannot hold.
    size_t soft = dd_p2s_guard_soft_limit();
    size_t hard = _hard_limit(soft, _treat_data_bound(PARSE_POST),
        dd_p2s_startup_parse_multipart_body());

    array_init(return_value);
    add_assoc_long(return_value, "hard_limit",
        hard > (size_t)ZEND_LONG_MAX ? -1 : (zend_long)hard);
    dd_p2s_stale_state_testing_status(return_value);
}
#endif // TESTING
