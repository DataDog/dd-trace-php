#include <SAPI.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_modules.h>
#include <php.h>
#include <stdbool.h>
#include <stddef.h>
#include <string.h>

#include <ext/standard/info.h>

/**
 * Represents a non-owning slice of chars. The order here matches GCC std::span,
 * which changed in v11 to be ptr-size instead of size-ptr to match iovec on
 * most platforms.
 *
 * Please do not use a null pointer; instead use an empty string with a `size`
 * of 0.
 *
 * todo: unify with ../components/string_view
 */
typedef struct datadog_php_str {
    const char *ptr;
    size_t size;
} datadog_php_str;

/* C11 allows a duplicate typedef provided they are the same, so this should be
 * fine as long as we compile with C11 or higher.
 */
typedef ZEND_RESULT_CODE zend_result;

/**
 * Returns macro expansion of ZEND_EXTENSION_BUILD_ID, which bindgen cannot
 * currently handle.
 */
const char *datadog_extension_build_id(void);

/**
 * Returns macro expansion of ZEND_MODULE_BUILD_ID, which bindgen cannot
 * currently handle.
 */
const char *datadog_module_build_id(void);

/* Expose globals so we can bridge them with Rust. */
// clang-format off
ZEND_BEGIN_MODULE_GLOBALS(datadog_php_profiling)
    bool profiling_enabled;
    bool profiling_experimental_cpu_time_enabled;
    uint32_t interrupt_count;

    // Maps to Rust's log::LevelFilter which is repr(usize).
    uintptr_t profiling_log_level;

    zend_bool *vm_interrupt_addr;

    // The strings will be interned but potentially only for the request, so be
    // careful to not use them outside a request (such as from other threads).
    datadog_php_str env;
    datadog_php_str service;
    datadog_php_str version;
ZEND_END_MODULE_GLOBALS(datadog_php_profiling)
// clang-format on

/**
 * A helper for Rust to fetch this extension's globals.
 */
zend_datadog_php_profiling_globals *datadog_php_profiling_globals_get(void);

/**
 * Interns the given string in the ZendEngine, returning a `datadog_php_str`
 * struct for ABI compatibility with Rust.
 */
datadog_php_str datadog_php_profiling_intern(const char *str, size_t size, bool permanent);

/**
 * Lookup module by name in the module registry. Returns NULL if not found.
 * This is meant to be called from Rust, so it uses types that are easy to use
 * in Rust. In Rust, strings are validated byte-slices instead of `char` slices
 * and array lengths use uintptr_t, not size_t.
 */
zend_module_entry *datadog_get_module_entry(const uint8_t *str, uintptr_t len);

/**
 * Called by this extension's rinit handler. Does things that are burdensome in
 * Rust like fetching EG(vm_interrupt).
 */
void datadog_php_profiling_rinit(void);

/**
 * For Code Hotspots, we need the tracer's local root span id and the current
 * span id. This is a cross-product struct, so keep it in sync with tracer's
 * version of this struct.
 * todo: re-use the tracer's header?
 */
typedef struct ddtrace_profiling_context_s {
    uint64_t local_root_span_id, span_id;
} ddtrace_profiling_context;

/**
 * A pointer to the tracer's ddtrace_get_profiling_context function if it was
 * found, otherwise points to a function which just returns {0, 0}.
 */
extern ddtrace_profiling_context (*datadog_php_profiling_get_profiling_context)(void);

/**
 * Called by this zend_extension's .startup handler. Does things that are
 * burdensome in Rust, like locating the ddtrace extension in the module
 * registry and finding the ddtrace_get_profiling_context function.
 */
void datadog_php_profiling_startup(zend_extension *extension);
