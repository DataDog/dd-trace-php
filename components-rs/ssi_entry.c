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
//   argv[0] = lib_path      ← skip  (ld.so already removed ld_path)
//   argv[1] = process_name  ← skip
//   argv[2] = ""            ← standard trampoline argv[1]
//   argv[3] = lib_path      ← standard trampoline argv[2]
//   ...
//   argv[argc-1] = symbol_name
//
// NOTE: dlopen()/dlsym() cannot be called here.  When a shared library is
// exec'd as the main program via ld.so, glibc's __libc_start_main is never
// invoked, so the internal dynamic-linker state that dlopen/dlsym rely on is
// not properly initialised.  Instead, we call the known entry points directly
// (this file is compiled as part of libddtrace_php.so so both symbols are in
// the same binary and will be resolved at link time).

#include <unistd.h>
#include <string.h>

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

    struct trampoline_data td = { argc, argv, NULL };
    fn(&td);
    _exit(0);
}

// Architecture-specific _start-like stub: read argc/argv from the kernel
// stack and tail-call ssi_main.

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
    "    movl (%rsp), %edi\n"    /* argc */
    "    lea  8(%rsp), %rsi\n"   /* argv */
    "    jmp  ssi_main\n"        /* noreturn tail call */
    ".size _dd_ssi_entry, .-_dd_ssi_entry\n"
);
#else
# error "ssi_entry.c: unsupported architecture"
#endif
