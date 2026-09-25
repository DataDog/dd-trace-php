#include "../tsrmls_cache.h"
#include "line_hook.h"

#include <Zend/zend_exceptions.h>
#include <Zend/zend_ini.h>
#ifndef _WIN32
#include <dlfcn.h>
#include <pthread.h>
#include <sched.h>
#include <stdio.h>
#include <time.h>
#include <stdatomic.h>
#include <sys/mman.h>
#include <unistd.h>
#else
#include <components/pthread_polyfill.h>
#endif
#include <jit_utils/jit_blacklist.h>
#include <jit_utils/opcache_symbols.h>
#include <Zend/zend_vm.h>

static zai_line_hook_handler zai_line_handler;
static const void *zai_line_trampoline;

uint32_t zai_line_hook_uncounted_arms;

uint32_t zai_line_hook_jit_bypassed_arms;

uint32_t zai_line_hook_jit_trigger_arms;

uint32_t zai_line_hook_refused_arms;

/* The trampoline dispatches callbacks, then resumes the VM.
   Shared handler writes use exchange/CAS because OPcache restarts and uncounted arms can bypass the reference protocol.
   The process-local mutex guards page permissions, not sibling writers. */

/* Atomic access to the engine-owned handler field. */
#ifdef _MSC_VER
static inline const void *zai_line_handler_swap(zend_op *opline, const void *desired) {
    return InterlockedExchangePointer((void *volatile *)&opline->handler, (void *)desired);
}
static inline bool zai_line_handler_cas(zend_op *opline, const void *expected, const void *desired) {
    return InterlockedCompareExchangePointer((void *volatile *)&opline->handler, (void *)desired, (void *)expected) == (void *)expected;
}
#else
static inline const void *zai_line_handler_swap(zend_op *opline, const void *desired) {
    return __atomic_exchange_n((const void **)&opline->handler, desired, __ATOMIC_SEQ_CST);
}
static inline bool zai_line_handler_cas(zend_op *opline, const void *expected, const void *desired) {
    return __atomic_compare_exchange_n((const void **)&opline->handler, &expected, desired, false, __ATOMIC_SEQ_CST, __ATOMIC_SEQ_CST);
}
#endif

/* Local arm state; shared ownership is tracked separately below. */
typedef struct {
    const void *original; /* displaced handler, restored by the last owner */
    bool shared;          /* handler is visible to sibling engines */
    bool counted;         /* owns a shared reference, or the opcodes are private */
    bool permanent;       /* deliberately uncounted shared arm; never restore */
    bool live;            /* this engine instance currently holds the arm */
    const zend_op *opline; /* raw address, distinct from the mixed hash key */
} zai_line_armed;

ZEND_TLS HashTable zai_line_armeds;

static void zai_line_armed_free(zval *zv) {
    pefree(Z_PTR_P(zv), 1);
}

const void *zai_line_hook_dispatch(zend_execute_data *frame, const zend_op *opline);

/* global-regs variant */
#if defined(__x86_64__) || defined(__aarch64__)
# define ZAI_LINE_HOOK_HAVE_GLOBAL_REGISTERS 1

_Static_assert(offsetof(zend_execute_data, opline) == 0, "The line trampoline loads opline from the frame's first field");

# ifdef __APPLE__
#  define ZAI_SYM(s) "_" s
#  define ZAI_LOCAL(s) ".private_extern " ZAI_SYM(s) "\n"
# else
#  define ZAI_SYM(s) s
#  define ZAI_LOCAL(s) ".hidden " ZAI_SYM(s) "\n"
# endif

extern void zai_line_hook_trampoline_global_registers(void);
/* Entered with FP/IP in global registers.
   Caller-saved registers may be clobbered; preserve the live callee-saved registers described by HYBRID_JIT_GUARD and the C ABI.
   Finish with a tail jump: a HYBRID handler dispatches onward without returning, so call+ret would leak stack frames. */
# if defined(__x86_64__)
__asm__(".text\n"
        ".globl " ZAI_SYM("zai_line_hook_trampoline_global_registers") "\n" ZAI_LOCAL("zai_line_hook_trampoline_global_registers")
        ZAI_SYM("zai_line_hook_trampoline_global_registers") ":\n"
        "  endbr64\n" /* Required for CET indirect-branch tracking; a NOP on other CPUs. */
        "  pushq %rbp\n"
        "  movq  %rsp, %rbp\n"
        "  andq  $-16, %rsp\n" /* Jump entry provides no stack-alignment guarantee. */
        "  movq  %r14, %rdi\n"
        "  movq  %r15, %rsi\n"
        "  call  " ZAI_SYM("zai_line_hook_dispatch") "\n"
        "  movq  (%r14), %r15\n" /* reload opline */
        "  movq  %rbp, %rsp\n"
        "  popq  %rbp\n"
        "  jmp   *%rax\n");
