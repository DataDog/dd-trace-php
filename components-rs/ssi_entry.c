// SSI entry point for libddtrace_php.so executed directly.
//
// The spawner invokes libddtrace_php.so via the dynamic loader explicitly:
//   execve(ld_path, [ld_path, lib_path, process_name, "", lib_path, deps..., symbol], envp)
// ld.so loads libc and all other dependencies, then jumps to _dd_ssi_entry.
// By that time the process is fully initialised (TLS, libc, allocator) so we
// can use ordinary C library calls.
//
// argv layout as seen by _dd_ssi_entry:
//   ld.so strips its own path before calling the entry point, so we see:
//   argv[0] = lib_path      <- skip  (ld.so already removed ld_path)
//   argv[1] = process_name  <- skip
//   argv[2] = ""            <- standard trampoline argv[1]
//   argv[3] = lib_path      <- standard trampoline argv[2]
//   ...
//   argv[argc-1] = symbol_name
//
// NOTE: we avoid dlopen()/dlsym() here.  They would actually work once the
// stack is properly aligned (see below), because ld.so sets up TLS and the
// pthread lock infrastructure before calling our entry point.  However,
// using them is unnecessary: this file is compiled into libddtrace_php.so
// alongside the Rust entry points, so the linker resolves the calls at link
// time via ordinary extern references — no runtime symbol lookup needed.
//
// NOTE: .init_array constructors are NOT called automatically when a shared
// library is exec'd as the main program via ld.so.  ld.so's _dl_init skips
// the main object, expecting __libc_start_main to handle it — but we never
// call __libc_start_main.  We therefore call run_own_init_array() before
// entering any Rust code (Rust runtime init, TLS, allocator setup, ...
// all live in .init_array).
//
// On every glibc version, _dl_init (in ld.so) only calls .init_array for
// shared libraries, never for the main executable.  The main executable's
// .init_array is always the responsibility of __libc_start_main, via its
// `init` function-pointer argument.  The mechanism differs by version:
//
//   glibc < 2.34:
//
//     ld.so _dl_init
//       └─ call_init() for each shared lib (NOT main exe)
//     app _start (glibc Scrt1.o)
//       └─ __libc_start_main(main, init=__libc_csu_init, ...)
//            ├─ init != NULL → call __libc_csu_init()
//            │   └─ iterates __init_array_start..__end  ← called here
//            └─ main()
//
//   glibc >= 2.34 (__libc_csu_init removed):
//
//     ld.so _dl_init
//       └─ call_init() for each shared lib (NOT main exe)
//     app _start (glibc Scrt1.o)
//       └─ __libc_start_main(main, init=NULL, ...)
//            ├─ init == NULL → inlined call_init reads DT_INIT_ARRAY
//            │   └─ iterates entries from link map  ← called here
//            └─ main()
//
// In our case: we ARE the main executable (exec'd via ld.so), but we never
// call __libc_start_main.  So .init_array is skipped.  We fix this below.
//
// Implementation notes for run_own_init_array():
//
//   _DYNAMIC: the linker always defines this symbol, pointing to the .dynamic
//   section of the current object.  It is PC-relative and always accessible.
//
//   DT_INIT_ARRAY d_ptr: a link-time virtual address (offset from load base).
//   ld.so adds l_addr when using it; it has NO separate RELATIVE relocation.
//   We add the load base ourselves.
//
//   Load base: __ehdr_start is a linker-defined symbol at VMA 0 within the
//   object.  For a standard DSO (first PT_LOAD p_vaddr = 0) this equals the
//   runtime load base.
//
//   .init_array entries: each entry has an R_*_RELATIVE relocation applied by
//   ld.so before our entry point runs, so they are already absolute VAs.
//   We call them directly without adding the load base again.

#include <elf.h>
#include <stddef.h>
#include <stdint.h>
#include <string.h>
#include <unistd.h>

struct trampoline_data {
    int    argc;
    char **argv;
    char **dependency_paths;
};

// Direct references to the known sidecar entry points in libddtrace_php.so.
// Declared weak so that if either symbol is absent the linker still accepts
// the build (the comparison below guards against calling a NULL pointer).
extern __attribute__((weak)) void ddog_daemon_entry_point(struct trampoline_data *);
extern __attribute__((weak)) void ddog_crashtracker_entry_point(struct trampoline_data *);

// Linker-defined symbol at VMA 0 of this DSO (= load base for a standard build).
extern __attribute__((visibility("hidden"))) char __ehdr_start;

// Linker-defined pointer to the .dynamic section of this object.
extern __attribute__((visibility("hidden"))) ElfW(Dyn) _DYNAMIC[];

