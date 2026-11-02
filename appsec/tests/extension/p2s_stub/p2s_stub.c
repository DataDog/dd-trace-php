// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Test-only PHP extension for the PHP2Shell mitigation tests
// (tests/extension/php2shell_mitigation_*.phpt). It is built next to the test
// extensions and never packaged.
//
// ext/filter and mbstring are built statically in the CI images, so they
// always initialise before ddtrace.so. Loaded after ddtrace.so, this stub
// stands in for a module that writes the request-variable hooks later:
//   p2s_stub.minit=1    in MINIT, register an input_filter pair that does not
//                       chain (as ext/filter does), a treat_data handler
//                       that delegates to php_default_treat_data (as
//                       mbstring does), a read_post handler and a
//                       php_rfc1867_callback that forward to the previous
//                       ones. AppSec captures the hooks after every MINIT,
//                       so it captures these;
//   p2s_stub.startup=1  do the same writes from a zend_extension startup hook
//                       registered in MINIT, which runs after AppSec's own
//                       startup hook: the hooks are displaced after capture;
//   p2s_stub.post_deactivate_error=1
//                       raise an E_WARNING from post_deactivate, after
//                       php_request_shutdown() cleared PG(last_error_*);
//   p2s_stub.rinit_alloc=N
//                       allocate (and free) N bytes in RINIT, inside a
//                       zend_try. Loaded after ddtrace.so, this runs after
//                       the ddtrace RINIT created the root span, while
//                       PG(modules_activated) is still 0. The bailout of
//                       a memory error is caught, so the request goes on and
//                       its script can inspect what the error left on the span
//                       (an observation device: a real module's RINIT would
//                       fail the request startup). Prints
//                       "P2S_STUB RINIT alloc bailed=0|1" to stderr;
//   p2s_stub.error_cb_swallow=1
//                       from MINIT, wrap zend_error_cb so that an E_ERROR
//                       is not chained, but printed to stderr as
//                       "P2S_STUB swallowed E_ERROR: <message>" and returned
//                       from, as Pinpoint's callback does for a type that
//                       error_reporting masks.
//
// Switches for the lifecycle of AppSec's request-level Zend MM handlers
// (php2shell_mitigation_lifecycle_fallbacks.phpt):
//   p2s_stub.rinit_bailout=1
//                       bail out in the RINIT of the first request only.
//                       Loaded after ddtrace.so, this runs after AppSec's
//                       RINIT; on PHP 8.x no RSHUTDOWN then runs
//                       (PG(modules_activated) stays 0). Only once: the
//                       modules whose RINIT ran too keep request state that
//                       only their RSHUTDOWN clears;
//   p2s_stub.rshutdown_bailout=1
//                       bail out in every RSHUTDOWN. Loaded after ddtrace.so,
//                       this RSHUTDOWN runs before AppSec's, which PHP 7.x
//                       then skips (one zend_try around the whole loop);
//   p2s_stub.activate_mm=1
//                       stand in for the allocation profiler's per-request
//                       handlers: install forwarding Zend MM handlers from a
//                       zend_extension activate hook (before every RINIT, so
//                       below AppSec's), chaining over whatever the slot
//                       holds, even this stub's own handlers, as the
//                       profiler's RINIT does; remove them from the
//                       zend_extension deactivate hook (inside
//                       zend_deactivate(), before every post_deactivate) only
//                       if they are on top, else give up, as the profiler's
//                       fallback does;
//   p2s_stub_retain($object)
//                       a PHP function that keeps a reference to $object
//                       until this stub's post_deactivate releases it, as
//                       some agents do with an exception they recorded
//                       (Elastic APM 1.11.0-1.17.0). Loaded before
//                       ddtrace.so, that post_deactivate runs after AppSec's.
//                       Before the release, it prints
//                       "P2S_STUB release store_reset=0|1" to stderr: 1 if
//                       the object store was already reset (its top is
//                       back to 1, though the object has a handle);
//   p2s_stub_mm_install()
//                       a PHP function that installs forwarding Zend MM
//                       handlers over the current ones at runtime (false if
//                       this stub's are installed already). Unless
//                       p2s_stub.mm_keep=1, the stub's RSHUTDOWN restores
//                       what it saved, if its handlers are on top; loaded
//                       before ddtrace.so, that RSHUTDOWN runs after
//                       AppSec's. With p2s_stub.mm_keep=1 they are never
//                       removed during a request and stay across requests;
//   p2s_stub.mm_drop=1  with p2s_stub.mm_keep=1: from the zend_extension
//                       activate hook (before every RINIT), if the stub's
//                       handlers kept from an earlier request are on top,
//                       reset the heap to plain zend_mm without putting back
//                       what they chained to, as an extension that removes
//                       itself and drops the handlers under it.
//
// Switch for AppSec's zend_interrupt_function (PHP 7.1+,
// php2shell_mitigation_interrupt_reentry.phpt):
//   p2s_stub.interrupt=1
//                       in MINIT, chain a zend_interrupt_function under
//                       AppSec's (installed after every MINIT) that calls
//                       the previous one, then the callback set with
//                       p2s_stub_interrupt_callback($callable) in this
//                       request, as Excimer and pcntl run PHP code from
//                       theirs; p2s_stub_vm_interrupt() sets
//                       EG(vm_interrupt), as their timers and signals do.
//
// Switch for AppSec's gc_mem_caches() wrapper (PHP 7.1-8.3,
// php2shell_mitigation_gc_mem_caches_replaced.phpt):
//   p2s_stub.gc_mem_caches=1|2
//                       replace the handler of gc_mem_caches() with one that
//                       only forwards, as another extension's wrapper that
//                       does not turn use_custom_heap off would: 1 from MINIT
//                       (before AppSec's startup capture), 2 from a
//                       zend_extension startup hook registered in MINIT
//                       (after it).
// MSHUTDOWN resets the heap to plain zend_mm if the stub's handlers are still
// on top, so that no handler outlives the DSO.
//
// The input_filter behaves as php_default_input_filter, except that it
// replaces the first byte of the variable p2s_tag with 'X'. It counts its
// calls for request variables only (POST, GET, COOKIE: not the PARSE_STRING
// calls of sapi_getenv() or parse_str(), nor the PARSE_SERVER calls of
// php-cgi's environment import), the input_filter_init, treat_data and
// read_post calls, and the MULTIPART_EVENT_FILE_START events its rfc1867
// callback receives. MSHUTDOWN prints
// "P2S_STUB filter=N init=M treat=K read=R file_start=F" to stderr (php-cgi
// runs it even after a failed request startup) and restores the slots it
// wrote, if they still hold its handlers.