# else
__asm__(".text\n"
        ".globl " ZAI_SYM("zai_line_hook_trampoline_global_registers") "\n" ZAI_LOCAL("zai_line_hook_trampoline_global_registers")
        ZAI_SYM("zai_line_hook_trampoline_global_registers") ":\n"
        "  hint #38\n" /* bti jc: accept CALL and HYBRID entries; a NOP without BTI. */
        "  stp x29, x30, [sp, #-16]!\n"
        "  mov x29, sp\n"
        "  mov x0, x27\n"
        "  mov x1, x28\n"
        "  bl  " ZAI_SYM("zai_line_hook_dispatch") "\n"
        "  mov x16, x0\n" /* BR through x16 accepts both bti c and bti j targets. */
        "  ldr x28, [x27]\n" /* reload opline */
        "  ldp x29, x30, [sp], #16\n"
        "  br  x16\n");
# endif
#endif

/* non-global-regs (CALL) variant */
#if PHP_VERSION_ID >= 80500
typedef const zend_op *(ZEND_FASTCALL *zai_line_call_handler_t)(zend_execute_data *, const zend_op *);

static const zend_op *ZEND_FASTCALL zai_line_hook_trampoline_call(zend_execute_data *execute_data, const zend_op *opline) {
    const void *original = zai_line_hook_dispatch(execute_data, opline);
    return ((zai_line_call_handler_t)original)(execute_data, execute_data->opline);
}

/* TAILCALL requires MUSTTAIL handlers. */
# if defined(ZEND_PRESERVE_NONE) && defined(ZEND_MUSTTAIL)
#  define ZAI_LINE_HOOK_HAVE_TAILCALL 1
typedef const zend_op *(ZEND_PRESERVE_NONE *zai_line_tailcall_handler_t)(zend_execute_data *, const zend_op *);

static ZEND_PRESERVE_NONE const zend_op *zai_line_hook_trampoline_tailcall(zend_execute_data *execute_data, const zend_op *opline) {
    const void *original = zai_line_hook_dispatch(execute_data, opline);
    ZEND_MUSTTAIL return ((zai_line_tailcall_handler_t)original)(execute_data, execute_data->opline);
}
# endif
#else
typedef int (ZEND_FASTCALL *zai_line_call_handler_t)(zend_execute_data *);

static int ZEND_FASTCALL zai_line_hook_trampoline_call(zend_execute_data *execute_data) {
    /* Without global registers EX(opline) is the instruction pointer. */
    const void *original = zai_line_hook_dispatch(execute_data, execute_data->opline);
    return ((zai_line_call_handler_t)original)(execute_data);
}
#endif

/* Under opcache.protect_memory, handler writes require temporarily writable pages. zend_accel_shared_protect() is not exported; use segment bounds when available (PHP 8.4+), otherwise the containing page.
   Huge-page mappings may require a larger aligned window. */

typedef struct {
    size_t size;
#if PHP_VERSION_ID >= 80000
    /* PHP 8 adds protect_size, the mprotect length; PHP 7 uses size (php-src 9a06876072b). */
    size_t end;
#endif
    size_t pos;
    void *p;
} zai_shared_segment;

#if PHP_VERSION_ID >= 80000
# define ZAI_SEGMENT_LEN(seg) ((seg)->end)
#else
# define ZAI_SEGMENT_LEN(seg) ((seg)->size)
#endif

typedef struct {
    zai_shared_segment **shared_segments;
    int shared_segments_count;
    /* trailing members irrelevant */
} zai_smm_shared_globals;

static int zai_line_shm_protected = -1;
static zai_smm_shared_globals **zai_line_smm_globals;

static zai_smm_shared_globals *zai_line_smm(void) {
    static bool resolved;
    if (!resolved) {
        resolved = true;
        /* ZEND_EXT_API on PHP 8.4+ */
        zai_line_smm_globals = (zai_smm_shared_globals **)zai_jit_fetch_opcache_symbol("smm_shared_globals");
    }
    return zai_line_smm_globals ? *zai_line_smm_globals : NULL;
}

/* PHP_INI_SYSTEM values are constant after startup.
   Use an explicit name length: PHP 7 INI_STR() requires a string literal.
   Parse the boolean locally because zend_ini_parse_bool() requires PHP 8. */
static bool zai_line_ini_bool(const char *name, int *cache) {
    if (*cache < 0) {
        const char *value = zend_ini_string_ex((char *)name, (uint32_t)strlen(name), 0, NULL);
        *cache = value && (!strcasecmp(value, "1") || !strcasecmp(value, "on") || !strcasecmp(value, "yes") || !strcasecmp(value, "true"));
    }
    return *cache > 0;
}

