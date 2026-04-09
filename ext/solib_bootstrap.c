// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0
//
// solib_bootstrap.c - Makes ddtrace.so directly executable without PT_INTERP.
// Works on both glibc and musl. No libc available - raw syscalls only.
// Must be compiled with -fno-stack-protector (runs before libc init).
//
// Two components:
//
//   _dd_solib_start (asm, ELF entry point set via -Wl,-e):
//     Saves sp, computes __ehdr_start (base) and _DYNAMIC via PC-relative
//     ADRP/LEA, calls _dd_self_relocate(base, dynamic), then calls
//     _dd_solib_bootstrap(original_sp).
//
//   _dd_self_relocate (C, hidden):
//     Walks .dynamic, applies R_*_RELATIVE from DT_RELA, DT_JMPREL, DT_RELR.
//     On aarch64, skips relocs targeting .dynamic (linker emits RELATIVE relocs
//     for .dynamic d_ptr entries on aarch64; those are not needed since nothing
//     reads those fields as runtime addresses after self-relocation).
//
//   _dd_solib_bootstrap (C, noreturn):
//     a. Maps the embedded TRAMPOLINE_BIN ELF into anonymous memory.
//     b. Redirects AT_PHDR/AT_PHNUM/AT_ENTRY in the auxv to the trampoline.
//     c. Creates a patched memfd copy of ddtrace.so: sendfile the whole binary,
//        then pwrite-patch .dynsym (STB_GLOBAL/SHN_UNDEF -> STB_WEAK) and
//        .dynamic (neutralize DT_NEEDED, DT_INIT/FINI, DT_VERNEED, etc.).
//        This lets dlopen succeed despite PHP symbols being absent in the child.
//     d. Loads ld.so (glibc or musl) from the __DD_LDSO_PATH env var.
//     e. Sets AT_BASE to ld.so's load address.
//     f. Restores sp and jumps to ld.so's entry point.
//
// ld.so then sees the trampoline as the main executable, loads its deps (libc
// etc.), and calls trampoline main(), which does dlopen(memfd_path) + dlsym
// to invoke the requested entry point.

#ifdef __linux__

#pragma GCC optimize("no-stack-protector")
// This file runs before ld.so and before any runtime library (libc, ASAN,
// etc.) is initialized. Any instrumentation that inserts calls to runtime
// functions (ASAN __asan_load*, stack protector __stack_chk_fail, UBSan
// __ubsan_handle_*) will produce unresolved PLT entries that crash before the
// bootstrap completes.
#ifdef __clang__
#  pragma clang attribute push(__attribute__((no_sanitize("address","undefined","thread","memory"))), apply_to = function)
#endif

#include <elf.h>
#include <stddef.h>
#include <stdint.h>
#include <stdnoreturn.h>

// DD_TRAMPOLINE_BIN is defined in spawn_worker (Rust) as a &[u8] fat pointer,
// i.e. { const u8 *ptr, usize len }.
struct dd_slice { const unsigned char *ptr; uintptr_t len; };
// Mark the symbol as hidden. This file is actually linked as part of a shared
// library and we don't want to linker to think the symbol could be preempted,
// especially because this could result in GLOB_DAT relocs that we currently
// don't handle during our self-relocation.
extern const struct dd_slice DD_TRAMPOLINE_BIN __attribute__((visibility("hidden")));

// Actually, the "split" build configuration requires that, when building
// ddtrace.la (yes, .la is apparently stil a thing), we provide a definition for
// the symbol, otherwise we get a linker error: "undefined reference to
// `DD_TRAMPOLINE_BIN'" / "relocation R_X86_64_PC32 against undefined hidden
// symbol `DD_TRAMPOLINE_BIN' can not be used when making a shared object". So
// provide here a weak hidden definition in the hopes that the final linking
// step will ignore it in favor of the non-weak version in the rust library
// libddtrace_php.a.
const struct dd_slice DD_TRAMPOLINE_BIN __attribute__((visibility("hidden"), weak)) = {0};

// ---- Structs {{{

struct loaded_lib {
    uintptr_t base;
    uintptr_t entry;
    Elf64_Dyn *dynamic;
    Elf64_Sym *dynsym;
    const char *dynstr;
    uint32_t *gnu_hash;
    Elf64_Rela *rela;
    long rela_count;
    Elf64_Rela *jmprel;
    long jmprel_count;
};

struct trampoline_map {
    uintptr_t base;      // load bias: base + p_vaddr == runtime address of that vaddr
    uintptr_t entry;     // runtime entry point (base + e_entry)
    uintptr_t phdr;      // runtime address of program header table
    uint16_t  phnum;     // number of program headers
    long      total_map; // total bytes reserved
};

struct boot_args {
    int argc;
    char **argv;
    char **envp;
    Elf64_auxv_t *auxv;
};

// ddtrace.so's ELF header is at __ehdr_start (linker-defined, hidden).
extern const Elf64_Ehdr __ehdr_start __attribute__((visibility("hidden")));

// }}}
// ---- Forward declarations {{{

static void parse_stack(void *stack_top, struct boot_args *args);
static const char *find_env(char **envp, const char *name);
static Elf64_auxv_t *find_auxv_entry(Elf64_auxv_t *auxv, unsigned long type);
static unsigned long get_auxv(Elf64_auxv_t *auxv, unsigned long type);
static int elf_map_segments(int fd, long file_bias, const Elf64_Phdr *phdrs,
                            int phnum, uintptr_t base, long page_size);
static int elf_load_trampoline(const void *src, size_t src_len,
                               struct trampoline_map *out, long page_size);
static int elf_load(const char *path, struct loaded_lib *lib, long page_size);
static int create_patched_memfd(void);
static int uint_to_dec(unsigned int v, char *buf);
static void _dd_self_relocate(uintptr_t base, const Elf64_Dyn *dynamic);
static void _dd_apply_relr(uintptr_t base, const uint64_t *relr, long relrsz);
static int elf_check_header(const Elf64_Ehdr *ehdr, unsigned type_mask, int max_phnum);
static const Elf64_Phdr *find_phdr(const Elf64_Phdr *phdrs, int phnum, uint32_t type);
static int elf_reserve(const Elf64_Phdr *phdrs, int phnum, long page_size,
                       uintptr_t *base_out, long *total_out);
static int bs_strlen(const char *s);
static int bs_strncmp(const char *a, const char *b, int n);
static void bs_memcpy(void *dst, const void *src, long n);
static void bs_memset(void *dst, int c, long n);
static int elf_pf_to_prot(uint32_t pf);
static noreturn void bs_fatal(const char *msg, int code);
static long sys_readlink(const char *path, char *buf, long bufsiz);
static int sys_open_rdonly(const char *path);
static long sys_read(int fd, void *buf, long count);
static long sys_write(int fd, const void *buf, long count);
static int sys_close(int fd);
static void *sys_mmap(void *addr, long length, int prot, int flags, int fd, long offset);
static int sys_munmap(void *addr, long length);

