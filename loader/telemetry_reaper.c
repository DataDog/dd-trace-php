#include "telemetry_reaper.h"

#include <errno.h>
#include <pthread.h>
#include <stdint.h>
#include <string.h>
#include <sys/mman.h>
#include <sys/wait.h>
#include <unistd.h>

#if (!defined(__x86_64__) && !defined(__aarch64__)) || defined(__ILP32__)
#error Unsupported architecture for the telemetry reaper
#endif

#if defined(__aarch64__) && defined(__ARM_FEATURE_BTI_DEFAULT)
#include <asm/hwcap.h>
#include <sys/auxv.h>

// Compatibility with the CentOS 7 headers used for release builds.
#ifndef HWCAP2_BTI
#define HWCAP2_BTI (1UL << 17)
#endif
#ifndef PROT_BTI
#define PROT_BTI 0x10
#endif
#endif

// The context precedes the copied code; keep its entry point aligned.
typedef struct __attribute__((aligned(16))) {
    size_t mapping_size;
    pid_t pid;
    pid_t (*wait_for_child)(pid_t, int *, int);
    int *(*error_location)(void);
    int (*unmap)(void *, size_t);
} ddloader_reaper_context;

// Linker-provided bounds for the reaper code size.
extern const char __start_ddloader_reaper_code[] __attribute__((visibility("hidden")));
extern const char __stop_ddloader_reaper_code[] __attribute__((visibility("hidden")));

#if __has_attribute(musttail)
#define DDLOADER_MUSTTAIL __attribute__((musttail))
#elif defined(__clang__) && __clang_major__ >= 13
#define DDLOADER_MUSTTAIL [[clang::musttail]]
#else
#define DDLOADER_MUSTTAIL
#endif

// No code or data reference may point back into the loader, including compiler
// instrumentation. All libc calls go through pointers in the copied context.
// The release build checks that this section contains no relocations.
__attribute__((section("ddloader_reaper_code"), used, noinline, no_instrument_function,
               no_profile_instrument_function))
#if defined(__clang__)
// no_sanitize covers frontend checks; disabling instrumentation also removes
// TSan's function entry/exit hooks, which otherwise survive no_sanitize("all").
__attribute__((no_stack_protector, no_sanitize("all"), disable_sanitizer_instrumentation))
#else
// Older GCC has no musttail attribute; force sibling calls even in debug builds.
__attribute__((no_sanitize_address, no_sanitize_thread, no_sanitize_undefined,
               optimize("O2", "optimize-sibling-calls", "no-stack-protector")))
#endif
static int ddloader_reap_child(void *mapping, size_t unused) {
    (void)unused;
    ddloader_reaper_context *context = mapping;
    while (context->wait_for_child(context->pid, NULL, 0) == -1 &&
           *context->error_location() == EINTR) {
    }
    // Match munmap's signature so musttail can guarantee that it returns straight
    // to pthread's startup routine, never into the page it has just unmapped.
    DDLOADER_MUSTTAIL return context->unmap(mapping, context->mapping_size);
}

int ddloader_reaper_start(pid_t pid) {
    size_t code_size = (uintptr_t)__stop_ddloader_reaper_code - (uintptr_t)__start_ddloader_reaper_code;
    size_t entry_offset = (uintptr_t)ddloader_reap_child - (uintptr_t)__start_ddloader_reaper_code;
    long page_size = sysconf(_SC_PAGESIZE);
    if (page_size <= 0 || sizeof(ddloader_reaper_context) + code_size > (size_t)page_size) {
        return EINVAL;
    }
    size_t mapping_size = (size_t)page_size;
    ddloader_reaper_context *context = mmap(NULL, mapping_size, PROT_READ | PROT_WRITE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    if (context == MAP_FAILED) {
        return errno;
    }
    // Taking libc function addresses resolves them before this DSO can unload;
    // the copied function must not use the loader's PLT or GOT, even for errno.
    *context = (ddloader_reaper_context){mapping_size, pid, waitpid, __errno_location, munmap};
    void *code = context + 1;
    memcpy(code, __start_ddloader_reaper_code, code_size);
    __builtin___clear_cache(code, (char *)code + code_size);

    int protection = PROT_READ | PROT_EXEC;
#if defined(__aarch64__) && defined(__ARM_FEATURE_BTI_DEFAULT)
    // Only enable BTI when the compiler emitted its landing pad in the copy.
    if (getauxval(AT_HWCAP2) & HWCAP2_BTI) {
        protection |= PROT_BTI;
    }
#endif
    if (mprotect(context, mapping_size, protection)) {
        int error = errno;
        munmap(context, mapping_size);
        return error;
    }

    pthread_attr_t attributes;
    int error = pthread_attr_init(&attributes);
    if (!error) {
        error = pthread_attr_setdetachstate(&attributes, PTHREAD_CREATE_DETACHED);
        if (!error) {
            pthread_t thread;
            // On the supported 64-bit ABIs the unused second argument needs no
            // initialization, and a detached thread's return value is discarded.
            error = pthread_create(&thread, &attributes, (void *(*)(void *))((char *)code + entry_offset), context);
        }
        pthread_attr_destroy(&attributes);
    }
    if (error) {
        munmap(context, mapping_size);
    }
    return error;
}
