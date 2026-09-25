#include "../tsrmls_cache.h"
#include "jit_blacklist.h"
#include "opcache_symbols.h"
#include "../interceptor/line_hook.h"
#include "is_mapped.h"
#include "zend_extensions.h"
#include <Zend/zend_types.h>
#include <Zend/zend_ini.h>

#ifndef _WIN32
#include <dlfcn.h>
#include <sys/mman.h>
#include <unistd.h>
#endif

#if PHP_VERSION_ID >= 80100
#include <Zend/Optimizer/zend_call_graph.h>
#else
#define zend_func_info_rid zai_jit_func_info_rid
static int zai_jit_func_info_rid = -2;

typedef struct _zend_ssa_range {
    zend_long              min;
    zend_long              max;
    bool              underflow;
    bool              overflow;
} zend_ssa_range;

typedef struct _zend_ssa_var_info {
    uint32_t               type; /* inferred type (see zend_inference.h) */
    zend_ssa_range         range;
    zend_class_entry      *ce;
    unsigned int           has_range : 1;
    unsigned int           is_instanceof : 1; /* 0 - class == "ce", 1 - may be child of "ce" */
    unsigned int           recursive : 1;
    unsigned int           use_as_double : 1;
    unsigned int           delayed_fetch_this : 1;
    unsigned int           avoid_refcounting : 1;
    unsigned int           guarded_reference : 1;
    unsigned int           indirect_reference : 1; /* IS_INDIRECT returned by FETCH_DIM_W/FETCH_OBJ_W */
} zend_ssa_var_info;

typedef struct _zend_cfg {
    int               blocks_count;       /* number of basic blocks      */
    int               edges_count;        /* number of edges             */
    void *blocks;             /* array of basic blocks       */
    int              *predecessors;
    uint32_t         *map;
    uint32_t          flags;
} zend_cfg;

typedef struct _zend_ssa {
    zend_cfg               cfg;            /* control flow graph             */
    int                    vars_count;     /* number of SSA variables        */
    int                    sccs;           /* number of SCCs                 */
    void        *blocks;         /* array of SSA blocks            */
    void           *ops;            /* array of SSA instructions      */
    void          *vars;           /* use/def chain of SSA variables */
    zend_ssa_var_info     *var_info;
} zend_ssa;

typedef struct _zend_func_info {
    int                     num;
    uint32_t                flags;
    zend_ssa                ssa;          /* Static Single Assignment Form  */
    void         *caller_info;  /* where this function is called from */
    void         *callee_info;  /* which functions are called from this one */
    void        **call_map;     /* Call info associated with init/call/send opnum */
    zend_ssa_var_info       return_info;
} zend_func_info;
#endif

#if PHP_VERSION_ID < 80400
typedef struct _zend_jit_op_array_trace_extension {
    zend_func_info func_info;
    const zend_op_array *op_array;
    size_t offset; /* offset from "zend_op" to corresponding "op_info" */
} zend_jit_op_array_trace_extension;

typedef union _zend_op_trace_info {
    zend_op dummy; /* the size of this structure must be the same as zend_op */
    struct {
        const void *orig_handler;
        const void *call_handler;
        int16_t    *counter;
        uint8_t     trace_flags;
    };
} zend_op_trace_info;

/* Stable from 8.0 through master (ext/opcache/jit/zend_jit_internal.h). */
#define ZEND_JIT_TRACE_START_LOOP   (1<<0)
#define ZEND_JIT_TRACE_START_ENTER  (1<<1)
#define ZEND_JIT_TRACE_START_RETURN (1<<2)
#define ZEND_JIT_TRACE_JITED        (1<<4)
#define ZEND_JIT_TRACE_BLACKLISTED  (1<<5)

#define ZEND_OP_TRACE_INFO(opline, offset) \
	((zend_op_trace_info*)(((char*)opline) + offset))
#endif

#define ZEND_FUNC_INFO(op_array) \
	((zend_func_info*)((op_array)->reserved[zend_func_info_rid]))