static noreturn void sys_exit_group(int status);
static int sys_memfd_create(const char *name, unsigned int flags);
static long sys_sendfile(int out_fd, int in_fd, long count);
static long sys_pwrite(int fd, const void *buf, long count, long offset);
// }}}

// ---- Constants {{{

#define BS_PROT_NONE   0x0
#define BS_PROT_READ   0x1
#define BS_PROT_WRITE  0x2
#define BS_PROT_EXEC   0x4
#define BS_MAP_PRIVATE   0x02
#define BS_MAP_FIXED     0x10
#define BS_MAP_ANONYMOUS 0x20
#define BS_MAP_FAILED    ((void *)-1)

#define BS_PAGE_DOWN(x, ps) ((uintptr_t)(x) & ~((uintptr_t)(ps) - 1))
#define BS_PAGE_UP(x, ps)   (((uintptr_t)(x) + (uintptr_t)(ps) - 1) & ~((uintptr_t)(ps) - 1))

// OS-specific ELF dynamic tags (not in all <elf.h> versions)
#ifndef DT_GNU_HASH
#define DT_GNU_HASH    0x6ffffef5
#endif
#ifndef DT_FLAGS_1
#define DT_FLAGS_1     0x6ffffffb
#endif
#ifndef DT_VERSYM
#define DT_VERSYM      0x6ffffff0
#endif
#ifndef DT_VERNEED
#define DT_VERNEED     0x6ffffffe
#endif
#ifndef DT_VERNEEDNUM
#define DT_VERNEEDNUM  0x6fffffff
#endif
#ifndef DT_RELACOUNT
#define DT_RELACOUNT   0x6ffffff9
#endif
#ifndef DT_RELR
#define DT_RELR        36
#endif
#ifndef DT_RELRSZ
#define DT_RELRSZ      35
#endif

// Architecture-specific RELATIVE relocation type
#ifdef __x86_64__
#define BS_R_RELATIVE  R_X86_64_RELATIVE   // = 8
#elif defined(__aarch64__)
#define BS_R_RELATIVE  R_AARCH64_RELATIVE  // = 0x403 = 1027
#endif
// }}}

// ---- ELF entry point (file-scope asm) {{{
// _dd_solib_bootstrap is noreturn - it transfers control directly to ld.so.
// The asm saves the original stack pointer and calls bootstrap with it.

#ifdef __x86_64__
__asm__(
    ".text\n"
    ".global _dd_solib_start\n"
    ".type _dd_solib_start, @function\n"
    "_dd_solib_start:\n"
    "    xor %ebp, %ebp\n"
    "    mov %rsp, %r12\n"                  // r12 = original sp (callee-saved)
    "    lea __ehdr_start(%rip), %rdi\n"    // arg1: base
    "    lea _DYNAMIC(%rip), %rsi\n"        // arg2: &_DYNAMIC
    "    andq $-16, %rsp\n"
    "    call _dd_self_relocate\n"
    "    mov %r12, %rdi\n"                  // arg1: original sp for bootstrap
    "    mov %r12, %rsp\n"
    "    andq $-16, %rsp\n"
    "    call _dd_solib_bootstrap\n"        // noreturn
    ".size _dd_solib_start, .-_dd_solib_start\n"
);
#elif defined(__aarch64__)
__asm__(
    ".text\n"
    ".global _dd_solib_start\n"
    ".type _dd_solib_start, @function\n"
    "_dd_solib_start:\n"
    "    mov x29, #0\n"
    "    mov x30, #0\n"
    "    mov x19, sp\n"                          // x19 = original sp (callee-saved)
    "    adrp x0, __ehdr_start\n"
    "    add  x0, x0, :lo12:__ehdr_start\n"      // arg1: base
    "    adrp x1, _DYNAMIC\n"
    "    add  x1, x1, :lo12:_DYNAMIC\n"          // arg2: &_DYNAMIC
    "    mov  x9, x19\n"
    "    and  x9, x9, #-16\n"
    "    mov  sp, x9\n"
    "    bl   _dd_self_relocate\n"
    "    mov  x0, x19\n"                          // arg1: original sp for bootstrap
    "    mov  x9, x19\n"
    "    and  x9, x9, #-16\n"
    "    mov  sp, x9\n"
    "    bl   _dd_solib_bootstrap\n"              // noreturn
    ".size _dd_solib_start, .-_dd_solib_start\n"
);
#endif

// }}}
// ---- Bootstrap entry point {{{