static bool zai_line_file_cache_only(void) {
    static int cached = -1;
    return zai_line_ini_bool("opcache.file_cache_only", &cached);
}

/* Use exported segment bounds when available.
   Otherwise, a NULL op_array refcount suggests SHM, except with file_cache_only.
   Private file-cache fallback loads can also have NULL refcounts; the protection path checks actual page permissions before changing them. */
static bool zai_line_opline_shared(const zend_op_array *op_array, const zend_op *opline) {
    zai_smm_shared_globals *smm = zai_line_smm();
    if (smm) {
        for (int i = 0; i < smm->shared_segments_count; ++i) {
            char *base = smm->shared_segments[i]->p;
            if ((char *)opline >= base && (char *)opline < base + ZAI_SEGMENT_LEN(smm->shared_segments[i])) {
                return true;
            }
        }
        return false;
    }
    return op_array->refcount == NULL && !zai_line_file_cache_only();
}

static bool zai_line_shm_needs_unprotect(void) { return zai_line_ini_bool("opcache.protect_memory", &zai_line_shm_protected); }

/* Returns 1 for writable, 0 for non-writable, or -1 for unknown.
   Used under opcache.protect_memory to avoid re-protecting a private file-cache arena misclassified as SHM. */
static int zai_line_addr_writable(const void *addr) {
#ifndef _WIN32
    FILE *maps = fopen("/proc/self/maps", "re");
    if (!maps) {
        return -1;
    }
    uintptr_t want = (uintptr_t)addr;
    char line[512];
    int result = -1;
    while (fgets(line, sizeof line, maps)) {
        unsigned long long start, end;
        char perms[8];
        if (sscanf(line, "%llx-%llx %7s", &start, &end, perms) != 3) {
            continue;
        }
        if (want >= (uintptr_t)start && want < (uintptr_t)end) {
            result = perms[1] == 'w';
            break;
        }
    }
    fclose(maps);
    return result;
#else
    MEMORY_BASIC_INFORMATION info;
    if (!VirtualQuery(addr, &info, sizeof info)) {
        return -1;
    }
    return (info.Protect & (PAGE_READWRITE | PAGE_WRITECOPY | PAGE_EXECUTE_READWRITE | PAGE_EXECUTE_WRITECOPY)) != 0;
#endif
}

static bool zai_line_shm_mprotect(void *base, size_t len, bool writable) {
#ifndef _WIN32
    return mprotect(base, len, writable ? (PROT_READ | PROT_WRITE) : PROT_READ) == 0;
#else
    DWORD old;
    return VirtualProtect(base, len, writable ? PAGE_READWRITE : PAGE_READONLY, &old) != 0;
#endif
}

/* Open every shared segment; any failure must prevent the handler write. */
static bool zai_line_shm_segments(bool writable) {
    zai_smm_shared_globals *smm = zai_line_smm();
    bool ok = true;
    for (int i = 0; smm && i < smm->shared_segments_count; ++i) {
        ok &= zai_line_shm_mprotect(smm->shared_segments[i]->p, ZAI_SEGMENT_LEN(smm->shared_segments[i]), writable);
    }
    return ok;
}

/* Tracks the exact protection window to close. A NULL base denotes the full segment list. */
typedef struct {
    bool opened;
    void *base;
    size_t len;
} zai_line_shm_window;

/* OPcache's MAP_HUGETLB segments are 2 MiB aligned and sized (shared_alloc_mmap.c), so an aligned window of this size stays inside the mapping. */
#define ZAI_LINE_HUGE_PAGE_SIZE (2 * 1024 * 1024)

static size_t zai_line_page_size(void) {
#ifndef _WIN32
    return (size_t)sysconf(_SC_PAGESIZE);
#else
    SYSTEM_INFO si;
    GetSystemInfo(&si);
    return si.dwPageSize;
#endif
}

/* Make the handler writable and record the window for closing; return false on failure. */
static bool zai_line_shm_open(const zend_op *opline, zai_line_shm_window *out) {
    *out = (zai_line_shm_window){false, NULL, 0};

    if (zai_line_smm()) {
        if (!zai_line_shm_segments(true)) {
            zai_line_shm_segments(false);
            return false;
        }
        out->opened = true;
        return true;
    }

    /* Without segment bounds, change permissions only for a confirmed non-writable page.
       Leave writable pages alone and refuse unknown permissions, including when procfs is unavailable. */
    switch (zai_line_addr_writable(opline)) {
        case 1:
            return true; /* nothing opened, so nothing for the close to undo */
        case 0:
            break;
        default:
            return false;
    }

    const size_t sizes[] = {zai_line_page_size(), ZAI_LINE_HUGE_PAGE_SIZE};
    for (unsigned i = 0; i < sizeof sizes / sizeof *sizes; ++i) {
        void *base = (void *)((uintptr_t)opline & ~(uintptr_t)(sizes[i] - 1));
        if (zai_line_shm_mprotect(base, sizes[i], true)) {
            *out = (zai_line_shm_window){true, base, sizes[i]};
            return true;
        }
    }
    return false;
}