/* JIT trigger flags from Zend/Optimizer/zend_func_info.h (PHP 8.0+). */
#if !defined(ZEND_FUNC_JIT_ON_FIRST_EXEC)
#define ZEND_FUNC_JIT_ON_FIRST_EXEC (1u << 13)
#endif
#if !defined(ZEND_FUNC_JIT_ON_PROF_REQUEST)
#define ZEND_FUNC_JIT_ON_PROF_REQUEST (1u << 14)
#endif
#if !defined(ZEND_FUNC_JIT_ON_HOT_COUNTERS)
#define ZEND_FUNC_JIT_ON_HOT_COUNTERS (1u << 15)
#endif
#if !defined(ZEND_FUNC_JIT_ON_HOT_TRACE)
#define ZEND_FUNC_JIT_ON_HOT_TRACE (1u << 16)
#endif

#if PHP_VERSION_ID >= 80400
static void (*zai_jit_blacklist_function)(zend_op_array *);
static bool zai_jit_fetch_symbols(void) {
    if (!zai_jit_blacklist_function) {
        zai_jit_blacklist_function = (void (*)(zend_op_array *))zai_jit_fetch_opcache_symbol("zend_jit_blacklist_function");
    }
    return zai_jit_blacklist_function != NULL;
}
#else
static void (*zai_jit_protect)(void), (*zai_jit_unprotect)(void);
static bool zai_jit_fetch_symbols(void) {
    if (!zai_jit_protect) {
        zai_jit_protect = (void (*)(void))zai_jit_fetch_opcache_symbol("zend_jit_protect");
        zai_jit_unprotect = (void (*)(void))zai_jit_fetch_opcache_symbol("zend_jit_unprotect");
    }
    return zai_jit_protect != NULL && zai_jit_unprotect != NULL;
}

#endif

// PHP_INI_SYSTEM, hence process-wide constant once startup is done.
static bool zai_jit_shm_protected(void) {
    static int protected = -1;
    if (protected < 0) {
        zend_string *name = zend_string_init(ZEND_STRL("opcache.protect_memory"), 0);
        zend_string *value = zend_ini_get_value(name);
        zend_string_release(name);
        protected = value && zend_ini_parse_bool(value);
    }
    return protected;
}

#if PHP_VERSION_ID < 80100
static inline bool check_pointer_near(void *a, void *b) {
    const size_t prefix_size = 0xFFFFFFFF; // 4 GB
    return (uintptr_t)a + prefix_size - (uintptr_t)b < prefix_size * 2;
}
#endif

int zai_get_zend_func_rid(zend_op_array *op_array) {
#if PHP_VERSION_ID < 80100
    if (zend_func_info_rid == -2) {
        if (!zai_jit_opcache_loaded()) {
            zai_jit_func_info_rid = -1;
        } else {
            // On PHP 8.0 we impossibly can get hold of zend_func_info_rid.
            // We determine it on our own heuristically, assuming:
            // a) The zend_func_info_rid is allocated in shared memory.
            // b) The op_array data is also allocated in shared memory, and thus relatively near.
            // c) The first matching pointer in op_array->reserved is the zend_func_info_rid.
            // d) "Normal" memory, like the VM stack is far away

            if (check_pointer_near(op_array->arg_info, EG(vm_stack))) {
                // This function does not seem JITted
                return -1;
            }

            for (int i = 0; i < ZEND_MAX_RESERVED_RESOURCES; ++i) {
                if (check_pointer_near(op_array->reserved[i], op_array->arg_info)) {
                    return (zend_func_info_rid = i);
                }
            }
        }
    }
#endif
    (void)op_array;
    return zend_func_info_rid;
}

/* Protected opcode, trace-info and function-info allocations need separate writable page spans. */
#define ZAI_JIT_SPANS 3

typedef struct {
    void *base;
    size_t len;
} zai_jit_span;

static void zai_jit_span_cover(zai_jit_span *span, const void *start, size_t len) {
#ifndef _WIN32
    size_t page_size = (size_t)sysconf(_SC_PAGESIZE);
#else
    size_t page_size = 4096;
#endif
    uintptr_t first = (uintptr_t)start & ~(uintptr_t)(page_size - 1);
    uintptr_t last = ((uintptr_t)start + len + page_size - 1) & ~(uintptr_t)(page_size - 1);
    span->base = (void *)first;
    span->len = (size_t)(last - first);
}

static bool zai_jit_span_protect(const zai_jit_span *span, bool writable) {
#ifndef _WIN32
    return mprotect(span->base, span->len, writable ? (PROT_READ | PROT_WRITE) : PROT_READ) == 0;
#else
    DWORD oldProtect;
    return VirtualProtect(span->base, span->len, writable ? PAGE_READWRITE : PAGE_READONLY, &oldProtect) != 0;
#endif
}