#include <SAPI.h>
#include <main/php_variables.h>
#include <main/rfc1867.h>
#include <php.h>
#include <stdbool.h>
#include <stdio.h>
#include <string.h>
#include <zend_extensions.h>

#define P2S_STUB_NAME "p2s_stub"
#define P2S_STUB_VERSION "0.1.0"
#define P2S_STUB_TAG "p2s_tag"

// main/rfc1867.h declares php_rfc1867_callback as returning int below PHP 8.3
// and zend_result from 8.3.
#if PHP_VERSION_ID < 80300
typedef int p2s_rfc1867_ret;
#else
typedef zend_result p2s_rfc1867_ret;
#endif
typedef p2s_rfc1867_ret (*p2s_rfc1867_fn)(
    unsigned int event, void *event_data, void **extra);

// The Zend MM custom handlers take the caller's file and line from PHP 8.4 on,
// which also adds the gc and shutdown handlers.
#if PHP_VERSION_ID >= 80400
#    define P2S_MM_DC ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC
#    define P2S_MM_CC ZEND_FILE_LINE_RELAY_CC ZEND_FILE_LINE_ORIG_RELAY_CC
typedef size_t (*p2s_mm_gc_fn)(void);
typedef void (*p2s_mm_shutdown_fn)(bool, bool);
#else
#    define P2S_MM_DC
#    define P2S_MM_CC
#endif
#if PHP_VERSION_ID < 80000
#    define P2S_ERROR_CB_PARAMS                                                \
        int type, const char *error_filename, const uint32_t error_lineno,     \
            const char *format, va_list args
#    define P2S_ERROR_CB_PASSTHRU type, error_filename, error_lineno, format, args
#elif PHP_VERSION_ID < 80100
#    define P2S_ERROR_CB_PARAMS                                                \
        int type, const char *error_filename, const uint32_t error_lineno,     \
            zend_string *message
