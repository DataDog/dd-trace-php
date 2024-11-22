#include "../tsrmls_cache.h"
#include "jit_blacklist.h"
#include "zend_extensions.h"
#include <Zend/zend_types.h>
#include <Zend/zend_ini.h>

#ifndef _WIN32
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

#define ZEND_JIT_TRACE_BLACKLISTED  (1<<5)

#define ZEND_OP_TRACE_INFO(opline, offset) \
	((zend_op_trace_info*)(((char*)opline) + offset))
#endif

#define ZEND_FUNC_INFO(op_array) \
	((zend_func_info*)((op_array)->reserved[zend_func_info_rid]))

static void *opcache_handle;
static void zai_jit_find_opcache_handle(void *ext) {
    zend_extension *extension = (zend_extension *)ext;
    if (strcmp(extension->name, "Zend OPcache") == 0) {
        opcache_handle = extension->handle;
    }
}

// opcache startup NULLs its handle. MINIT is executed before extension startup.
void zai_jit_minit(void) {
    zend_llist_apply(&zend_extensions, zai_jit_find_opcache_handle);
}

#if PHP_VERSION_ID >= 80400
void (*zai_jit_blacklist_function)(zend_op_array *), (*zai_jit_unprotect)(void);
static void zai_jit_fetch_symbols(void) {
    if (!zai_jit_blacklist_function) {
        ZEND_ASSERT(opcache_handle); // assert the handle is there is zend_func_info_rid != -1

        zai_jit_blacklist_function = (void (*)(zend_op_array *)) DL_FETCH_SYMBOL(opcache_handle, "zend_jit_blacklist_function");
        if (zai_jit_blacklist_function == NULL) {
            zai_jit_blacklist_function = (void (*)(zend_op_array *)) DL_FETCH_SYMBOL(opcache_handle, "_zend_jit_blacklist_function");
        }
    }
}
#else
void (*zai_jit_protect)(void), (*zai_jit_unprotect)(void);
static void zai_jit_fetch_symbols(void) {
    if (!zai_jit_protect) {
        ZEND_ASSERT(opcache_handle); // assert the handle is there is zend_func_info_rid != -1

        zai_jit_protect = (void (*)(void))DL_FETCH_SYMBOL(opcache_handle, "zend_jit_protect");
        if (zai_jit_protect == NULL) {
            zai_jit_protect = (void (*)(void))DL_FETCH_SYMBOL(opcache_handle, "_zend_jit_protect");
        }

        zai_jit_unprotect = (void (*)(void))DL_FETCH_SYMBOL(opcache_handle, "zend_jit_unprotect");
        if (zai_jit_unprotect == NULL) {
            zai_jit_unprotect = (void (*)(void))DL_FETCH_SYMBOL(opcache_handle, "_zend_jit_unprotect");
        }
    }
}

static inline bool zai_is_func_recv_opcode(zend_uchar opcode) {
    return opcode == ZEND_RECV || opcode == ZEND_RECV_INIT || opcode == ZEND_RECV_VARIADIC;
}
#endif

#if PHP_VERSION_ID < 80100
static inline bool check_pointer_near(void *a, void *b) {
    const size_t prefix_size = 0xFFFFFFFF; // 4 GB
    return (uintptr_t)a + prefix_size - (uintptr_t)b < prefix_size * 2;
}
#endif

int zai_get_zend_func_rid(zend_op_array *op_array) {
#if PHP_VERSION_ID < 80100
    if (zend_func_info_rid == -2) {
        if (!opcache_handle) {
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
                if (check_pointer_near(op_array->reserved, op_array->arg_info)) {
                    return (zend_func_info_rid = i);
                }
            }
        }
    }
#endif
    (void)op_array;
    return zend_func_info_rid;
}