static void zai_line_shm_close(const zai_line_shm_window *window) {
    if (!window->opened) {
        return;
    }
    if (window->base) {
        zai_line_shm_mprotect(window->base, window->len, false);
    } else {
        zai_line_shm_segments(false);
    }
}

#if ZTS
static MUTEX_T zai_line_write_mutex;
#endif

/* The ZTS mutex serializes the mprotect pair within this process; atomic handler writes still coordinate with sibling processes.
   NULL expected selects exchange, otherwise CAS.
   Return false if opening the protection window fails or CAS loses. */
static bool zai_line_store_handler(bool shared, zend_op *opline, const void *handler, const void *expected, const void **displaced) {
    bool protect = shared && zai_line_shm_needs_unprotect();
    zai_line_shm_window window = {false, NULL, 0};
    if (protect) {
#if ZTS
        tsrm_mutex_lock(zai_line_write_mutex);
#endif
        if (!zai_line_shm_open(opline, &window)) {
#if ZTS
            tsrm_mutex_unlock(zai_line_write_mutex);
#endif
            return false;
        }
    }

    bool stored;
    if (expected) {
        stored = zai_line_handler_cas(opline, expected, handler);
        if (displaced) {
            *displaced = stored ? expected : (const void *)opline->handler;
        }
    } else {
        const void *prev = zai_line_handler_swap(opline, handler);
        if (displaced) {
            *displaced = prev;
        }
        stored = true;
    }

    if (protect) {
        zai_line_shm_close(&window);
#if ZTS
        tsrm_mutex_unlock(zai_line_write_mutex);
#endif
    }
    return stored;
}

/* One shared reference per engine instance prevents restoration while a sibling still needs the arm.
   This anonymous MAP_SHARED table is created before POSIX workers fork.
   Windows attaches unrelated processes to named OPcache mappings, so its arms remain permanent.
   Handler pointers must match across workers: fork guarantees this on POSIX; Windows follows OPcache's DLL-address requirement (shared_alloc_win32.c). */

/* NO_SLOT permits a permanent, uncounted arm.
   BUSY must refuse: a pending restorer could otherwise overwrite a newly installed trampoline. */
typedef enum {
    ZAI_LINE_ACQUIRED,
    ZAI_LINE_ACQUIRE_NO_SLOT,
    ZAI_LINE_ACQUIRE_BUSY,
} zai_line_acquire_result;

typedef enum {
    ZAI_LINE_RELEASED,      /* reference dropped, others remain */
    ZAI_LINE_RELEASED_LAST, /* reference dropped and it was the last; slot parked in RESTORING */
    ZAI_LINE_RELEASE_BUSY,  /* nothing dropped: a sibling is restoring and outlasted the bound */
} zai_line_release_result;

#ifndef _WIN32
#define ZAI_LINE_ARMED_SLOTS 1024u /* power of two; open addressed */
#define ZAI_LINE_ARMED_RESTORING 0x80000000u
#define ZAI_LINE_ARMED_SPINS 1000u /* bounded restore wait; see zai_line_armed_wait() */

typedef struct {
    _Atomic(uintptr_t) opline; /* key; 0 = free */
    _Atomic(uint32_t) refcount;
} zai_line_armed_slot;

static zai_line_armed_slot *zai_line_armed_table;

/* Yield briefly, then sleep so immediate sched_yield() returns do not exhaust the wait.
   Bound waiting in case the restorer dies before releasing the slot. */
static bool zai_line_armed_wait(uint32_t spins) {
    if (spins > ZAI_LINE_ARMED_SPINS) {
        return false;
    }
    if (spins < 16) {
        sched_yield();
    } else {
        struct timespec ts = {0, 10000}; /* 10us */
        nanosleep(&ts, NULL);
    }
    return true;
}

static void zai_line_armed_table_create(void) {
    void *p = mmap(NULL, sizeof(zai_line_armed_slot) * ZAI_LINE_ARMED_SLOTS, PROT_READ | PROT_WRITE, MAP_SHARED | MAP_ANONYMOUS, -1, 0);
    if (p != MAP_FAILED) {
        memset(p, 0, sizeof(zai_line_armed_slot) * ZAI_LINE_ARMED_SLOTS);
        zai_line_armed_table = p;
    }
}