__attribute__((visibility("hidden"), used))
noreturn void _dd_solib_bootstrap(void *stack_top) {
    struct boot_args args;
    struct loaded_lib bs_ldso;
    parse_stack(stack_top, &args);

    long page_size = (long)get_auxv(args.auxv, AT_PAGESZ);
    if (page_size <= 0) page_size = 4096;

    const char *ldso_path = find_env(args.envp, "__DD_LDSO_PATH");
    if (!ldso_path)
        bs_fatal("__DD_LDSO_PATH not set", 119);

    // Step 1: Map the embedded trampoline binary from memory
    const unsigned char *trampoline_bytes = DD_TRAMPOLINE_BIN.ptr;
    size_t trampoline_len = DD_TRAMPOLINE_BIN.len;

    if (!trampoline_bytes || !trampoline_len)
        bs_fatal("TRAMPOLINE_BIN not available", 120);

    struct trampoline_map tmap;
    if (elf_load_trampoline(trampoline_bytes, trampoline_len, &tmap, page_size) < 0)
        bs_fatal("failed to map trampoline", 121);

    // Step 2: Redirect auxv to the trampoline
    // ld.so reads AT_PHDR/AT_PHNUM/AT_ENTRY to find and set up the main executable.
    // We redirect these to the trampoline, so ld.so loads it instead of ddtrace.so.
    Elf64_auxv_t *at_phdr  = find_auxv_entry(args.auxv, AT_PHDR);
    Elf64_auxv_t *at_phnum = find_auxv_entry(args.auxv, AT_PHNUM);
    Elf64_auxv_t *at_entry = find_auxv_entry(args.auxv, AT_ENTRY);

    if (at_phdr)  at_phdr->a_un.a_val  = tmap.phdr;
    if (at_phnum) at_phnum->a_un.a_val = tmap.phnum;
    if (at_entry) at_entry->a_un.a_val = tmap.entry;

    // Zero AT_SYSINFO_EHDR (vDSO) so ld.so's setup_vdso() is a no-op.
    // setup_vdso() calls elf_get_dynamic_info() on the vDSO's link_map, which
    // runs ADJUST_DYN_INFO - writing to the vDSO's .dynamic in-place. The vDSO
    // is mapped read-only by the kernel, so that write crashes on glibc versions
    // that added DT_RELR support in ADJUST_DYN_INFO before adding the
    // l_ld_readonly guard that skips the write for read-only .dynamic sections.
    {
        Elf64_auxv_t *at_sysinfo = find_auxv_entry(args.auxv, 33 /* AT_SYSINFO_EHDR */);
        if (at_sysinfo) at_sysinfo->a_un.a_val = 0;
    }

    // Step 3: Create a patched memfd so the trampoline can dlopen without PHP
    // Replace all argv entries pointing to ddtrace.so with /proc/self/fd/<mfd>
    // so the trampoline loads the patched (symbol-weakened) copy instead.
    {
        static char fd_path_buf[32];
        static char self_path_buf[512];
        int patched_mfd = create_patched_memfd();
        if (patched_mfd >= 0) {
            // Build "/proc/self/fd/N"
            const char *prefix = "/proc/self/fd/";
            int plen = bs_strlen(prefix);
            bs_memcpy(fd_path_buf, prefix, plen);
            int nlen = uint_to_dec((unsigned int)patched_mfd, fd_path_buf + plen);
            fd_path_buf[plen + nlen] = '\0';

            // Resolve the ddtrace.so path via /proc/self/exe
            long self_len = sys_readlink("/proc/self/exe", self_path_buf,
                                         (long)sizeof(self_path_buf) - 1);
            if (self_len > 0) self_path_buf[self_len] = '\0';
            else self_len = 0;

            // Replace all argv entries (indices 2..argc-2) that match ddtrace.so
            // with the patched memfd path. This covers both argv[2] (the main
            // library to dlopen) and any argv[3+] (additional dependencies).
            for (int i = 2; i < args.argc - 1; i++) {
                const char *a = args.argv[i];
                if (!a || !*a) {
                    // empty slot → replace directly (this is the "temp file" slot)
                    args.argv[i] = fd_path_buf;
                } else if (self_len > 0 &&
                           bs_strlen(a) == (int)self_len &&
                           bs_strncmp(a, self_path_buf, (int)self_len) == 0) {
                    // exact path match → replace
                    args.argv[i] = fd_path_buf;
                }
            }
        }
    }

    // Step 4: Load ld.so / musl
    if (elf_load(ldso_path, &bs_ldso, page_size) < 0)
        bs_fatal("failed to load dynamic linker", 122);

    // set AT_BASE so ld.so knows its own load address
    Elf64_auxv_t *at_base = find_auxv_entry(args.auxv, AT_BASE);
    if (at_base) at_base->a_un.a_val = bs_ldso.base;

    // Step 5: Jump to ld.so's entry
    // ld.so will:
    // * Find the "main executable" via AT_PHDR (the trampoline)
    // * Load trampoline's DT_NEEDED (libc, libdl, libm, libpthread)
    // * Relocate the trampoline fully
    // * Call trampoline's _start
    // * Trampoline main() does dlopen(argv[2]) + dlsym(argv[argc-1]) + calls it
    //
    // AT_ENTRY now points to trampoline's entry, so ld.so calls trampoline's
    // _start. The original argv (process_name, "", ddtrace_path, deps...,
    // symbol) is on the kernel stack and is passed unchanged to the
    // trampoline's main().

    // Restore sp to the original kernel stack and jump to ld.so entry. The
    // function is noreturn - we transfer control via inline asm.

    uintptr_t ldso_entry = bs_ldso.entry;

    // Restore the original kernel stack and jump to ld.so's entry point.
#ifdef __x86_64__
    // On x86, the kernel could pass the rtld_fini function in edx.
    // This is not the case for amd64
    __asm__ volatile(
        "mov %[sp], %%rsp\n"
        "jmp *%[entry]\n"
        :
        : [sp] "r"(stack_top),
        [entry] "r"(ldso_entry)
        : "memory"
    );
#elif defined(__aarch64__)
    __asm__ volatile(
        "mov sp, %0\n"
        "br %1\n"
        :: "r"(stack_top), "r"(ldso_entry)
        : "memory"
    );
#endif
    __builtin_unreachable();
}

// }}}

// ---- Self-relocation {{{
// Apply all R_*_RELATIVE relocations in DT_RELA, DT_JMPREL, and DT_RELR.
// Called from _dd_solib_start asm before any other C code runs.
// Constraints: only uses register arguments and stack locals - no global access.
__attribute__((used))
static void _dd_self_relocate(uintptr_t base, const Elf64_Dyn *dynamic) {
    const Elf64_Rela *rela   = NULL; long relasz   = 0;
    const Elf64_Rela *jmprel = NULL; long pltrelsz = 0;
    const uint64_t   *relr   = NULL; long relrsz   = 0;

    const Elf64_Dyn *d = dynamic;
    while (d->d_tag != DT_NULL) {
        switch (d->d_tag) {
        // eager relocations
        case DT_RELA:     rela    = (const Elf64_Rela *)(base + d->d_un.d_ptr); break;
        case DT_RELASZ:   relasz  = (long)d->d_un.d_val; break;
        // lazy (w/out BIND_NOW) relocations
        case DT_JMPREL:   jmprel  = (const Elf64_Rela *)(base + d->d_un.d_ptr); break;
        case DT_PLTRELSZ: pltrelsz = (long)d->d_un.d_val; break;
        // compact relative relocations
        case DT_RELR:     relr    = (const uint64_t *)(base + d->d_un.d_ptr); break;
        case DT_RELRSZ:   relrsz  = (long)d->d_un.d_val; break;
        default: break;
        }
        d++;
    }
    // d now points to DT_NULL; d+1 is the first byte past .dynamic

#ifdef __aarch64__
    // On aarch64 the linker emits R_AARCH64_RELATIVE for .dynamic d_ptr entries.
    // These relocs are not needed: we already extracted what we needed from
    // .dynamic in the loop above and are not consulting .dynamic again.
    uintptr_t dyn_start = (uintptr_t)dynamic;
    uintptr_t dyn_end   = (uintptr_t)(d + 1); // first byte past DT_NULL
#endif

    // Apply RELATIVE relocs from DT_RELA
    if (rela) {
        const Elf64_Rela *end = (const Elf64_Rela *)((const char *)rela + relasz);
        for (const Elf64_Rela *r = rela; r < end; r++) {
            if (ELF64_R_TYPE(r->r_info) != (unsigned)BS_R_RELATIVE) continue;
#ifdef __aarch64__
            uintptr_t target = base + (uintptr_t)r->r_offset;
            if (target >= dyn_start && target < dyn_end) continue;
#endif
            *(uint64_t *)(base + (uintptr_t)r->r_offset) =
                base + (uint64_t)r->r_addend;
        }
    }

    // Apply RELATIVE relocs from DT_JMPREL (PLT entries; processed eagerly)
    if (jmprel) {
        const Elf64_Rela *end = (const Elf64_Rela *)((const char *)jmprel + pltrelsz);
        for (const Elf64_Rela *r = jmprel; r < end; r++) {
            if (ELF64_R_TYPE(r->r_info) == (unsigned)BS_R_RELATIVE)
                *(uint64_t *)(base + (uintptr_t)r->r_offset) =
                    base + (uint64_t)r->r_addend;
        }
    }

    // Apply DT_RELR compact relative relocations
    if (relr) _dd_apply_relr(base, relr, relrsz);
}