/* Opens `count` spans, unwinding the ones already opened if any refuses. */
static bool zai_jit_spans_open(const zai_jit_span *spans, unsigned count) {
    for (unsigned i = 0; i < count; ++i) {
        if (!zai_jit_span_protect(&spans[i], true)) {
            while (i--) {
                zai_jit_span_protect(&spans[i], false);
            }
            return false;
        }
    }
    return true;
}

static void zai_jit_spans_close(const zai_jit_span *spans, unsigned count) {
    while (count--) {
        zai_jit_span_protect(&spans[count], false);
    }
}

/* Update a local arm through its original-handler record and preserve sibling trampolines.
   Arming runs this blacklist first, so a sibling trampoline already has that work done. */
static void zai_jit_write_handler_through_arms(zend_op *opline, const void *handler) {
    if (zai_line_hook_write_original_handler(opline, handler)) {
        return;
    }

    /* CAS retries must recheck for a sibling's newly installed trampoline. */
    const void *trampoline = zai_line_hook_trampoline_address();
    for (;;) {
        const void *current = opline->handler;
        if (current == handler) {
            return;
        }
        if (trampoline && current == trampoline) {
            return; /* sibling arm; its blacklist pass already ran */
        }
        if (zai_line_hook_cas_handler(opline, current, handler)) {
            return;
        }
    }
}

/* Restore the ON_FIRST_EXEC trigger to its canonical VM handler.
   HOT_COUNTERS and PROF_REQUEST depend on version-specific private orig_handlers layouts and may have compiled entries, so report them as unsupported. */
static zai_jit_blacklist_result zai_jit_disable_function_trigger(zend_op_array *op_array, zend_func_info *func_info) {
    if (func_info->flags & (ZEND_FUNC_JIT_ON_HOT_COUNTERS | ZEND_FUNC_JIT_ON_PROF_REQUEST)) {
        return ZAI_JIT_BLACKLIST_UNSUPPORTED_TRIGGER;
    }
    if (!(func_info->flags & ZEND_FUNC_JIT_ON_FIRST_EXEC)) {
        return ZAI_JIT_BLACKLIST_APPLIED;
    }

    /* Match zend_jit.c: typed functions trigger at their first receive.
       Untyped functions skip RECV and RECV_INIT, but never RECV_VARIADIC. */
    zend_op *opline = op_array->opcodes;
    if (!(op_array->fn_flags & ZEND_ACC_HAS_TYPE_HINTS)) {
        while (opline < op_array->opcodes + op_array->last && (opline->opcode == ZEND_RECV || opline->opcode == ZEND_RECV_INIT)) {
            ++opline;
        }
    }
    if (opline >= op_array->opcodes + op_array->last) {
        return ZAI_JIT_BLACKLIST_APPLIED;
    }

    zend_op canonical = *opline;
    zend_vm_set_opcode_handler(&canonical);
    zai_jit_write_handler_through_arms(opline, canonical.handler);
    func_info->flags &= ~ZEND_FUNC_JIT_ON_FIRST_EXEC;
    return ZAI_JIT_BLACKLIST_APPLIED;
}