/* Keys are never reclaimed.
   If OPcache recycles an address, acquisition re-establishes the trampoline and restoration uses CAS to avoid overwriting a different handler. */
static zai_line_armed_slot *zai_line_armed_slot_for(const zend_op *opline, bool claim) {
    if (!zai_line_armed_table) {
        return NULL;
    }
    uintptr_t key = (uintptr_t)opline;
    uint32_t idx = (uint32_t)(((key >> 4) * 2654435761u) & (ZAI_LINE_ARMED_SLOTS - 1));

    for (uint32_t i = 0; i < ZAI_LINE_ARMED_SLOTS; ++i) {
        zai_line_armed_slot *slot = &zai_line_armed_table[(idx + i) & (ZAI_LINE_ARMED_SLOTS - 1)];
        uintptr_t cur = atomic_load(&slot->opline);
        if (cur == key) {
            return slot;
        }
        if (cur == 0) {
            if (!claim) {
                return NULL;
            }
            if (atomic_compare_exchange_strong(&slot->opline, &cur, key) || cur == key) {
                return slot;
            }
        }
    }
    return NULL;
}

static zai_line_acquire_result zai_line_armed_acquire(const zend_op *opline) {
    zai_line_armed_slot *slot = zai_line_armed_slot_for(opline, true);
    if (!slot) {
        return ZAI_LINE_ACQUIRE_NO_SLOT;
    }
    for (uint32_t spins = 0;; ++spins) {
        uint32_t rc = atomic_load(&slot->refcount);
        if (rc & ZAI_LINE_ARMED_RESTORING) {
            if (!zai_line_armed_wait(spins)) {
                return ZAI_LINE_ACQUIRE_BUSY;
            }
            continue;
        }
        if (atomic_compare_exchange_weak(&slot->refcount, &rc, rc + 1)) {
            return ZAI_LINE_ACQUIRED;
        }
    }
}

static zai_line_release_result zai_line_armed_release(const zend_op *opline) {
    zai_line_armed_slot *slot = zai_line_armed_slot_for(opline, false);
    if (!slot) {
        return ZAI_LINE_RELEASED;
    }
    for (uint32_t spins = 0;; ++spins) {
        uint32_t rc = atomic_load(&slot->refcount);
        if (rc & ZAI_LINE_ARMED_RESTORING) {
            if (!zai_line_armed_wait(spins)) {
                return ZAI_LINE_RELEASE_BUSY;
            }
            continue;
        }
        if (rc == 0) {
            return ZAI_LINE_RELEASED;
        }
        uint32_t next = rc == 1 ? ZAI_LINE_ARMED_RESTORING : rc - 1;
        if (atomic_compare_exchange_weak(&slot->refcount, &rc, next)) {
            return next == ZAI_LINE_ARMED_RESTORING ? ZAI_LINE_RELEASED_LAST : ZAI_LINE_RELEASED;
        }
    }
}

static void zai_line_armed_release_done(const zend_op *opline) {
    zai_line_armed_slot *slot = zai_line_armed_slot_for(opline, false);
    if (slot) {
        atomic_store(&slot->refcount, 0);
    }
}
#else
/* Windows shared mappings cannot use this anonymous reference table; keep their arms permanent. */
static void zai_line_armed_table_create(void) {}
static zai_line_acquire_result zai_line_armed_acquire(const zend_op *opline) {
    (void)opline;
    return ZAI_LINE_ACQUIRE_NO_SLOT;
}
static zai_line_release_result zai_line_armed_release(const zend_op *opline) {
    (void)opline;
    return ZAI_LINE_RELEASED;
}
static void zai_line_armed_release_done(const zend_op *opline) { (void)opline; }
#endif

static const void *zai_line_hook_recompute_handler(const zend_op *opline) {
    /* Recover the canonical handler when a sibling owns the displaced value. */
    zend_op copy = *opline;
    zend_vm_set_opcode_handler(&copy);
    return copy.handler;
}

/* Disarmed private opcodes retain their trampolines, so their cached original handlers remain usable. */
const void *zai_line_hook_trampoline_address(void) { return zai_line_trampoline; }

bool zai_line_hook_cas_handler(const zend_op *opline, const void *expected, const void *desired) {
    return zai_line_handler_cas((zend_op *)opline, expected, desired);
}

const void *zai_line_hook_original_handler(const zend_op *opline) {
    zai_line_armed *arm = zend_hash_index_find_ptr(&zai_line_armeds, zai_line_hook_opline_key(opline));
    return arm ? arm->original : NULL;
}