// }}}
// ---- Raw syscall wrappers {{{

#ifdef __x86_64__

static long _syscall1(long n, long a1) {
    long ret;
    __asm__ volatile("syscall" : "=a"(ret) : "a"(n), "D"(a1) : "rcx", "r11", "memory");
    return ret;
}
static long _syscall2(long n, long a1, long a2) {
    long ret;
    __asm__ volatile("syscall" : "=a"(ret) : "a"(n), "D"(a1), "S"(a2) : "rcx", "r11", "memory");
    return ret;
}
static long _syscall3(long n, long a1, long a2, long a3) {
    long ret;
    __asm__ volatile("syscall" : "=a"(ret) : "a"(n), "D"(a1), "S"(a2), "d"(a3) : "rcx", "r11", "memory");
    return ret;
}
static long _syscall4(long n, long a1, long a2, long a3, long a4) {
    long ret;
    register long r10 __asm__("r10") = a4;
    __asm__ volatile("syscall" : "=a"(ret) : "a"(n), "D"(a1), "S"(a2), "d"(a3), "r"(r10) : "rcx", "r11", "memory");
    return ret;
}
static long _syscall6(long n, long a1, long a2, long a3, long a4, long a5, long a6) {
    long ret;
    register long r10 __asm__("r10") = a4;
    register long r8 __asm__("r8") = a5;
    register long r9 __asm__("r9") = a6;
    __asm__ volatile("syscall" : "=a"(ret) : "a"(n), "D"(a1), "S"(a2), "d"(a3), "r"(r10), "r"(r8), "r"(r9) : "rcx", "r11", "memory");
    return ret;
}

#define SYS_READ        0
#define SYS_WRITE       1
#define SYS_OPEN        2
#define SYS_CLOSE       3
#define SYS_LSEEK       8
#define SYS_MMAP        9
#define SYS_MPROTECT    10
#define SYS_MUNMAP      11
#define SYS_PWRITE64    18
#define SYS_SENDFILE    40
#define SYS_READLINK    89
#define SYS_EXIT_GROUP  231
#define SYS_MEMFD_CREATE 319

#elif defined(__aarch64__)

static long _syscall1(long n, long a1) {
    register long x8 __asm__("x8") = n;
    register long x0 __asm__("x0") = a1;
    __asm__ volatile("svc 0" : "+r"(x0) : "r"(x8) : "memory");
    return x0;
}
static long _syscall2(long n, long a1, long a2) {
    register long x8 __asm__("x8") = n;
    register long x0 __asm__("x0") = a1;
    register long x1 __asm__("x1") = a2;
    __asm__ volatile("svc 0" : "+r"(x0) : "r"(x8), "r"(x1) : "memory");
    return x0;
}
static long _syscall3(long n, long a1, long a2, long a3) {
    register long x8 __asm__("x8") = n;
    register long x0 __asm__("x0") = a1;
    register long x1 __asm__("x1") = a2;
    register long x2 __asm__("x2") = a3;
    __asm__ volatile("svc 0" : "+r"(x0) : "r"(x8), "r"(x1), "r"(x2) : "memory");
    return x0;
}
static long _syscall4(long n, long a1, long a2, long a3, long a4) {
    register long x8 __asm__("x8") = n;
    register long x0 __asm__("x0") = a1;
    register long x1 __asm__("x1") = a2;
    register long x2 __asm__("x2") = a3;
    register long x3 __asm__("x3") = a4;
    __asm__ volatile("svc 0" : "+r"(x0) : "r"(x8), "r"(x1), "r"(x2), "r"(x3) : "memory");
    return x0;
}
static long _syscall6(long n, long a1, long a2, long a3, long a4, long a5, long a6) {
    register long x8 __asm__("x8") = n;
    register long x0 __asm__("x0") = a1;
    register long x1 __asm__("x1") = a2;
    register long x2 __asm__("x2") = a3;
    register long x3 __asm__("x3") = a4;
    register long x4 __asm__("x4") = a5;
    register long x5 __asm__("x5") = a6;
    __asm__ volatile("svc 0" : "+r"(x0) : "r"(x8), "r"(x1), "r"(x2), "r"(x3), "r"(x4), "r"(x5) : "memory");
    return x0;
}

#define SYS_READ        63
#define SYS_OPENAT      56
#define SYS_CLOSE       57
#define SYS_LSEEK       62
#define SYS_WRITE       64
#define SYS_SENDFILE    71
#define SYS_READLINKAT  78
#define SYS_PWRITE64    68
#define SYS_EXIT_GROUP  94
#define SYS_MMAP        222
#define SYS_MUNMAP      215
#define SYS_MPROTECT    226
#define SYS_MEMFD_CREATE 279
#ifndef AT_FDCWD
#define AT_FDCWD -100
#endif

#else
#error "solib_bootstrap: unsupported architecture"
#endif

// }}}
// ---- Stack / auxv parsing {{{

static void parse_stack(void *stack_top, struct boot_args *args) {
    long *sp = (long *)stack_top;
    args->argc = (int)*sp;
    args->argv = (char **)(sp + 1);
    args->envp = args->argv + args->argc + 1;
    char **ep = args->envp;
    while (*ep) ep++;
    args->auxv = (Elf64_auxv_t *)(ep + 1);
}

static const char *find_env(char **envp, const char *name) {
    int name_len = bs_strlen(name);
    for (char **ep = envp; *ep; ep++) {
        if (bs_strncmp(*ep, name, name_len) == 0 && (*ep)[name_len] == '=')
            return *ep + name_len + 1;
    }
    return NULL;
}

static Elf64_auxv_t *find_auxv_entry(Elf64_auxv_t *auxv, unsigned long type) {
    for (Elf64_auxv_t *a = auxv; a->a_type != AT_NULL; a++) {
        if (a->a_type == type) return a;
    }
    return NULL;
}

static unsigned long get_auxv(Elf64_auxv_t *auxv, unsigned long type) {
    Elf64_auxv_t *a = find_auxv_entry(auxv, type);
    return a ? a->a_un.a_val : 0;
}

// }}}
// ---- Shared ELF helpers {{{

