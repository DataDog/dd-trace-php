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
// always initialise before ddappsec. Loaded after ddappsec, this stub stands
// in for a module that writes the request-variable hooks later:
//   p2s_stub.minit=1    in MINIT, register an input_filter pair that does not
//                       chain (as ext/filter does), a treat_data handler
//                       that delegates to php_default_treat_data (as
//                       mbstring does), a read_post handler and a
//                       php_rfc1867_callback that forward to the previous
//                       ones. ddappsec captures the hooks after every MINIT,
//                       so it captures these;
//   p2s_stub.startup=1  do the same writes from a zend_extension startup hook
//                       registered in MINIT, which runs after ddappsec's own
//                       startup hook: the hooks are displaced after capture;
//   p2s_stub.post_deactivate_error=1
//                       raise an E_WARNING from post_deactivate, after
//                       php_request_shutdown() cleared PG(last_error_*).
//
// Switches for the lifecycle of ddappsec's request-level Zend MM handlers
// (php2shell_mitigation_lifecycle_fallbacks.phpt):
//   p2s_stub.rinit_bailout=1
//                       bail out in the RINIT of the first request only.
//                       Loaded after ddappsec, this runs after ddappsec's
//                       RINIT; on PHP 8.x no RSHUTDOWN then runs
//                       (PG(modules_activated) stays 0). Only once: the
//                       modules whose RINIT ran too keep request state that
//                       only their RSHUTDOWN clears;
//   p2s_stub.rshutdown_bailout=1
//                       bail out in every RSHUTDOWN. Loaded after ddappsec,
//                       this RSHUTDOWN runs before ddappsec's, which PHP 7.x
//                       then skips (one zend_try around the whole loop);
//   p2s_stub.activate_mm=1
//                       stand in for the allocation profiler's per-request
//                       handlers: install forwarding Zend MM handlers from a
//                       zend_extension activate hook (before every RINIT, so
//                       below ddappsec's), chaining over whatever the slot
//                       holds, even this stub's own handlers, as the
//                       profiler's RINIT does; remove them from the
//                       zend_extension deactivate hook (inside
//                       zend_deactivate(), before every post_deactivate) only
//                       if they are on top, else give up, as the profiler's
//                       fallback does;
//   p2s_stub_mm_install()
//                       a PHP function that installs forwarding Zend MM
//                       handlers over the current ones at runtime (false if
//                       this stub's are installed already). Unless
//                       p2s_stub.mm_keep=1, the stub's RSHUTDOWN restores
//                       what it saved, if its handlers are on top; loaded
//                       before ddappsec, that RSHUTDOWN runs after
//                       ddappsec's. With p2s_stub.mm_keep=1 they are never
//                       removed during a request and stay across requests;
//   p2s_stub.mm_drop=1  with p2s_stub.mm_keep=1: from the zend_extension
//                       activate hook (before every RINIT), if the stub's
//                       handlers kept from an earlier request are on top,
//                       reset the heap to plain zend_mm without putting back
//                       what they chained to, as an extension that removes
//                       itself and drops the handlers under it.
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
typedef void *(*p2s_mm_malloc_fn)(size_t P2S_MM_DC);
typedef void (*p2s_mm_free_fn)(void *P2S_MM_DC);
typedef void *(*p2s_mm_realloc_fn)(void *, size_t P2S_MM_DC);

static PHP_MINIT_FUNCTION(p2s_stub);
static PHP_MSHUTDOWN_FUNCTION(p2s_stub);
static PHP_RINIT_FUNCTION(p2s_stub);
static PHP_RSHUTDOWN_FUNCTION(p2s_stub);
static ZEND_MODULE_POST_ZEND_DEACTIVATE_D(p2s_stub);
static PHP_FUNCTION(p2s_stub_mm_install);
static int _startup(zend_extension *extension);
static void _activate(void);
static void _deactivate(void);
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
static bool _rinit_bailout;
static bool _rshutdown_bailout;
static bool _activate_mm;
static bool _mm_keep;
static bool _mm_drop;
static unsigned long _requests;
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
    PHP_INI_ENTRY("p2s_stub.rinit_bailout", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.rshutdown_bailout", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.activate_mm", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.mm_keep", "0", PHP_INI_SYSTEM, NULL)
    PHP_INI_ENTRY("p2s_stub.mm_drop", "0", PHP_INI_SYSTEM, NULL)
PHP_INI_END()

ZEND_BEGIN_ARG_INFO_EX(_mm_install_arginfo, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry _functions[] = {
    PHP_FE(p2s_stub_mm_install, _mm_install_arginfo)
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
    _rinit_bailout = zend_ini_long(ZEND_STRL("p2s_stub.rinit_bailout"), 0) != 0;
    _rshutdown_bailout =
        zend_ini_long(ZEND_STRL("p2s_stub.rshutdown_bailout"), 0) != 0;
    _activate_mm = zend_ini_long(ZEND_STRL("p2s_stub.activate_mm"), 0) != 0;
    _mm_keep = zend_ini_long(ZEND_STRL("p2s_stub.mm_keep"), 0) != 0;
    _mm_drop = zend_ini_long(ZEND_STRL("p2s_stub.mm_drop"), 0) != 0;

    if (_minit_mode) {
        _install();
    }
    if (_startup_mode || _activate_mm || _mm_drop) {
        // As ddappsec does: hand the DSO handle to the zend_extension, so the
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
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(p2s_stub)
{
    (void)type;
    (void)module_number;
    if (_mm.installed && !_activate_mm && !_mm_keep && _mm_on_top()) {
        _mm_restore();
    }
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
    return SUCCESS;
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

static int _startup(zend_extension *extension)
{
    (void)extension;
    if (_startup_mode) {
        _install();
    }
    return SUCCESS;
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
// the first field of zend_mm_heap, as ddappsec and the profiler assume).
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