#if defined(__linux__) && (defined(__x86_64__) || defined(__aarch64__)) && PHP_VERSION_ID < 80400
static bool is_mapped(void *addr, size_t size) {
    uintptr_t page_size = sysconf(_SC_PAGESIZE);
    assert(size <= page_size);
    uintptr_t page_addr = ((uintptr_t)addr & ~(page_size - 1));
    uintptr_t last_page_addr = ((uintptr_t)(addr + size - 1) & ~(page_size - 1));

    unsigned char vec[2];
#ifdef __x86_64__
#define SYS_mincore 0x1B
#else // aarch64
#define SYS_mincore 0xE8
#endif

    int retries = 5;
again:
    if (syscall(SYS_mincore, page_addr, (1 + (page_addr != last_page_addr)) * page_size, &vec) == 0) {
        return true;
    } else if (errno == EFAULT || errno == ENOMEM) {
        return false;
    } else if (errno == EAGAIN) {
        if (retries-- > 0) {
            goto again;
        }
		return true;
    } else {
        // we don't know... assume true
#ifdef ZEND_DEBUG
        abort();
#else
        return true;
#endif
    }
}
#elif defined(__APPLE__) && PHP_VERSION_ID < 80400
#include <mach/mach.h>
static bool is_mapped(void *addr, size_t size) {
    mach_port_t task = mach_task_self();
    vm_address_t address = (vm_address_t)addr;

    while (address < (vm_address_t)addr + size) {
        __auto_type a = address;
        vm_size_t region_size;
        vm_region_basic_info_data_64_t info;
        kern_return_t kr = vm_region_64(task, &address, &region_size, VM_REGION_BASIC_INFO, (vm_region_info_t)&info,
                                        &(mach_msg_type_number_t){VM_REGION_BASIC_INFO_COUNT_64}, &(memory_object_name_t){0});

        if (kr != KERN_SUCCESS || !(info.protection & VM_PROT_READ)) {
            return false;
        }

        address += region_size;
    }

    return true;
}
#else
static inline bool is_mapped(...) {
    return true;
}
#endif

void zai_jit_blacklist_function_inlining(zend_op_array *op_array) {
#if PHP_VERSION_ID >= 80400
    if (opcache_handle) {
        zai_jit_fetch_symbols();
        zai_jit_blacklist_function(op_array);
    }
#else
    if (zai_get_zend_func_rid(op_array) < 0) {
        return;
    }
    // now in PHP < 8.1, zend_func_info_rid is set (on newer versions it's in zend_func_info.h)

    zend_jit_op_array_trace_extension *jit_extension = (zend_jit_op_array_trace_extension *)ZEND_FUNC_INFO(op_array);
    if (!jit_extension) {
        return;
    }

    // First not-skipped op
    zend_op *opline = op_array->opcodes;
    while (zai_is_func_recv_opcode(opline->opcode)) {
        ++opline;
    }

    size_t offset = jit_extension->offset;

    // check whether the op_trace_info is actually readable or EFAULTing
    // we can't trust opcache too much here...
    if (!is_mapped(ZEND_OP_TRACE_INFO(opline, offset), sizeof(zend_op_trace_info))) {
        return;
    }

    if (!(ZEND_OP_TRACE_INFO(opline, offset)->trace_flags & ZEND_JIT_TRACE_BLACKLISTED)) {
        bool is_protected_memory = false;
        zend_string *protect_memory = zend_string_init(ZEND_STRL("opcache.protect_memory"), 0);
        zend_string *protect_memory_ini = zend_ini_get_value(protect_memory);
        zend_string_release(protect_memory);
        if (protect_memory_ini) {
            is_protected_memory = zend_ini_parse_bool(protect_memory_ini);
        }

        zai_jit_fetch_symbols();

        uint8_t *trace_flags = &ZEND_OP_TRACE_INFO(opline, offset)->trace_flags;
        const void **handler = &((zend_op*)opline)->handler;

#ifndef _WIN32
        size_t page_size = sysconf(_SC_PAGESIZE);
#else
        size_t page_size = 4096;
#endif
        void *trace_flags_page = (void *) ((uintptr_t) trace_flags & ~page_size);
        void *handler_page = (void *) ((uintptr_t) handler & ~page_size);
        if (is_protected_memory) {
#ifndef _WIN32
            mprotect(trace_flags_page, page_size, PROT_READ | PROT_WRITE);
            mprotect(handler_page, page_size, PROT_READ | PROT_WRITE);
#else
            DWORD oldProtect;
            VirtualProtect(handler_page, page_size, PAGE_READWRITE, &oldProtect);
            VirtualProtect(trace_flags_page, page_size, PAGE_READWRITE, &oldProtect);
#endif
        }

        zai_jit_unprotect();

        *trace_flags |= ZEND_JIT_TRACE_BLACKLISTED;
        *handler = ZEND_OP_TRACE_INFO(opline, offset)->orig_handler;

        zai_jit_protect();

        if (is_protected_memory) {
#ifndef _WIN32
            mprotect(handler_page, page_size, PROT_READ);
            mprotect(trace_flags_page, page_size, PROT_READ);
#else
            DWORD oldProtect;
            VirtualProtect(handler_page, page_size, PAGE_READONLY, &oldProtect);
            VirtualProtect(trace_flags_page, page_size, PAGE_READONLY, &oldProtect);
#endif
        }
    }
#endif
}