// Validate ELF magic, class, type, and phnum limit.
// type_mask: bitmask of permitted e_type values, eg (1u<<ET_DYN).
static int elf_check_header(const Elf64_Ehdr *ehdr, unsigned type_mask, int max_phnum) {
    if (ehdr->e_ident[EI_MAG0] != ELFMAG0 || ehdr->e_ident[EI_MAG1] != ELFMAG1 ||
        ehdr->e_ident[EI_MAG2] != ELFMAG2 || ehdr->e_ident[EI_MAG3] != ELFMAG3 ||
        ehdr->e_ident[EI_CLASS] != ELFCLASS64) return -1;
    if (!(type_mask & (1u << ehdr->e_type))) return -1;
    if (ehdr->e_phnum > max_phnum) return -1;
    return 0;
}

// Find the first phdr of a given type, or NULL.
static const Elf64_Phdr *find_phdr(const Elf64_Phdr *phdrs, int phnum, uint32_t type) {
    for (int i = 0; i < phnum; i++)
        if (phdrs[i].p_type == type) return &phdrs[i];
    return NULL;
}

// Compute the page-aligned vaddr range of all PT_LOAD segments, reserve it with
// a PROT_NONE mmap, and return the load bias (base + p_vaddr = runtime
// address). Sets *total to the reserved byte count. Returns -1 on failure.
static int elf_reserve(const Elf64_Phdr *phdrs, int phnum, long page_size,
                       uintptr_t *base_out, long *total_out) {
    uintptr_t lo = (uintptr_t)-1, hi = 0;
    for (int i = 0; i < phnum; i++) {
        if (phdrs[i].p_type != PT_LOAD) continue;
        uintptr_t slo = BS_PAGE_DOWN(phdrs[i].p_vaddr, page_size);
        uintptr_t shi = BS_PAGE_UP(phdrs[i].p_vaddr + phdrs[i].p_memsz, page_size);
        if (slo < lo) lo = slo;
        if (shi > hi) hi = shi;
    }
    if (lo == (uintptr_t)-1) return -1;
    long total = (long)(hi - lo);
    void *base_map = sys_mmap(NULL, total, BS_PROT_NONE,
                               BS_MAP_PRIVATE | BS_MAP_ANONYMOUS, -1, 0);
    if (base_map == BS_MAP_FAILED) return -1;
    *base_out  = (uintptr_t)base_map - lo;
    *total_out = total;
    return 0;
}

// }}}
// ---- Common PT_LOAD segment mapper {{{
//
// Maps all PT_LOAD segments from an open fd into a pre-reserved address space.
// `file_bias` is added to each segment's page-aligned file offset:
// * pass 0 for a standalone file (e.g. ld.so loaded from its own path)
// * pass (DD_TRAMPOLINE_BIN.ptr - &__ehdr_start) for the trampoline embedded
//   in /proc/self/exe
//
// Does NOT close fd on failure; caller is responsible.
//

static int elf_map_segments(int fd, long file_bias, const Elf64_Phdr *phdrs,
                             int phnum, uintptr_t base, long page_size) {
    if (file_bias != BS_PAGE_DOWN(file_bias, page_size)) {
        bs_fatal("file_bias not page-aligned", 123);
        __builtin_unreachable();
    }

    for (int i = 0; i < phnum; i++) {
        if (phdrs[i].p_type != PT_LOAD) continue;

        uintptr_t seg_start     = BS_PAGE_DOWN(phdrs[i].p_vaddr, page_size);
        uintptr_t seg_file_end  = phdrs[i].p_vaddr + phdrs[i].p_filesz;
        uintptr_t seg_mem_end   = phdrs[i].p_vaddr + phdrs[i].p_memsz;
        uintptr_t file_page_end = BS_PAGE_UP(seg_file_end, page_size);
        uintptr_t mem_page_end  = BS_PAGE_UP(seg_mem_end, page_size);
        int prot                = elf_pf_to_prot(phdrs[i].p_flags);

        if (phdrs[i].p_filesz > 0) {
            // ELF spec (gABI): p_vaddr ≡ p_offset (mod p_align), so
            // PAGE_DOWN(p_offset) places p_vaddr at the correct address.
            long file_offset  = file_bias + (long)BS_PAGE_DOWN(phdrs[i].p_offset, page_size);
            long file_map_len = (long)(file_page_end - seg_start);
            void *seg = sys_mmap((void *)(base + seg_start), file_map_len,
                                  prot, BS_MAP_PRIVATE | BS_MAP_FIXED, fd, file_offset);
            if (seg == BS_MAP_FAILED) return -1;

            // Zero tail within the last file-backed page (writable only).
            // Both glibc and musl do this dlopen. One can't trus the linkers to
            // have the zeros in the file.
            if (seg_mem_end > seg_file_end && (phdrs[i].p_flags & PF_W))
                bs_memset((void *)(base + seg_file_end), 0,
                          (long)(file_page_end - seg_file_end));
        }

        // Anonymous pages for BSS.  For pure-BSS segments (p_filesz==0) start
        // at seg_start; for mixed segments start after the last file-backed page.
        uintptr_t anon_start = (phdrs[i].p_filesz > 0) ? file_page_end : seg_start;
        if (mem_page_end > anon_start) {
            void *bss = sys_mmap((void *)(base + anon_start),
                                  (long)(mem_page_end - anon_start),
                                  prot,
                                  BS_MAP_PRIVATE | BS_MAP_FIXED | BS_MAP_ANONYMOUS,
                                  -1, 0);
            if (bss == BS_MAP_FAILED) return -1;
        }
    }
    return 0;
}

// }}}
// ---- Load trampoline ELF from /proc/self/exe {{{
//
// The trampoline binary is embedded in ddtrace.so at DD_TRAMPOLINE_BIN.ptr.
// Since ddtrace.so is /proc/self/exe when executed directly, we open that file
// and mmap each PT_LOAD segment directly from it with the correct permissions.