bool zai_line_hook_write_original_handler(const zend_op *opline, const void *handler) {
    zai_line_armed *arm = zend_hash_index_find_ptr(&zai_line_armeds, zai_line_hook_opline_key(opline));
    if (arm) {
        arm->original = handler;
    }
    return arm != NULL;
}

const void *zai_line_hook_dispatch(zend_execute_data *frame, const zend_op *opline) {
    const void *original = zai_line_hook_original_handler(opline);
    /* Never return the trampoline itself, which would recursively re-enter dispatch. */
    if (UNEXPECTED(!original || original == zai_line_trampoline)) {
        original = zai_line_hook_recompute_handler(opline);
    }

    /* Trampolines reload IP from the frame, including when no callback is installed. */
    frame->opline = opline;
    if (EXPECTED(zai_line_handler != NULL)) {
        zend_object *exception = EG(exception);
        zai_line_handler(frame, opline);
        if (UNEXPECTED(EG(exception) && EG(exception) != exception)) {
            /* The hooked instruction has not run. Unwind temporaries and partial calls from the preceding one. */
            const zend_op *throw_op = opline;
            if (throw_op > frame->func->op_array.opcodes) {
                --throw_op;
            }
            if ((throw_op == opline || throw_op->opcode == ZEND_EXT_NOP) && (throw_op->result_type & (IS_TMP_VAR | IS_VAR))) {
                /* No result exists before the first instruction, or after PHP 7's entry NOP, which only reserves a temporary. */
                if (throw_op->opcode == ZEND_ROPE_INIT) {
                    *(zend_string **)ZEND_CALL_VAR(frame, throw_op->result.var) = ZSTR_EMPTY_ALLOC();
                } else {
                    ZVAL_UNDEF(ZEND_CALL_VAR(frame, throw_op->result.var));
                }
            }
            EG(opline_before_exception) = throw_op;
            frame->opline = EG(exception_op);
            return frame->opline->handler;
        }
        /* Restoring a pending exception can redirect EX(opline) to EG(exception_op).
           Restore the real instruction before its original handler runs. */
        frame->opline = opline;
    }

    return original;
}

bool zai_line_hook_arm(zend_op_array *op_array, zend_op *opline) {
    if (!zai_line_trampoline) {
        return false;
    }

    zai_line_armed *held = zend_hash_index_find_ptr(&zai_line_armeds, zai_line_hook_opline_key(opline));
    if (held && held->live) {
        return true; /* one reference per engine instance, however many ranges arm this opline */
    }

#if ZAI_JIT_BLACKLIST_ACTIVE
    /* Blacklist before replacing a handler: later JIT compilation cannot decode our trampoline.
       Existing native code cannot be discarded; report known bypasses separately. */
    switch (zai_jit_blacklist_function_inlining(op_array)) {
        case ZAI_JIT_BLACKLIST_APPLIED:
            break;
        case ZAI_JIT_BLACKLIST_ALREADY_COMPILED:
            ++zai_line_hook_jit_bypassed_arms;
            break;
        case ZAI_JIT_BLACKLIST_UNSUPPORTED_TRIGGER:
            ++zai_line_hook_jit_trigger_arms;
            break;
    }
#endif

    zai_line_armed *arm = zend_hash_index_find_ptr(&zai_line_armeds, zai_line_hook_opline_key(opline));
    bool fresh = arm == NULL;
    if (fresh) {
        arm = pecalloc(1, sizeof(*arm), 1);
        arm->shared = zai_line_opline_shared(op_array, opline);
        arm->opline = opline;
        /* A private opline is ours alone, so it needs no reference. */
        arm->counted = !arm->shared;
        /* Capture original only from the atomic exchange below. */
        zend_hash_index_update_ptr(&zai_line_armeds, zai_line_hook_opline_key(opline), arm);
    }
    if (!arm->live) {
        /* A retained record may own no reference after failed fork setup or a failed store.
           Reacquire ownership before making it live. */
        bool acquired_here = false;
        if (arm->shared && !arm->counted && !arm->permanent) {
            switch (zai_line_armed_acquire(opline)) {
                case ZAI_LINE_ACQUIRED:
                    arm->counted = true;
                    acquired_here = true;
                    break;
                case ZAI_LINE_ACQUIRE_NO_SLOT:
                    /* Keep deliberate permanent arms distinct from failed acquisitions.
                       The tracer logs the exported count; ZAI also builds without libdatadog. */
                    arm->permanent = true;
                    ++zai_line_hook_uncounted_arms;
                    break;
                case ZAI_LINE_ACQUIRE_BUSY:
                    ++zai_line_hook_refused_arms;
                    if (fresh) {
                        zend_hash_index_del(&zai_line_armeds, zai_line_hook_opline_key(opline));
                    }
                    return false;
            }
        }

        /* Exchange establishes the trampoline and captures the displaced handler atomically.
           A rearm must use that current value, not a cached handler for a recycled address.
           If a sibling already installed the trampoline, derive the canonical handler instead. */
        const void *displaced;
        if (!zai_line_store_handler(arm->shared, opline, zai_line_trampoline, NULL, &displaced)) {
            /* Roll back only the reference acquired here; inactive records are deleted without release at RSHUTDOWN. */
            if (acquired_here) {
                if (zai_line_armed_release(opline) == ZAI_LINE_RELEASED_LAST) {
                    zai_line_armed_release_done(opline);
                }
                arm->counted = false;
            }
            return false;
        }
        arm->original = displaced != zai_line_trampoline ? displaced : zai_line_hook_recompute_handler(opline);
        arm->live = true;
    }

    return true;
}

