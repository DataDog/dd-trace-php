// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Helpers shared by the PHP2Shell units that own a hook slot: slot reads and
// restores, the displacement check, the MINFO hook row and the lookup of an
// internal function's live handler.

#ifndef DD_P2S_HOOK_H
#define DD_P2S_HOOK_H

#include <Zend/zend_API.h>
#include <ext/standard/info.h>
#include <php.h>
#include <stdatomic.h>
#include <stdbool.h>

#include "../attributes.h"
#include "../logging.h"

// The effect logged for a displaced slot whose wrapper only forwards then.
#define DD_P2S_EFFECT_OFF "the mitigation is off for it"

// Whether `slot` holds `wrapper` right now. Relaxed load.
#define DD_P2S_SLOT_HOLDS(slot, wrapper)                                       \
    (__atomic_load_n(&(slot), __ATOMIC_RELAXED) == (wrapper))

// Put `value` back in `slot` if it still holds `wrapper`; a slot overwritten
// by another module is left alone.
#define DD_P2S_SLOT_RESTORE(slot, wrapper, value)                              \
    do {                                                                       \
        if (DD_P2S_SLOT_HOLDS(slot, wrapper)) {                                \
            __atomic_store_n(&(slot), (value), __ATOMIC_RELAXED);              \
        }                                                                      \
    } while (0)

// Per-request check, from post_deactivate, that a captured slot still holds
// our wrapper. A slot replaced after startup (by a module that writes it
// outside MINIT) is not repaired: the mitigation is off for that hook, as on
// an unpatched runtime without it, except for zend_interrupt_function and
// gc_mem_caches(): the request stays armed, and a replacement that does not
// chain to ours keeps runtime errors from being delivered before the hard
// limit, or frees nothing (see Coverage: Displaced hooks). The first time a
// slot is found displaced, one warning is logged for the process; MINFO shows
// it as "Displaced". Only reads the slot; `logged` is written once at most.
static inline void dd_p2s_hook_check_displaced(bool captured, bool ours,
    atomic_bool *nonnull logged, const char *nonnull name,
    const char *nonnull effect)
{
    if (EXPECTED(!captured || ours)) {
        return;
    }
    if (atomic_load_explicit(logged, memory_order_relaxed)) {
        return;
    }
    if (atomic_exchange_explicit(logged, true, memory_order_relaxed)) {
        return;
    }
    mlog(dd_log_warning,
        "PHP2Shell mitigation: %s was replaced after startup; %s", name,
        effect);
}

// The MINFO row of one hook.
static inline void dd_p2s_hook_minfo_row(
    const char *nonnull label, bool captured, bool ours)
{
    const char *value = "Not installed";
    if (captured) {
        value = ours ? "Installed" : "Displaced";
    }
    php_info_print_table_row(2, label, value);
}

// The internal function `name` now, or NULL if it is gone. Looked up by name
// on every call, never through a saved zend_function: from PHP 8.0,
// disable_functions deletes the entry after our capture (php_module_startup()
// runs it after zend_startup_extensions()), and the function table's
// destructor frees the function. On ZTS the per-thread tables were copied
// after our capture and share the function, so they hold our handler too.
static inline zend_internal_function *nullable dd_p2s_live_function(
    const char *nonnull name, size_t name_len)
{
    zend_function *fn =
        zend_hash_str_find_ptr(CG(function_table), name, name_len);
    if (fn == NULL || fn->type != ZEND_INTERNAL_FUNCTION) {
        return NULL;
    }
    return &fn->internal_function;
}

// The handler of the internal function `name` now, or NULL if the function is
// gone or disabled.
static inline zif_handler nullable dd_p2s_live_handler(
    const char *nonnull name, size_t name_len)
{
    zend_internal_function *fn = dd_p2s_live_function(name, name_len);
    if (fn == NULL) {
        return NULL;
    }
    zif_handler handler = __atomic_load_n(&fn->handler, __ATOMIC_RELAXED);
#if PHP_VERSION_ID < 80000
    // Before PHP 8.0, disable_functions keeps the entry and replaces the
    // handler.
    if (handler == ZEND_FN(display_disabled_function)) {
        return NULL;
    }
#endif
    return handler;
}

#endif // DD_P2S_HOOK_H