static int elf_load_trampoline(const void *src, size_t src_len,
                                struct trampoline_map *out, long page_size) {
    bs_memset(out, 0, sizeof(*out));

    if (src_len < sizeof(Elf64_Ehdr)) return -1;
    const Elf64_Ehdr *ehdr = (const Elf64_Ehdr *)src;

    if (elf_check_header(ehdr, 1u << ET_DYN, 32) < 0) return -1;
    // The trampoline must be a PIE (ET_DYN) executable. ET_EXEC cannot be
    // loaded at a random base address. build.rs enforces -fPIE/-pie; if
    // something goes wrong in the build and ET_EXEC slips through, abort
    // loudly rather than silently misbehaving.
    if (ehdr->e_type != ET_DYN) __builtin_trap();
    if (ehdr->e_phoff + (uint64_t)ehdr->e_phnum * sizeof(Elf64_Phdr) > src_len) return -1;

    const Elf64_Phdr *phdrs = (const Elf64_Phdr *)((const char *)src + ehdr->e_phoff);

    // Compute the file offset of the trampoline ELF within /proc/self/exe.
    // __ehdr_start is the runtime load address of ddtrace.so's own ELF header.
    uintptr_t tramp_file_bias = (uintptr_t)src - (uintptr_t)&__ehdr_start;
    if (tramp_file_bias & ((uintptr_t)page_size - 1))
        return -1; // DD_TRAMPOLINE_BIN not page-aligned within ddtrace.so

    int fd = sys_open_rdonly("/proc/self/exe");
    if (fd < 0) return -1;

    uintptr_t base; long total;
    if (elf_reserve(phdrs, ehdr->e_phnum, page_size, &base, &total) < 0) {
        sys_close(fd); return -1;
    }
    if (elf_map_segments(fd, (long)tramp_file_bias, phdrs, ehdr->e_phnum,
                         base, page_size) < 0) {
        sys_close(fd); return -1;
    }
    sys_close(fd);

    out->base      = base;
    out->entry     = base + ehdr->e_entry;
    out->phnum     = ehdr->e_phnum;
    out->total_map = total;
    // Use PT_PHDR.p_vaddr for the phdr runtime address when present;
    // e_phoff is a file offset and works as a vaddr only when the first
    // PT_LOAD has p_vaddr==0 (true for standard PIE, but PT_PHDR is portable).
    out->phdr = base + ehdr->e_phoff;
    const Elf64_Phdr *pt_phdr = find_phdr(phdrs, ehdr->e_phnum, PT_PHDR);
    if (pt_phdr) out->phdr = base + pt_phdr->p_vaddr;
    return 0;
}

// }}}
// ---- ELF loader (for ld.so from file) {{{

static long bs_read_full(int fd, void *buf, long count) {
    long total = 0;
    while (total < count) {
        long n = sys_read(fd, (char *)buf + total, count - total);
        if (n <= 0) return -1;
        total += n;
    }
    return total;
}

// Load an ELF shared library from file (for ld.so / musl)
static int elf_load(const char *path, struct loaded_lib *lib, long page_size) {
    bs_memset(lib, 0, sizeof(*lib));

    int fd = sys_open_rdonly(path);
    if (fd < 0) return -1;

    Elf64_Ehdr ehdr;
    if (bs_read_full(fd, &ehdr, sizeof(ehdr)) < 0) { sys_close(fd); return -1; }
    if (elf_check_header(&ehdr, 1u << ET_DYN, 32) < 0) { sys_close(fd); return -1; }

    Elf64_Phdr phdrs[32];
    long hdr_map_size = (long)BS_PAGE_UP(ehdr.e_phoff + ehdr.e_phnum * sizeof(Elf64_Phdr),
                                          page_size);
    void *hdr_map = sys_mmap(NULL, hdr_map_size, BS_PROT_READ, BS_MAP_PRIVATE, fd, 0);
    if (hdr_map == BS_MAP_FAILED) { sys_close(fd); return -1; }
    bs_memcpy(phdrs, (char *)hdr_map + ehdr.e_phoff, ehdr.e_phnum * sizeof(Elf64_Phdr));
    sys_munmap(hdr_map, hdr_map_size);

    uintptr_t base; long total;
    if (elf_reserve(phdrs, ehdr.e_phnum, page_size, &base, &total) < 0) {
        sys_close(fd); return -1;
    }
    lib->base  = base;
    lib->entry = base + ehdr.e_entry;

    if (elf_map_segments(fd, 0, phdrs, ehdr.e_phnum, base, page_size) < 0) {
        sys_close(fd); return -1;
    }
    sys_close(fd);

    const Elf64_Phdr *pt_dyn = find_phdr(phdrs, ehdr.e_phnum, PT_DYNAMIC);
    if (!pt_dyn) return -1;
    lib->dynamic = (Elf64_Dyn *)(base + pt_dyn->p_vaddr);

    for (Elf64_Dyn *d = lib->dynamic; d->d_tag != DT_NULL; d++) {
        switch (d->d_tag) {
        case DT_SYMTAB:  lib->dynsym = (Elf64_Sym *)(base + d->d_un.d_ptr); break;
        case DT_STRTAB:  lib->dynstr = (const char *)(base + d->d_un.d_ptr); break;
        case DT_GNU_HASH: lib->gnu_hash = (uint32_t *)(base + d->d_un.d_ptr); break;
        case DT_RELA:    lib->rela = (Elf64_Rela *)(base + d->d_un.d_ptr); break;
        case DT_RELASZ:  lib->rela_count = (long)(d->d_un.d_val / sizeof(Elf64_Rela)); break;
        case DT_JMPREL:  lib->jmprel = (Elf64_Rela *)(base + d->d_un.d_ptr); break;
        case DT_PLTRELSZ: lib->jmprel_count = (long)(d->d_un.d_val / sizeof(Elf64_Rela)); break;
        }
    }
    return 0;
}

// }}}
// ---- Patched memfd: make a copy of ddtrace.so with weakened symbols {{{

static long gnu_hash_symcount(const uint32_t *ht);
static long vaddr_to_file_offset(const Elf64_Phdr *phdrs, int phnum, uintptr_t vaddr);

// The trampoline calls dlopen(ddtrace.so) in a process with no PHP loaded.
// Without patching, ld.so aborts on hundreds of unresolved STB_GLOBAL symbols
// (OnUpdateString, zend_hash_find_ex, ...) that normally come from the PHP
// binary.
//
// Strategy: sendfile the entire ddtrace.so into a memfd, then patch two
// sections of the memfd via pwrite:
//
//   Ste 1: .dynamic section:
//     neutralize DT_NEEDED (no PHP deps loaded), DT_INIT/FINI (no PHP init
//     called), DT_VERNEED/VERSYM (no version checking), DT_BIND_NOW / DF flags
//     (lazy PLT ok)
//
//   Step 2: .dynsym table:
//     every STB_GLOBAL/SHN_UNDEF symbol → STB_WEAK, so unresolved PHP symbols
//     silently resolve to NULL rather than aborting dlopen
//
// The patching reads ELF structures from the already exec'd mapping
// (__ehdr_start) to locate the right sections and compute their file offsets,
// then pwrite's the patches into the memfd. The exec'd mapping itself is never
// modified.