void zai_line_hook_disarm(zend_op *opline) {
    zai_line_armed *arm = zend_hash_index_find_ptr(&zai_line_armeds, zai_line_hook_opline_key(opline));
    if (arm && arm->live) {
        if (!arm->shared) {
            /* Keep private trampolines and their original-handler cache until destruction.
               Opcode storage may be freed before RSHUTDOWN, and destructor notification arrives after that storage is gone. */
            arm->live = false;
        } else {
            /* Without a reference we cannot tell whether a sibling still needs the arm, so we leave it installed. */
            zai_line_release_result released = arm->counted ? zai_line_armed_release(opline) : ZAI_LINE_RELEASED;
            if (released == ZAI_LINE_RELEASE_BUSY) {
                /* Keep ownership so RSHUTDOWN can retry the release. */
                return;
            }
            if (released == ZAI_LINE_RELEASED_LAST) {
                /* Restore only our trampoline.
                   RESTORING excludes counted acquirers, while CAS also guards against writers outside that protocol. */
                zai_line_store_handler(arm->shared, opline, arm->original, zai_line_trampoline, NULL);
                zai_line_armed_release_done(opline);
            }
            zend_hash_index_del(&zai_line_armeds, zai_line_hook_opline_key(opline));
        }
    }
}

/* Before PHP 8.2, probe zend_vm_call_opcode_handler(): it preserves the marker return only without global registers; the global-register variant derives its result from VM state. */
#if PHP_VERSION_ID < 80200
# define ZAI_LINE_NO_GLOBAL_REGISTER_MARKER 0x5ED9
static int ZEND_FASTCALL zai_line_global_register_probe_handler(void) {
    return ZAI_LINE_NO_GLOBAL_REGISTER_MARKER;
}

static bool zend_gcc_global_regs(void) {
    zend_op op = {0};
    op.opcode = ZEND_NOP;
    op.handler = (const void *)zai_line_global_register_probe_handler;

    zend_execute_data ex = {0};
    ex.opline = &op;

    return zend_vm_call_opcode_handler(&ex) != ZAI_LINE_NO_GLOBAL_REGISTER_MARKER;
}
#endif

bool zai_line_hook_available(void) {
    return zai_line_trampoline != NULL;
}

void zai_line_hook_set_handler(zai_line_hook_handler handler) {
    zai_line_handler = handler;
}

/* Set by whoever owns the line-hook registry, so it can drop its own per-opline state from the same dtor. */
static zai_line_hook_op_array_dtor_handler zai_line_op_array_dtor_handler;

void zai_line_hook_set_op_array_dtor(zai_line_hook_op_array_dtor_handler handler) {
    zai_line_op_array_dtor_handler = handler;
}

/* destroy_op_array() frees opcodes and filename before notifying extensions, so use their addresses only as keys.
   Persisted SHM arrays skip this callback; disarming releases their shared references. */