#    define P2S_ERROR_CB_PASSTHRU type, error_filename, error_lineno, message
#else
#    define P2S_ERROR_CB_PARAMS                                                \
        int type, zend_string *error_filename, const uint32_t error_lineno,    \
            zend_string *message
#    define P2S_ERROR_CB_PASSTHRU type, error_filename, error_lineno, message
#endif

typedef void *(*p2s_mm_malloc_fn)(size_t P2S_MM_DC);
typedef void (*p2s_mm_free_fn)(void *P2S_MM_DC);
typedef void *(*p2s_mm_realloc_fn)(void *, size_t P2S_MM_DC);

static PHP_MINIT_FUNCTION(p2s_stub);
static PHP_MSHUTDOWN_FUNCTION(p2s_stub);
static PHP_RINIT_FUNCTION(p2s_stub);
static PHP_RSHUTDOWN_FUNCTION(p2s_stub);
static ZEND_MODULE_POST_ZEND_DEACTIVATE_D(p2s_stub);
static PHP_FUNCTION(p2s_stub_mm_install);
static PHP_FUNCTION(p2s_stub_retain);
#if PHP_VERSION_ID >= 70100
static PHP_FUNCTION(p2s_stub_interrupt_callback);
static PHP_FUNCTION(p2s_stub_vm_interrupt);
static void _interrupt(zend_execute_data *execute_data);
#endif
static ZEND_NAMED_FUNCTION(_gc_mem_caches);
static void _gc_install(void);
static void _gc_restore(void);
static int _startup(zend_extension *extension);
static void _activate(void);
static void _deactivate(void);
static void _error_cb(P2S_ERROR_CB_PARAMS);
static void _mm_install(void);
static bool _mm_on_top(void);
static void _mm_restore(void);
static void _mm_reset(void);
static void *_heap_malloc(size_t size P2S_MM_DC);
static void _heap_free(void *ptr P2S_MM_DC);
static void *_heap_realloc(void *ptr, size_t size P2S_MM_DC);
#if PHP_VERSION_ID >= 80400
static size_t _heap_gc(void);
static void _heap_shutdown(bool full, bool silent);
#endif
static void _install(void);
static void _restore(void);
static SAPI_INPUT_FILTER_FUNC(_input_filter);
static unsigned int _input_filter_init(void);
static SAPI_TREAT_DATA_FUNC(_treat_data);
static size_t _read_post(char *buffer, size_t count_bytes);
static p2s_rfc1867_ret _rfc1867_callback(
    unsigned int event, void *event_data, void **extra);

static bool _minit_mode;
static bool _startup_mode;
static bool _post_deactivate_error;
static bool _error_cb_swallow;
static void (*_prev_error_cb)(P2S_ERROR_CB_PARAMS);
static size_t _rinit_alloc;
static bool _rinit_bailout;
static bool _rshutdown_bailout;
static bool _activate_mm;
static bool _mm_keep;
static bool _mm_drop;
static unsigned long _requests;
static zend_long _gc_mode;
static __typeof__(((zend_internal_function *)0)->handler) _prev_gc_mem_caches;
#if PHP_VERSION_ID >= 70100
static void (*_prev_interrupt)(zend_execute_data *execute_data);
// The callback of p2s_stub_interrupt_callback(), until RSHUTDOWN.
static zval _interrupt_cb;
#endif
// The object p2s_stub_retain() keeps until post_deactivate (php-cgi, one
// thread).
static zval _retained;
static bool _installed;
static unsigned long _filter_calls;
static unsigned long _init_calls;
static unsigned long _treat_calls;
static unsigned long _read_calls;
static unsigned long _file_start_calls;
static __typeof__(sapi_module.treat_data) _prev_treat_data;
static __typeof__(sapi_module.input_filter) _prev_input_filter;
static __typeof__(sapi_module.input_filter_init) _prev_input_filter_init;
static __typeof__(sapi_module.read_post) _prev_read_post;
static p2s_rfc1867_fn _prev_rfc1867;