// Create a patched memfd from /proc/self/exe.
// Returns the memfd fd (>= 0) on success, -1 on error.
static int create_patched_memfd(void) {
    // Source: the exec'd binary is /proc/self/exe (same inode as ddtrace.so)
    int src = sys_open_rdonly("/proc/self/exe");
    if (src < 0) return -1;

    int mfd = sys_memfd_create("ddtrace_patched", 0);
    if (mfd < 0) {
        bs_fatal("Failed to create memfd", 123);
        __builtin_unreachable();
    }

    // Copy entire file into the memfd via sendfile (in-kernel, no userspace buf)
    for (;;) {
        long n = sys_sendfile(mfd, src, 0x10000000 /*256 MB chunk*/);
        if (n == 0) break;       /* EOF – copy complete */
        if (n == -4) continue;   /* EINTR – retry the interrupted syscall */
        if (n < 0) {
            bs_fatal("Failed to copy ddtrace.so to memfd", 122);
            __builtin_unreachable();
        }
    }
    sys_close(src);

    // Use the exec'd mapping (__ehdr_start) as a read-only guide to locate
    // .dynamic and .dynsym. vaddr_to_file_offset converts each virtual address
    // to the corresponding file offset; that offset is where pwrite patches the memfd.
    uintptr_t base = (uintptr_t)&__ehdr_start;
    const Elf64_Ehdr *ehdr = (const Elf64_Ehdr *)base;
    const Elf64_Phdr *phdrs = (const Elf64_Phdr *)(base + ehdr->e_phoff);

    // Locate PT_DYNAMIC
    long dyn_foff = -1;
    uintptr_t dyn_vaddr = 0;
    for (int i = 0; i < ehdr->e_phnum; i++) {
        if (phdrs[i].p_type == PT_DYNAMIC) {
            dyn_foff  = (long)phdrs[i].p_offset;
            dyn_vaddr = phdrs[i].p_vaddr;
            break;
        }
    }
    if (dyn_foff < 0) { sys_close(mfd); return -1; }

    const Elf64_Dyn *dyn = (const Elf64_Dyn *)(base + dyn_vaddr);

    uintptr_t dynsym_vaddr = 0;
    uintptr_t strtab_vaddr = 0;
    const uint32_t *hash = NULL, *gnu_hash = NULL;

    // Pre-pass: find DT_STRTAB (needed to look up DT_NEEDED library names)
    for (long i = 0; dyn[i].d_tag != DT_NULL; i++) {
        if (dyn[i].d_tag == DT_STRTAB) { strtab_vaddr = dyn[i].d_un.d_ptr; break; }
    }

    // Pass 1: patch dynamic section tags; collect symtab/hash pointers
    for (long i = 0; dyn[i].d_tag != DT_NULL; i++) {
        Elf64_Xword new_tag = 0;
        Elf64_Xword new_val = 0;
        int patch_val = 0;

        // DT_TOMBSTONE must NOT collide with any tag the dynamic linker
        // recognises. The previous value 0x6ffffef5 == DT_GNU_HASH, which
        // caused musl to treat every neutralised entry as a GNU hash table
        // pointer, corrupting dso->ghashtab and crashing in gnu_lookup_filtered.
#define DT_TOMBSTONE 0x6ffffef4
        switch (dyn[i].d_tag) {
        // Neutralize: deps (with exceptions), init/fini, version info, binding flags.
        case DT_NEEDED:
            // Keep libgcc_s and libunwind: they provide _Unwind_RaiseException,
            // which Rust needs for panic handling. Without these, any Rust panic
            // (common in debug builds) causes SIGSEGV. All other DT_NEEDED
            // entries are tombstoned to prevent ld.so from loading PHP-specific
            // or problematic deps (e.g. ld-linux-x86-64.so.2, libcurl).
            if (strtab_vaddr) {
                const char *libname = (const char *)(base + strtab_vaddr) + dyn[i].d_un.d_val;
                if (bs_strncmp(libname, "libgcc_s", 8) == 0 ||
                    bs_strncmp(libname, "libunwind", 9) == 0) {
                    break; /* keep this DT_NEEDED */
                }
            }
            new_tag = DT_TOMBSTONE;
            break;
        case DT_BIND_NOW:
        case DT_INIT:
        case DT_INIT_ARRAY:  case DT_INIT_ARRAYSZ:
        case DT_FINI:
        case DT_FINI_ARRAY:  case DT_FINI_ARRAYSZ:
        case DT_VERNEED:
        case DT_VERNEEDNUM:
        case DT_VERSYM:
        case DT_RELACOUNT: /* neutralize: ld.so doesn't need the count hint */
            new_tag = DT_TOMBSTONE;
            break;
        // Clear DF_BIND_NOW (bit 3) from DT_FLAGS
        case DT_FLAGS:
            new_val = dyn[i].d_un.d_val & ~8ULL;
            patch_val = 1;
            break;
        // Clear DF_1_NOW (bit 0) from DT_FLAGS_1
        case DT_FLAGS_1:
            new_val = dyn[i].d_un.d_val & ~1ULL;
            patch_val = 1;
            break;
        // Track symtab, strtab, and hash tables for dynsym patching
        case DT_SYMTAB:    dynsym_vaddr = dyn[i].d_un.d_ptr; break;
        case DT_STRTAB:    strtab_vaddr = dyn[i].d_un.d_ptr; break;
        case DT_HASH:      hash     = (const uint32_t *)(base + dyn[i].d_un.d_ptr); break;
        case DT_GNU_HASH:  gnu_hash = (const uint32_t *)(base + dyn[i].d_un.d_ptr); break;
        default: break;
        }

        if (new_tag) {
            long entry_off = dyn_foff + i * (long)sizeof(Elf64_Dyn);
            sys_pwrite(mfd, &new_tag, sizeof(new_tag), entry_off);
        }
        if (patch_val) {
            long val_off = dyn_foff + i * (long)sizeof(Elf64_Dyn) + 8;
            sys_pwrite(mfd, &new_val, sizeof(new_val), val_off);
        }
    }

    // Pass 2: weaken STB_GLOBAL/SHN_UNDEF dynsym entries
    if (!dynsym_vaddr) { sys_close(mfd); return -1; }

    long dynsym_foff = vaddr_to_file_offset(phdrs, ehdr->e_phnum, dynsym_vaddr);
    if (dynsym_foff < 0) { sys_close(mfd); return -1; }

    long symcount = 0;
    if (hash)
        symcount = (long)hash[1]; // nchain = total symbol count
    else if (gnu_hash)
        symcount = gnu_hash_symcount(gnu_hash);
    if (!symcount) { sys_close(mfd); return -1; }

    const Elf64_Sym *syms = (const Elf64_Sym *)(base + dynsym_vaddr);
    for (long i = 0; i < symcount; i++) {
        if (ELF64_ST_BIND(syms[i].st_info) == STB_GLOBAL &&
            syms[i].st_shndx == SHN_UNDEF) {
            unsigned char new_info = (unsigned char)ELF64_ST_INFO(
                STB_WEAK, ELF64_ST_TYPE(syms[i].st_info));
            // st_info is at offset 4 within Elf64_Sym (after st_name)
            sys_pwrite(mfd, &new_info, 1,
                       dynsym_foff + i * (long)sizeof(Elf64_Sym) + 4);
        }
    }

    return mfd;
}