void zai_line_hook_op_array_dtor(zend_op_array *op_array) {
    if (zend_hash_num_elements(&zai_line_armeds)) {
        uintptr_t low = (uintptr_t)op_array->opcodes;
        uintptr_t high = low + (uintptr_t)op_array->last * sizeof(zend_op);

        zend_ulong key;
        zai_line_armed *arm;
        ZEND_HASH_FOREACH_NUM_KEY_PTR(&zai_line_armeds, key, arm) {
            uintptr_t addr = (uintptr_t)arm->opline;
            if (addr >= low && addr < high) {
                zend_hash_index_del(&zai_line_armeds, key);
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    if (zai_line_op_array_dtor_handler) {
        zai_line_op_array_dtor_handler(op_array);
    }
}

void zai_line_hook_startup(zai_line_hook_handler handler) {
    zai_line_handler = handler;

    /* Resolve the target VM ABI at runtime; distributed extensions cannot assume its build flags. */
    switch (zend_vm_kind()) {
#ifdef ZEND_VM_KIND_HYBRID /* 7.2+; before that CALL with global registers is the only global-regs build */
        case ZEND_VM_KIND_HYBRID:
# ifdef ZAI_LINE_HOOK_HAVE_GLOBAL_REGISTERS
            zai_line_trampoline = (const void *)zai_line_hook_trampoline_global_registers;
            break;
# endif
            /* Hybrid always has global registers. */
            return;
#endif

        case ZEND_VM_KIND_CALL:
            /* CALL may use global registers; the runtime query or probe determines its ABI. */
            if (zend_gcc_global_regs()) {
#ifdef ZAI_LINE_HOOK_HAVE_GLOBAL_REGISTERS
                zai_line_trampoline = (const void *)zai_line_hook_trampoline_global_registers;
#else
                return;
#endif
            } else {
                zai_line_trampoline = (const void *)zai_line_hook_trampoline_call;
            }
            break;

#ifdef ZAI_LINE_HOOK_HAVE_TAILCALL
        case ZEND_VM_KIND_TAILCALL:
            /* TAILCALL uses no global registers. */
            zai_line_trampoline = (const void *)zai_line_hook_trampoline_tailcall;
            break;
#endif

        default:
            /* SWITCH and GOTO put an index or a label in opline->handler, neither of which we support. */
            return;
    }

#ifdef ZTS
    zai_line_write_mutex = tsrm_mutex_alloc();
#endif
    /* Before the pool forks (alongside opcache), so every worker inherits the same region. */
    zai_line_armed_table_create();
}

void zai_line_hook_ginit(void) {
    zend_hash_init(&zai_line_armeds, 8, NULL, zai_line_armed_free, 1);
}

/* Fork copies local arm records but takes no shared references.
   Acquire a claim for the child and re-establish the trampoline if the parent already restored it.
   Failed setup leaves an inactive, unowned record that a later arm can retry. */
void zai_line_hook_handle_fork(void) {
    zai_line_armed *arm;
    ZEND_HASH_FOREACH_PTR(&zai_line_armeds, arm) {
        if (!arm->shared) {
            continue; /* private opcodes need no shared reference */
        }
        if (!arm->live) {
            arm->counted = false;
            continue;
        }
        if (arm->permanent) {
            /* Permanent arms hold no reference and are never restored. */
            continue;
        }

        zend_op *opline = (zend_op *)arm->opline;
        if (zai_line_armed_acquire(opline) != ZAI_LINE_ACQUIRED) {
            /* Leave the record unowned and inactive for a later retry. */
            arm->counted = false;
            arm->live = false;
            continue;
        }
        arm->counted = true;

        if (opline->handler != zai_line_trampoline) {
            const void *displaced;
            if (zai_line_store_handler(arm->shared, opline, zai_line_trampoline, NULL, &displaced)) {
                arm->original = displaced != zai_line_trampoline ? displaced : zai_line_hook_recompute_handler(opline);
            } else {
                /* Only a last release may clear the shared count; other owners may remain. */
                if (zai_line_armed_release(opline) == ZAI_LINE_RELEASED_LAST) {
                    zai_line_armed_release_done(opline);
                }
                arm->counted = false;
                arm->live = false;
            }
        }
    }
    ZEND_HASH_FOREACH_END();
}

void zai_line_hook_rshutdown(void) {
    zend_ulong key;
    zai_line_armed *arm;
    ZEND_HASH_FOREACH_NUM_KEY_PTR(&zai_line_armeds, key, arm) {
        if (!arm->live) {
            zend_hash_index_del(&zai_line_armeds, key);
        } else {
            zai_line_hook_disarm((zend_op *)arm->opline);
        }
    } ZEND_HASH_FOREACH_END();
}

void zai_line_hook_gshutdown(void) {
    zend_hash_destroy(&zai_line_armeds);
}

void zai_line_hook_mshutdown(void) {
    zai_line_trampoline = NULL;
    zai_line_handler = NULL;

#ifndef _WIN32
    if (zai_line_armed_table) {
        /* Only this process's view; forked siblings keep theirs. */
        munmap(zai_line_armed_table, sizeof(zai_line_armed_slot) * ZAI_LINE_ARMED_SLOTS);
        zai_line_armed_table = NULL;
    }
#endif

#ifdef ZTS
    if (zai_line_write_mutex) {
        tsrm_mutex_free(zai_line_write_mutex);
        zai_line_write_mutex = NULL;
    }
#endif
}