// The stub's Zend MM handlers (p2s_stub.activate_mm, p2s_stub_mm_install()):
// whether they were installed and not removed since, and what they forward to
// (the handlers found at install time, or zend_mm itself).
static struct {
    bool installed;
    bool had_prev;
    zend_mm_heap *heap;
    p2s_mm_malloc_fn prev_malloc;
    p2s_mm_free_fn prev_free;
    p2s_mm_realloc_fn prev_realloc;
#if PHP_VERSION_ID >= 80400
    p2s_mm_gc_fn prev_gc;
    p2s_mm_shutdown_fn prev_shutdown;
#endif
} _mm;

// clang-format off
PHP_INI_BEGIN()
    PHP_INI_ENTRY("p2s_stub.minit", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.startup", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.post_deactivate_error", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.error_cb_swallow", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.rinit_alloc", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.rinit_bailout", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.rshutdown_bailout", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.activate_mm", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.mm_keep", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.mm_drop", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.interrupt", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.gc_mem_caches", "0", PHP_INI_SYSTEM, NULL)
PHP_INI_END()

ZEND_BEGIN_ARG_INFO_EX(_mm_install_arginfo, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(_retain_arginfo, 0, 0, 1)
    ZEND_ARG_INFO(0, object)
ZEND_END_ARG_INFO()

#if PHP_VERSION_ID >= 70100
ZEND_BEGIN_ARG_INFO_EX(_interrupt_callback_arginfo, 0, 0, 1)
    ZEND_ARG_INFO(0, callback)
ZEND_END_ARG_INFO()
#endif

static const zend_function_entry _functions[] = {
    PHP_FE(p2s_stub_mm_install, _mm_install_arginfo)
    PHP_FE(p2s_stub_retain, _retain_arginfo)
#if PHP_VERSION_ID >= 70100
    PHP_FE(p2s_stub_interrupt_callback, _interrupt_callback_arginfo)
    PHP_FE(p2s_stub_vm_interrupt, _mm_install_arginfo)
#endif
    PHP_FE_END
};

zend_module_entry p2s_stub_module_entry = {
    STANDARD_MODULE_HEADER,
    P2S_STUB_NAME,
    _functions,
    PHP_MINIT(p2s_stub),
    PHP_MSHUTDOWN(p2s_stub),
    PHP_RINIT(p2s_stub),
    PHP_RSHUTDOWN(p2s_stub),
    NULL,
    P2S_STUB_VERSION,
    NO_MODULE_GLOBALS,
    ZEND_MODULE_POST_ZEND_DEACTIVATE_N(p2s_stub),
    STANDARD_MODULE_PROPERTIES_EX
};

static zend_extension _zend_extension_entry = {
    P2S_STUB_NAME,
    P2S_STUB_VERSION,
    "Datadog",
    "https://github.com/DataDog/dd-trace-php",
    "Copyright Datadog",
    _startup,
    NULL,
    _activate,
    _deactivate,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    STANDARD_ZEND_EXTENSION_PROPERTIES};
// clang-format on

ZEND_GET_MODULE(p2s_stub)

static PHP_MINIT_FUNCTION(p2s_stub)
{
    (void)type;
    REGISTER_INI_ENTRIES();
    _minit_mode = zend_ini_long(ZEND_STRL("p2s_stub.minit"), 0) != 0;
    _startup_mode = zend_ini_long(ZEND_STRL("p2s_stub.startup"), 0) != 0;
    _post_deactivate_error =
        zend_ini_long(ZEND_STRL("p2s_stub.post_deactivate_error"), 0) != 0;
    _error_cb_swallow =
        zend_ini_long(ZEND_STRL("p2s_stub.error_cb_swallow"), 0) != 0;
    zend_long rinit_alloc = zend_ini_long(ZEND_STRL("p2s_stub.rinit_alloc"), 0);
    _rinit_alloc = rinit_alloc > 0 ? (size_t)rinit_alloc : 0;
    _rinit_bailout = zend_ini_long(ZEND_STRL("p2s_stub.rinit_bailout"), 0) != 0;
    _rshutdown_bailout =
        zend_ini_long(ZEND_STRL("p2s_stub.rshutdown_bailout"), 0) != 0;
    _activate_mm = zend_ini_long(ZEND_STRL("p2s_stub.activate_mm"), 0) != 0;
    _mm_keep = zend_ini_long(ZEND_STRL("p2s_stub.mm_keep"), 0) != 0;
    _mm_drop = zend_ini_long(ZEND_STRL("p2s_stub.mm_drop"), 0) != 0;
    _gc_mode = zend_ini_long(ZEND_STRL("p2s_stub.gc_mem_caches"), 0);

    if (_minit_mode) {
        _install();
    }
    if (_gc_mode == 1) {
        _gc_install();
    }
#if PHP_VERSION_ID >= 70100
    if (zend_ini_long(ZEND_STRL("p2s_stub.interrupt"), 0) != 0) {
        _prev_interrupt = zend_interrupt_function;
        zend_interrupt_function = _interrupt;
    }
#endif
    if (_error_cb_swallow) {
        _prev_error_cb = zend_error_cb;
        zend_error_cb = _error_cb;
    }
    if (_startup_mode || _activate_mm || _mm_drop || _gc_mode == 2) {
        // As ddtrace does: hand the DSO handle to the zend_extension, so the
        // library stays mapped until the zend_extension list is destroyed,
        // after module shutdown.
        zend_module_entry *mod = zend_hash_str_find_ptr(
            &module_registry, P2S_STUB_NAME, sizeof(P2S_STUB_NAME) - 1);
        zend_register_extension(
            &_zend_extension_entry, mod ? mod->handle : NULL);
        if (mod) {
            mod->handle = NULL;
        }
    }
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(p2s_stub)
{
    (void)type;
    if (_minit_mode || _startup_mode) {
        fprintf(stderr,
            "P2S_STUB filter=%lu init=%lu treat=%lu read=%lu file_start=%lu\n",
            _filter_calls, _init_calls, _treat_calls, _read_calls,
            _file_start_calls);
        fflush(stderr);
    }
    _restore();
    _gc_restore();
    if (_prev_error_cb && zend_error_cb == _error_cb) {
        zend_error_cb = _prev_error_cb;
    }
#if PHP_VERSION_ID >= 70100
    if (zend_interrupt_function == _interrupt) {
        zend_interrupt_function = _prev_interrupt;
    }
#endif
    if (_mm.installed && _mm_on_top()) {
        _mm_reset();
    }
    UNREGISTER_INI_ENTRIES();
    return SUCCESS;
}

static PHP_RINIT_FUNCTION(p2s_stub)
{
    (void)type;
    (void)module_number;
    if (_rinit_bailout && ++_requests == 1) {
        fprintf(stderr, "P2S_STUB bailout in RINIT\n");
        fflush(stderr);
        zend_bailout();
    }
    if (_rinit_alloc > 0) {
        // volatile: set after the longjmp, read after zend_end_try().
        volatile bool bailed = false;
        zend_try { efree(emalloc(_rinit_alloc)); }
        zend_catch { bailed = true; }
        zend_end_try();
        fprintf(stderr, "P2S_STUB RINIT alloc bailed=%d\n", bailed ? 1 : 0);
        fflush(stderr);
    }
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(p2s_stub)
{
    (void)type;
    (void)module_number;
    if (_mm.installed && !_activate_mm && !_mm_keep && _mm_on_top()) {
        _mm_restore();
    }
#if PHP_VERSION_ID >= 70100
    zval_ptr_dtor(&_interrupt_cb);
    ZVAL_UNDEF(&_interrupt_cb);
#endif
    if (_rshutdown_bailout) {
        fprintf(stderr, "P2S_STUB bailout in RSHUTDOWN\n");
        fflush(stderr);
        zend_bailout();
    }
    return SUCCESS;
}

static ZEND_MODULE_POST_ZEND_DEACTIVATE_D(p2s_stub)
{
    if (_post_deactivate_error) {
        zend_error(E_WARNING, "P2S_STUB late error");
    }
    if (Z_TYPE(_retained) != IS_UNDEF) {
        // A fast shutdown (release builds) takes no reference on an object
        // with the default free_obj, so this release reaches
        // zend_objects_store_del(), which writes to the object store. From
        // PHP 7.4, a slow one (debug builds, always) keeps a reference on
        // every object, so the release does not reach it: only this line
        // shows whether the store had been reset already.
        fprintf(stderr, "P2S_STUB release store_reset=%d\n",
            EG(objects_store).top == 1 ? 1 : 0);
        fflush(stderr);
#if PHP_VERSION_ID < 70400
        // Up to 7.3, a slow shutdown destroys the store and keeps no
        // reference either: the release would crash without AppSec too.
        // Leak the object instead.
        if (EG(objects_store).object_buckets == NULL &&
            (ZEND_DEBUG || EG(full_tables_cleanup) || !is_zend_mm())) {
            ZVAL_UNDEF(&_retained);
        }
#endif
        zval_ptr_dtor(&_retained);
        ZVAL_UNDEF(&_retained);
    }
    return SUCCESS;
}

static PHP_FUNCTION(p2s_stub_retain)
{
    zval *object;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "o", &object) == FAILURE) {
        RETURN_FALSE;
    }
    zval_ptr_dtor(&_retained);
    ZVAL_COPY(&_retained, object);
    RETURN_TRUE;
}

static PHP_FUNCTION(p2s_stub_mm_install)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    if (_mm.installed) {
        RETURN_FALSE;
    }
    _mm_install();
    RETURN_TRUE;
}

#if PHP_VERSION_ID >= 70100
static PHP_FUNCTION(p2s_stub_interrupt_callback)
{
    zval *cb;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &cb) == FAILURE) {
        RETURN_FALSE;
    }
    zval_ptr_dtor(&_interrupt_cb);
    ZVAL_COPY(&_interrupt_cb, cb);
    RETURN_TRUE;
}

