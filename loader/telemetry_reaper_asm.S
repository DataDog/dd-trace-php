#include <asm/unistd.h>
#include <asm/errno.h>

// This entire routine and its immutable context are copied into a separate RX
// mapping before pthread_create(). It must have no references to the original
// DSO, including its PLT, GOT or unwind tables. Only caller-saved registers are
// used, preserving the return address into glibc's pthread startup routine.
//
// After waiting, tail-call libc's munmap() to release our own mapping and return
// directly to glibc for thread cleanup. In particular, never call dlclose(): it
// can unload other DSOs whose exit destructors are running on another thread.

.text
.balign 16
.globl ddloader_reaper_code_start
.globl ddloader_reaper_code_context
.globl ddloader_reaper_code_end
.hidden ddloader_reaper_code_start
.hidden ddloader_reaper_code_context
.hidden ddloader_reaper_code_end
ddloader_reaper_code_start:
.Lreaper_start:
#if defined(__x86_64__) && !defined(__ILP32__)
    .type ddloader_reaper_code_start, @function
    // Use the encoding until CentOS 7/devtoolset-7 is dropped; binutils 2.28
    // does not recognize the mnemonic. This can then be replaced with endbr64.
    .byte 0xf3, 0x0f, 0x1e, 0xfa
    leaq .Lreaper_start(%rip), %r8
    // The pthread argument is the child PID. wait4 preserves it in rdi.
    xorl %esi, %esi
    xorl %edx, %edx
    xorl %r10d, %r10d
.Lwait:
    movl $__NR_wait4, %eax
    syscall
    cmpq $-EINTR, %rax
    je .Lwait
    movq .Lcontext+8-.Lreaper_start(%r8), %rax
    movq .Lcontext-.Lreaper_start(%r8), %rsi
    movq %r8, %rdi
    jmp *%rax
#elif defined(__aarch64__) && !defined(__ILP32__)
    .type ddloader_reaper_code_start, %function
    // Use the encoding until CentOS 7/devtoolset-7 is dropped; binutils 2.28
    // does not recognize the mnemonic. This can then be replaced with bti c.
    .inst 0xd503245f
    adr x9, .Lreaper_start
    mov w10, w0
    mov x1, xzr
    mov x2, xzr
    mov x3, xzr
.Lwait:
    mov w0, w10 // Restore the PID: x0 also holds the syscall return value.
    mov x8, #__NR_wait4
    svc #0
    cmn x0, #EINTR
    b.eq .Lwait
    ldr x16, [x9, #(.Lcontext+8-.Lreaper_start)]
    ldr x1, [x9, #(.Lcontext-.Lreaper_start)]
    mov x0, x9
    br x16 // x16 permits a tail call to a BTI c landing pad.
#else
#error Unsupported architecture for the telemetry reaper
#endif
.size ddloader_reaper_code_start, .-ddloader_reaper_code_start
.balign 8
ddloader_reaper_code_context:
.Lcontext:
    .quad 0 // Mapping size, filled before making the copy executable.
    .quad 0 // Resolved libc munmap address.
ddloader_reaper_code_end:

#ifdef __ELF__
.section .note.GNU-stack,"",%progbits

// Match the compiler's hardening properties so this object does not clear them
// at link time. These notes are outside the copied code and context.
#if defined(__x86_64__) && defined(__CET__)
#define DDLOADER_PROPERTY_TYPE 0xc0000002 // GNU_PROPERTY_X86_FEATURE_1_AND
#define DDLOADER_PROPERTY_BITS (__CET__ & 3) // IBT and SHSTK
#elif defined(__aarch64__)
#define DDLOADER_PROPERTY_TYPE 0xc0000000 // GNU_PROPERTY_AARCH64_FEATURE_1_AND
#if defined(__ARM_FEATURE_BTI_DEFAULT)
#define DDLOADER_BTI 1
#else
#define DDLOADER_BTI 0
#endif
#if defined(__ARM_FEATURE_PAC_DEFAULT) && (__ARM_FEATURE_PAC_DEFAULT & 3)
#define DDLOADER_PAC 2
#else
#define DDLOADER_PAC 0
#endif
#if defined(__ARM_FEATURE_GCS_DEFAULT)
#define DDLOADER_GCS 4
#else
#define DDLOADER_GCS 0
#endif
#define DDLOADER_PROPERTY_BITS (DDLOADER_BTI | DDLOADER_PAC | DDLOADER_GCS)
#endif

#if defined(DDLOADER_PROPERTY_BITS) && DDLOADER_PROPERTY_BITS
.pushsection .note.gnu.property,"a",%note
.balign 8
.long 4 // Name size, including the terminating NUL.
.long 16 // Descriptor size, including padding.
.long 5 // NT_GNU_PROPERTY_TYPE_0
.asciz "GNU"
.long DDLOADER_PROPERTY_TYPE
.long 4 // Property data size.
.long DDLOADER_PROPERTY_BITS
.long 0 // Pad the descriptor to an 8-byte boundary.
.popsection
#endif
#endif // __ELF__