zai_jit_blacklist_result zai_jit_blacklist_function_inlining(zend_op_array *op_array) {
#if PHP_VERSION_ID >= 80400
    if (zai_jit_fetch_symbols()) {
        zai_jit_blacklist_function(op_array);
    }
    /* PHP 8.4+ exports the blacklist but no compiled-state query. Handle function-JIT triggers separately. */
    if (zai_get_zend_func_rid(op_array) >= 0) {
        zend_func_info *func_info = ZEND_FUNC_INFO(op_array);
        if (func_info && zai_is_mapped(func_info, sizeof(*func_info))) {
            bool is_protected = zai_jit_shm_protected();
            zai_jit_span spans[2];
            zai_jit_span_cover(&spans[0], op_array->opcodes, sizeof(zend_op) * op_array->last);
            zai_jit_span_cover(&spans[1], func_info, sizeof(*func_info));
            if (is_protected && !zai_jit_spans_open(spans, 2)) {
                return ZAI_JIT_BLACKLIST_APPLIED;
            }
            zai_jit_blacklist_result result = zai_jit_disable_function_trigger(op_array, func_info);
            if (is_protected) {
                zai_jit_spans_close(spans, 2);
            }
            return result;
        }
    }
    return ZAI_JIT_BLACKLIST_APPLIED;
#else
    if (zai_get_zend_func_rid(op_array) < 0) {
        return ZAI_JIT_BLACKLIST_APPLIED; /* function metadata unavailable */
    }
    // now in PHP < 8.1, zend_func_info_rid is set (on newer versions it's in zend_func_info.h)

    zend_jit_op_array_trace_extension *jit_extension = (zend_jit_op_array_trace_extension *)ZEND_FUNC_INFO(op_array);
    if (!jit_extension || !zai_is_mapped(jit_extension, sizeof(*jit_extension))) {
        return ZAI_JIT_BLACKLIST_APPLIED;
    }

    if (!(jit_extension->func_info.flags & ZEND_FUNC_JIT_ON_HOT_TRACE)) {
        /* Handle function-JIT triggers separately from tracing JIT. */
        bool is_protected = zai_jit_shm_protected();
        zai_jit_span spans[2];
        zai_jit_span_cover(&spans[0], op_array->opcodes, sizeof(zend_op) * op_array->last);
        zai_jit_span_cover(&spans[1], jit_extension, sizeof(*jit_extension));
        if (is_protected && !zai_jit_spans_open(spans, 2)) {
            return ZAI_JIT_BLACKLIST_APPLIED;
        }
        zai_jit_blacklist_result result = zai_jit_disable_function_trigger(op_array, &jit_extension->func_info);
        if (is_protected) {
            zai_jit_spans_close(spans, 2);
        }
        return result;
    }

    size_t offset = jit_extension->offset;
    size_t trace_info_size = sizeof(zend_op_trace_info) * op_array->last;
    zend_op_trace_info *trace_info = ZEND_OP_TRACE_INFO(op_array->opcodes, offset);

    /* Validate the entire trace-info array before reading it. */
    if (!zai_is_mapped(trace_info, trace_info_size)) {
        return ZAI_JIT_BLACKLIST_APPLIED;
    }

    if (!zai_jit_fetch_symbols()) {
        return ZAI_JIT_BLACKLIST_APPLIED;
    }

    zai_jit_span spans[ZAI_JIT_SPANS];
    zai_jit_span_cover(&spans[0], op_array->opcodes, sizeof(zend_op) * op_array->last);
    zai_jit_span_cover(&spans[1], trace_info, trace_info_size);
    zai_jit_span_cover(&spans[2], jit_extension, sizeof(*jit_extension));

    bool is_protected_memory = zai_jit_shm_protected();
    if (is_protected_memory) {
        if (!zai_jit_spans_open(spans, ZAI_JIT_SPANS)) {
            return ZAI_JIT_BLACKLIST_APPLIED;
        }
    }

    zai_jit_unprotect();

    /* Mirror zend_jit_stop_hot_trace_counters(): restore every uncompiled entry, loop and return trigger, not just the function entry. */
    bool already_compiled = false;
    for (uint32_t i = 0; i < op_array->last; ++i) {
        zend_op_trace_info *info = ZEND_OP_TRACE_INFO(&op_array->opcodes[i], offset);
        if (info->trace_flags & ZEND_JIT_TRACE_JITED) {
            /* Blacklisting prevents new traces; existing native code can still bypass opline->handler. */
            already_compiled = true;
            continue;
        }
        if (info->trace_flags & ZEND_JIT_TRACE_BLACKLISTED) {
            continue;
        }
        if (!(info->trace_flags & (ZEND_JIT_TRACE_START_LOOP | ZEND_JIT_TRACE_START_ENTER | ZEND_JIT_TRACE_START_RETURN))) {
            continue; /* not a trace start, so it carries no counter handler to undo */
        }

        info->trace_flags |= ZEND_JIT_TRACE_BLACKLISTED;
        zai_jit_write_handler_through_arms(&op_array->opcodes[i], info->orig_handler);
    }

    /* Prevent new trace starts for this function, as zend_jit_blacklist_function() does. */
    jit_extension->func_info.flags &= ~ZEND_FUNC_JIT_ON_HOT_TRACE;

    zai_jit_protect();

    if (is_protected_memory) {
        zai_jit_spans_close(spans, ZAI_JIT_SPANS);
    }

    return already_compiled ? ZAI_JIT_BLACKLIST_ALREADY_COMPILED : ZAI_JIT_BLACKLIST_APPLIED;
#endif
}