static PHP_FUNCTION(p2s_stub_vm_interrupt)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
#    if PHP_VERSION_ID >= 80200
    zend_atomic_bool_store_ex(&EG(vm_interrupt), true);
#    else
    EG(vm_interrupt) = 1;
#    endif
    RETURN_TRUE;
}

static void _interrupt(zend_execute_data *execute_data)
{
    if (_prev_interrupt) {
        _prev_interrupt(execute_data);
    }
    if (Z_TYPE(_interrupt_cb) == IS_UNDEF) {
        return;
    }
    zval retval;
    ZVAL_UNDEF(&retval);
    call_user_function(NULL, NULL, &_interrupt_cb, &retval, 0, NULL);
    zval_ptr_dtor(&retval);
}
#endif

static int _startup(zend_extension *extension)
{
    (void)extension;
    if (_startup_mode) {
        _install();
    }
    if (_gc_mode == 2) {
        _gc_install();
    }
    return SUCCESS;
}

static void _gc_install(void)
{
    zend_function *fn = zend_hash_str_find_ptr(
        CG(function_table), ZEND_STRL("gc_mem_caches"));
    if (fn != NULL && fn->type == ZEND_INTERNAL_FUNCTION) {
        _prev_gc_mem_caches = fn->internal_function.handler;
        fn->internal_function.handler = _gc_mem_caches;
    }
}