// Call DT_INIT and DT_INIT_ARRAY for this library.
//
// ld.so skips the main object's .init_array (leaves it for __libc_start_main),
// so we must run it manually before calling any Rust code.
static void run_own_init_array(void)
{
    uintptr_t base = (uintptr_t)&__ehdr_start;

    int       has_init       = 0;
    uintptr_t init_off       = 0;
    uintptr_t init_array_off = 0;
    size_t    init_array_sz  = 0;

    for (ElfW(Dyn) *d = _DYNAMIC; d->d_tag != DT_NULL; d++) {
        switch (d->d_tag) {
        case DT_INIT:
            has_init = 1;
            init_off = d->d_un.d_ptr;
            break;
        case DT_INIT_ARRAY:
            init_array_off = d->d_un.d_ptr;
            break;
        case DT_INIT_ARRAYSZ:
            init_array_sz = d->d_un.d_val;
            break;
        }
    }

    // ELF spec: DT_INIT runs before DT_INIT_ARRAY.
    if (has_init)
        ((void (*)(void))(base + init_off))();

    if (init_array_off) {
        // DT_INIT_ARRAY d_ptr is a link-time offset; add load base.
        // The entries themselves are already absolute VAs (RELATIVE relocs applied).
        void (**arr)(void) = (void (**)(void))(base + init_array_off);
        size_t n = init_array_sz / sizeof(*arr);
        for (size_t i = 0; i < n; i++) {
            // Slots with value 0 or -1 are sentinels meaning "empty".
            if (arr[i] && (uintptr_t)arr[i] != (uintptr_t)-1)
                arr[i]();
        }
    }
}

__attribute__((noreturn, used))
static void ssi_main(int argc, char **argv)
{
    if (argc < 4) _exit(1);
    argc -= 2;
    argv += 2;

    const char *symbol = argv[argc - 1];

    void (*fn)(struct trampoline_data *) = NULL;
    if (ddog_daemon_entry_point &&
            strcmp(symbol, "ddog_daemon_entry_point") == 0) {
        fn = ddog_daemon_entry_point;
    } else if (ddog_crashtracker_entry_point &&
            strcmp(symbol, "ddog_crashtracker_entry_point") == 0) {
        fn = ddog_crashtracker_entry_point;
    }

    if (!fn) _exit(2);

    // Run our own .init_array before entering Rust code.  ld.so skips it
    // for the main executable, expecting __libc_start_main to handle it,
    // but we never call __libc_start_main.
    run_own_init_array();

    struct trampoline_data td = { argc, argv, NULL };
    fn(&td);
    _exit(0);
}

// Architecture-specific _start-like stub: read argc/argv from the kernel
// stack and tail-call ssi_main.
//
// Stack alignment:
//
//   x86-64: the kernel sets rsp % 16 == 0 at process entry.  The SysV ABI
//   requires rsp % 16 == 8 at the *start* of a C function (as if a 'call'
//   had just pushed an 8-byte return address).  Compiled code — including
//   glibc internals — can use 'movaps' and other SSE instructions that
//   require 16-byte aligned stack slots.  If we jump to C code without
//   fixing the alignment the first such instruction will SIGSEGV.
//
//   Fix: 'and $-16, %rsp' (no-op since rsp is already 16-aligned at entry)
//   followed by 'sub $8, %rsp' to simulate the return-address push.
//
//   argc and argv must be read *before* the stack pointer is moved.
//
//   aarch64: sp is required to be 16-byte aligned at all times by the ABI,
//   and the kernel guarantees this at entry.  There is no return-address-on-
//   stack convention (lr carries it), so no adjustment is needed.

#if defined(__aarch64__)
__asm__(
    ".text\n"
    ".global _dd_ssi_entry\n"
    ".type   _dd_ssi_entry, @function\n"
    "_dd_ssi_entry:\n"
    "    mov  x29, #0\n"
    "    mov  x30, #0\n"
    "    ldr  x0,  [sp]\n"       /* argc */
    "    add  x1,  sp, #8\n"     /* argv */
    "    b    ssi_main\n"        /* noreturn tail call */
    ".size _dd_ssi_entry, .-_dd_ssi_entry\n"
);
#elif defined(__x86_64__)
__asm__(
    ".text\n"
    ".global _dd_ssi_entry\n"
    ".type   _dd_ssi_entry, @function\n"
    "_dd_ssi_entry:\n"
    "    xor  %ebp, %ebp\n"
    "    movl (%rsp), %edi\n"    /* argc — read before adjusting rsp   */
    "    lea  8(%rsp), %rsi\n"   /* argv                                */
    "    and  $-16, %rsp\n"      /* ensure 16-byte alignment            */
    "    sub  $8, %rsp\n"        /* simulate 'call': rsp%16==8          */
    "    jmp  ssi_main\n"        /* noreturn tail call                  */
    ".size _dd_ssi_entry, .-_dd_ssi_entry\n"
);
#else
# error "ssi_entry.c: unsupported architecture"
#endif