// Count dynamic symbols via GNU hash table.
static long gnu_hash_symcount(const uint32_t *ht) {
    uint32_t nbuckets  = ht[0];
    uint32_t symndx    = ht[1];
    uint32_t maskwords = ht[2]; // count of 64-bit bloom filter words
    const uint32_t *buckets = &ht[4 + maskwords * 2];
    const uint32_t *chains  = &buckets[nbuckets];

    uint32_t max_sym = symndx;
    for (uint32_t b = 0; b < nbuckets; b++) {
        uint32_t idx = buckets[b];
        if (!idx) continue;
        uint32_t off = idx - symndx;
        for (;;) {
            if (idx > max_sym) max_sym = idx;
            if (chains[off] & 1) break;
            off++; idx++;
        }
    }
    return (long)(max_sym + 1);
}

// Compute file offset of a virtual address within a PT_LOAD segment.
static long vaddr_to_file_offset(const Elf64_Phdr *phdrs, int phnum, uintptr_t vaddr) {
    for (int i = 0; i < phnum; i++) {
        if (phdrs[i].p_type != PT_LOAD) continue;
        if (vaddr >= phdrs[i].p_vaddr &&
            vaddr <  phdrs[i].p_vaddr + phdrs[i].p_filesz)
            return (long)(phdrs[i].p_offset + (vaddr - phdrs[i].p_vaddr));
    }
    return -1;
}

// Write an unsigned int as decimal into buf (no nul terminator). Returns len.
static int uint_to_dec(unsigned int v, char *buf) {
    char tmp[12]; int n = 0;
    if (!v) { buf[0] = '0'; return 1; }
    while (v) { tmp[n++] = '0' + (v % 10); v /= 10; }
    for (int i = 0; i < n; i++) buf[i] = tmp[n - 1 - i];
    return n;
}

// }}}
// ---- DT_RELR (compact relative relocations) {{{
// Called from _dd_solib_start asm after DT_RELA/DT_JMPREL RELATIVE relocs have
// been applied. Uses only register arguments and stack locals - no GOT access.
// Algorithm matches musl ldso/dlstart.c (and glibc ELF_DYNAMIC_DO_RELR):
// * Entry with low bit 0: absolute address -> *addr += base; addr++
// * Entry with low bit 1: bitmap -> for each set bit i in bits[1..63]: addr[i] += base;
//                         then addr += 63  (covers 63 slots per bitmap word)
__attribute__((used))
static void _dd_apply_relr(uintptr_t base, const uint64_t *relr, long relrsz) {
    uint64_t *addr = NULL;
    for (; relrsz > 0; relr++, relrsz -= 8) {
        if ((*relr & 1) == 0) {
            addr = (uint64_t *)(base + *relr);
            *addr++ += base;
        } else {
            uint64_t bitmap = *relr >> 1;
            for (uint64_t i = 0; bitmap; bitmap >>= 1, i++)
                if (bitmap & 1) addr[i] += base;
            addr += 63;
        }
    }
}

// }}}
// ---- Error output {{{

static void bs_write_str(const char *s) {
    sys_write(2, s, bs_strlen(s));
}

static noreturn void bs_fatal(const char *msg, int code) {
    bs_write_str("dd_solib_bootstrap: ");
    bs_write_str(msg);
    bs_write_str("\n");
    sys_exit_group(code);
}

// }}}
// ---- Minimal string/memory utilities {{{

// The compiler is too smart for its own good. We must prevent it from
// replacing this calls  with PLT calls to libc strlen/memcpy/memset,
// which would crash because those GOT entries are unresolved when the bootstrap
// runs. (TODO: try harder with -fno-builtin?)
__attribute__((noinline))
static int bs_strlen(const char *s) {
    int n = 0;
    while (s[n]) { __asm__ volatile("" : "+r"(n)); n++; }
    return n;
}

__attribute__((noinline))
static int bs_strncmp(const char *a, const char *b, int n) {
    for (int i = 0; i < n; i++) {
        __asm__ volatile("" : "+r"(i));
        if (a[i] != b[i]) return (unsigned char)a[i] - (unsigned char)b[i];
        if (!a[i]) return 0;
    }
    return 0;
}

__attribute__((noinline))
static void bs_memcpy(void *dst, const void *src, long n) {
    char *d = dst; const char *s = src;
    while (n--) { __asm__ volatile("" : "+r"(d) : "r"(s)); *d++ = *s++; }
}

__attribute__((noinline))
static void bs_memset(void *dst, int c, long n) {
    char *d = dst;
    while (n--) { __asm__ volatile("" : "+r"(d)); *d++ = (char)c; }
}

static int elf_pf_to_prot(uint32_t pf) {
    int prot = 0;
    if (pf & PF_R) prot |= BS_PROT_READ;
    if (pf & PF_W) prot |= BS_PROT_WRITE;
    if (pf & PF_X) prot |= BS_PROT_EXEC;
    return prot;
}

// }}}
// ---- Syscall convenience wrappers {{{

static int sys_open_rdonly(const char *path) {
#ifdef SYS_OPEN
    return (int)_syscall2(SYS_OPEN, (long)path, 0 /*O_RDONLY*/);
#else
    return (int)_syscall3(SYS_OPENAT, AT_FDCWD, (long)path, 0 /*O_RDONLY*/);
#endif
}

static long sys_read(int fd, void *buf, long count) {
    return _syscall3(SYS_READ, fd, (long)buf, count);
}

static long sys_write(int fd, const void *buf, long count) {
    return _syscall3(SYS_WRITE, fd, (long)buf, count);
}

static int sys_close(int fd) {
    return (int)_syscall1(SYS_CLOSE, fd);
}

static void *sys_mmap(void *addr, long length, int prot, int flags, int fd, long offset) {
    return (void *)_syscall6(SYS_MMAP, (long)addr, length, prot, flags, fd, offset);
}

static int sys_munmap(void *addr, long length) {
    return (int)_syscall2(SYS_MUNMAP, (long)addr, length);
}

static noreturn void sys_exit_group(int status) {
    _syscall1(SYS_EXIT_GROUP, status);
    __builtin_unreachable();
}

static long sys_readlink(const char *path, char *buf, long bufsiz) {
#ifdef __x86_64__
    return _syscall3(SYS_READLINK, (long)path, (long)buf, bufsiz);
#elif defined(__aarch64__)
    // aarch64 has readlinkat only
    return _syscall4(SYS_READLINKAT, AT_FDCWD, (long)path, (long)buf, bufsiz);
#endif
}

static int sys_memfd_create(const char *name, unsigned int flags) {
    return (int)_syscall2(SYS_MEMFD_CREATE, (long)name, (long)flags);
}

static long sys_sendfile(int out_fd, int in_fd, long count) {
    return _syscall4(SYS_SENDFILE, out_fd, in_fd, 0L /*offset=NULL*/, count);
}

static long sys_pwrite(int fd, const void *buf, long count, long offset) {
    return _syscall4(SYS_PWRITE64, fd, (long)buf, count, offset);
}

// }}}

#ifdef __clang__
#  pragma clang attribute pop
#endif
#endif // __linux__

// vim: foldmethod=marker