static void _gc_restore(void)
{
    zend_function *fn = zend_hash_str_find_ptr(
        CG(function_table), ZEND_STRL("gc_mem_caches"));
    if (fn != NULL && fn->type == ZEND_INTERNAL_FUNCTION &&
        fn->internal_function.handler == _gc_mem_caches) {
        fn->internal_function.handler = _prev_gc_mem_caches;
    }
}

static ZEND_NAMED_FUNCTION(_gc_mem_caches)
{
    _prev_gc_mem_caches(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static void _error_cb(P2S_ERROR_CB_PARAMS)
{
    if (type != E_ERROR) {
        _prev_error_cb(P2S_ERROR_CB_PASSTHRU);
        return;
    }
#if PHP_VERSION_ID < 80000
    char message[256];
    va_list copy;
    va_copy(copy, args);
    vsnprintf(message, sizeof(message), format, copy);
    va_end(copy);
    fprintf(stderr, "P2S_STUB swallowed E_ERROR: %s\n", message);
#else
    fprintf(stderr, "P2S_STUB swallowed E_ERROR: %s\n", ZSTR_VAL(message));
#endif
    fflush(stderr);
}

static void _activate(void)
{
    // As the profiler's RINIT: chain over whatever is there, without checking
    // whether it is this stub's own handlers.
    if (_activate_mm) {
        _mm_install();
    }
    if (_mm_drop && _mm.installed && _mm_on_top()) {
        _mm_reset();
    }
}

static void _deactivate(void)
{
    // As the profiler's fallback: give up if another set is on top.
    if (_activate_mm && _mm.installed && _mm_on_top()) {
        _mm_restore();
    }
}

static void _mm_install(void)
{
    zend_mm_heap *heap = zend_mm_get_heap();
    _mm.heap = heap;
    _mm.had_prev = !is_zend_mm();
    if (_mm.had_prev) {
#if PHP_VERSION_ID >= 80400
        zend_mm_get_custom_handlers_ex(heap, &_mm.prev_malloc, &_mm.prev_free,
            &_mm.prev_realloc, &_mm.prev_gc, &_mm.prev_shutdown);
#else
        zend_mm_get_custom_handlers(
            heap, &_mm.prev_malloc, &_mm.prev_free, &_mm.prev_realloc);
#endif
    }
#if PHP_VERSION_ID >= 80400
    zend_mm_set_custom_handlers_ex(heap, _heap_malloc, _heap_free,
        _heap_realloc, _heap_gc, _heap_shutdown);
#else
    zend_mm_set_custom_handlers(heap, _heap_malloc, _heap_free, _heap_realloc);
#endif
    _mm.installed = true;
}

static bool _mm_on_top(void)
{
    if (is_zend_mm()) {
        return false;
    }
    p2s_mm_malloc_fn cur_malloc = NULL;
    p2s_mm_free_fn cur_free = NULL;
    p2s_mm_realloc_fn cur_realloc = NULL;
#if PHP_VERSION_ID >= 80400
    p2s_mm_gc_fn cur_gc = NULL;
    p2s_mm_shutdown_fn cur_shutdown = NULL;
    zend_mm_get_custom_handlers_ex(zend_mm_get_heap(), &cur_malloc, &cur_free,
        &cur_realloc, &cur_gc, &cur_shutdown);
#else
    zend_mm_get_custom_handlers(
        zend_mm_get_heap(), &cur_malloc, &cur_free, &cur_realloc);
#endif
    return cur_malloc == _heap_malloc && cur_free == _heap_free &&
           cur_realloc == _heap_realloc;
}

// Put back what _mm_install() found.
static void _mm_restore(void)
{
    if (!_mm.had_prev) {
        _mm_reset();
        return;
    }
#if PHP_VERSION_ID >= 80400
    zend_mm_set_custom_handlers_ex(_mm.heap, _mm.prev_malloc, _mm.prev_free,
        _mm.prev_realloc, _mm.prev_gc, _mm.prev_shutdown);
#else
    zend_mm_set_custom_handlers(
        _mm.heap, _mm.prev_malloc, _mm.prev_free, _mm.prev_realloc);
#endif
    _mm.installed = false;
}

// Back to plain zend_mm.
static void _mm_reset(void)
{
#if PHP_VERSION_ID >= 80400
    zend_mm_set_custom_handlers_ex(_mm.heap, NULL, NULL, NULL, NULL, NULL);
#else
    zend_mm_set_custom_handlers(_mm.heap, NULL, NULL, NULL);
#    if PHP_VERSION_ID < 70300
    // PHP 7.0-7.2 leave use_custom_heap set when all handlers are NULL.
    *(int *)_mm.heap = ZEND_MM_CUSTOM_HEAP_NONE;
#    endif
#endif
    _mm.installed = false;
}

// Each handler forwards to the handlers found at install time or, with none,
// to zend_mm with the custom slot disabled for the call (use_custom_heap is
// the first field of zend_mm_heap, as AppSec and the profiler assume).
static void *_heap_malloc(size_t size P2S_MM_DC)
{
    if (_mm.had_prev) {
        return _mm.prev_malloc(size P2S_MM_CC);
    }
    int saved = *(int *)_mm.heap;
    *(int *)_mm.heap = ZEND_MM_CUSTOM_HEAP_NONE;
    void *ptr = zend_mm_alloc(_mm.heap, size);
    *(int *)_mm.heap = saved;
    return ptr;
}

static void _heap_free(void *ptr P2S_MM_DC)
{
    if (_mm.had_prev) {
        _mm.prev_free(ptr P2S_MM_CC);
        return;
    }
    int saved = *(int *)_mm.heap;
    *(int *)_mm.heap = ZEND_MM_CUSTOM_HEAP_NONE;
    zend_mm_free(_mm.heap, ptr);
    *(int *)_mm.heap = saved;
}

static void *_heap_realloc(void *ptr, size_t size P2S_MM_DC)
{
    if (_mm.had_prev) {
        return _mm.prev_realloc(ptr, size P2S_MM_CC);
    }
    int saved = *(int *)_mm.heap;
    *(int *)_mm.heap = ZEND_MM_CUSTOM_HEAP_NONE;
    void *res = zend_mm_realloc(_mm.heap, ptr, size);
    *(int *)_mm.heap = saved;
    return res;
}

#if PHP_VERSION_ID >= 80400
static size_t _heap_gc(void)
{
    if (_mm.had_prev && _mm.prev_gc) {
        return _mm.prev_gc();
    }
    int saved = *(int *)_mm.heap;
    *(int *)_mm.heap = ZEND_MM_CUSTOM_HEAP_NONE;
    size_t freed = zend_mm_gc(_mm.heap);
    *(int *)_mm.heap = saved;
    return freed;
}

static void _heap_shutdown(bool full, bool silent)
{
    if (_mm.had_prev && _mm.prev_shutdown) {
        _mm.prev_shutdown(full, silent);
        return;
    }
    int saved = *(int *)_mm.heap;
    *(int *)_mm.heap = ZEND_MM_CUSTOM_HEAP_NONE;
    zend_mm_shutdown(_mm.heap, full, silent);
    *(int *)_mm.heap = saved;
}
#endif

static void _install(void)
{
    if (_installed) {
        return;
    }
    _prev_treat_data = sapi_module.treat_data;
    _prev_input_filter = sapi_module.input_filter;
    _prev_input_filter_init = sapi_module.input_filter_init;
    sapi_register_input_filter(_input_filter, _input_filter_init);
    sapi_register_treat_data(_treat_data);
    _prev_read_post = sapi_module.read_post;
    if (_prev_read_post != NULL) {
        sapi_module.read_post = _read_post;
    }
    // The session extension, built in, installed its callback in its MINIT,
    // before this one.
    _prev_rfc1867 = php_rfc1867_callback;
    php_rfc1867_callback = _rfc1867_callback;
    _installed = true;
}

static void _restore(void)
{
    if (!_installed) {
        return;
    }
    if (sapi_module.treat_data == _treat_data) {
        sapi_module.treat_data = _prev_treat_data;
    }
    if (sapi_module.input_filter == _input_filter) {
        sapi_module.input_filter = _prev_input_filter;
    }
    if (sapi_module.input_filter_init == _input_filter_init) {
        sapi_module.input_filter_init = _prev_input_filter_init;
    }
    if (sapi_module.read_post == _read_post) {
        sapi_module.read_post = _prev_read_post;
    }
    if (php_rfc1867_callback == _rfc1867_callback) {
        php_rfc1867_callback = _prev_rfc1867;
    }
    _installed = false;
}

static SAPI_INPUT_FILTER_FUNC(_input_filter)
{
    if (arg == PARSE_POST || arg == PARSE_GET || arg == PARSE_COOKIE) {
        _filter_calls++;
        if (val_len > 0 && strcmp(var, P2S_STUB_TAG) == 0) {
            (*val)[0] = 'X';
        }
    }
    if (new_val_len) {
        *new_val_len = val_len;
    }
    return 1;
}

static unsigned int _input_filter_init(void)
{
    _init_calls++;
    return 0;
}

static SAPI_TREAT_DATA_FUNC(_treat_data)
{
    _treat_calls++;
    php_default_treat_data(arg, str, destArray);
}

static size_t _read_post(char *buffer, size_t count_bytes)
{
    _read_calls++;
    return _prev_read_post(buffer, count_bytes);
}

static p2s_rfc1867_ret _rfc1867_callback(
    unsigned int event, void *event_data, void **extra)
{
    if (event == MULTIPART_EVENT_FILE_START) {
        _file_start_calls++;
    }
    if (_prev_rfc1867 == NULL) {
        return SUCCESS;
    }
    return _prev_rfc1867(event, event_data, extra);
}
