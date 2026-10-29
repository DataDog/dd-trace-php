#ifndef ZAI_LINE_HOOK_H
#define ZAI_LINE_HOOK_H

#include <Zend/zend_compile.h>
#include <Zend/zend_types.h>
#include <Zend/zend_vm.h>
#include <stdbool.h>

/* PHP < 7.2 exposes only the compile-time VM kind. */
#if PHP_VERSION_ID < 70200
# define zend_vm_kind() ZEND_VM_KIND
#endif

/* Bijective mixing spreads aligned opcode pointers across Zend numeric hash buckets on supported 64-bit builds.
   Store the raw pointer in the record; the mixed key is not an address. */
static zend_always_inline zend_ulong zai_line_hook_opline_key(const zend_op *opline) {
    zend_ulong key = (zend_ulong)(uintptr_t)opline;
    key ^= key >> 33;
    key *= 0xff51afd7ed558ccdULL;
    key ^= key >> 29;
    key *= 0xc4ceb9fe1a85ec53ULL;
    key ^= key >> 32;
    return key;
}

/* Runs before an armed instruction. Use the supplied opline; a VM with global registers may not have synchronized EX(opline).
   Exceptions raised during dispatch enter the VM's exception handler before the armed instruction executes. */
typedef void (*zai_line_hook_handler)(zend_execute_data *frame, const zend_op *opline);

/* Call from post_startup after OPcache starts to resolve the target VM handler ABI. */
void zai_line_hook_startup(zai_line_hook_handler handler);

/* Set the request dispatch callback independently of ABI resolution. */
void zai_line_hook_set_handler(zai_line_hook_handler handler);

/* Called after opcode storage and filename are freed. Use their addresses only as keys. */
typedef void (*zai_line_hook_op_array_dtor_handler)(zend_op_array *op_array);
void zai_line_hook_set_op_array_dtor(zai_line_hook_op_array_dtor_handler handler);
void zai_line_hook_op_array_dtor(zend_op_array *op_array);
void zai_line_hook_ginit(void);
/* Call in the child after fork to acquire its own shared references and rearm as needed.
   Failed setup retains inactive, unowned records for retry without releasing parent references. */
void zai_line_hook_handle_fork(void);
void zai_line_hook_rshutdown(void);
void zai_line_hook_gshutdown(void);
void zai_line_hook_mshutdown(void);

/* False for unsupported VM ABIs; callers must refuse line-hook installation. */
bool zai_line_hook_available(void);

/* Idempotent per opline and engine instance.
   Arming blacklists the op_array before changing its handler; existing JIT code can still bypass it. */
/* False for an unsupported ABI, a restore timeout, or a failed protection change.
   The site is not instrumented by this call; transient failures may be retried later. */
bool zai_line_hook_arm(zend_op_array *op_array, zend_op *opline);

/* Non-decreasing count of uncounted arms: a full/unavailable table leaves them permanent for the pool lifetime. */
extern uint32_t zai_line_hook_uncounted_arms;

/* Non-decreasing count of arms whose existing JIT code may bypass the handler. */
extern uint32_t zai_line_hook_jit_bypassed_arms;

/* Non-decreasing count of arms with unsupported hot-counter/profiling JIT triggers. */
extern uint32_t zai_line_hook_jit_trigger_arms;

/* Non-decreasing count of arms refused after a sibling restore exceeded the wait bound. */
extern uint32_t zai_line_hook_refused_arms;
void zai_line_hook_disarm(zend_op *opline);

/* Get/update the local arm's original handler, including cached disarmed private records.
   The setter returns false without a record; other writers must then preserve sibling trampolines using CAS. */
const void *zai_line_hook_original_handler(const zend_op *opline);

/* The VM trampoline, or NULL if unsupported.
   It can belong to a sibling whose record is absent locally; other handler writers must preserve it. */
const void *zai_line_hook_trampoline_address(void);

/* Atomic handler replacement. The caller must ensure writable memory and preserve any sibling trampoline. */
bool zai_line_hook_cas_handler(const zend_op *opline, const void *expected, const void *desired);
bool zai_line_hook_write_original_handler(const zend_op *opline, const void *handler);

#endif  // ZAI_LINE_HOOK_H
