#include "telemetry_reaper.h"

#include <errno.h>
#include <stdint.h>
#include <string.h>
#include <sys/mman.h>
#include <unistd.h>

#if defined(__aarch64__)
#include <asm/hwcap.h>
#include <sys/auxv.h>

// Remove these fallback definitions once release builds stop using CentOS 7-era headers.
#ifndef HWCAP2_BTI
#define HWCAP2_BTI (1UL << 17)
#endif
#ifndef PROT_BTI
#define PROT_BTI 0x10
#endif
#endif

extern const unsigned char ddloader_reaper_code_start[];
extern const unsigned char ddloader_reaper_code_context[];
extern const unsigned char ddloader_reaper_code_end[];

// Matches the two 64-bit words at ddloader_reaper_code_context in the assembly.
typedef struct {
    size_t mapping_size;
    int (*unmap)(void *, size_t);
} ddloader_reaper_context;

_Static_assert(sizeof(ddloader_reaper_context) == 16, "reaper context ABI");

int ddloader_reaper_prepare(ddloader_reaper *reaper) {
    int error = pthread_attr_init(&reaper->attributes);
    if (error) {
        return error;
    }
    reaper->mapping = NULL;
    error = pthread_attr_setdetachstate(&reaper->attributes, PTHREAD_CREATE_DETACHED);
    if (error) {
        goto fail;
    }

    long page_size = sysconf(_SC_PAGESIZE);
    size_t code_size = (uintptr_t)ddloader_reaper_code_end - (uintptr_t)ddloader_reaper_code_start;
    if (page_size <= 0 || code_size > (size_t)page_size) {
        error = EINVAL;
        goto fail;
    }
    reaper->mapping_size = (size_t)page_size;
    reaper->mapping = mmap(NULL, reaper->mapping_size, PROT_READ | PROT_WRITE,
                          MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    if (reaper->mapping == MAP_FAILED) {
        reaper->mapping = NULL;
        error = errno;
        goto fail;
    }

    memcpy(reaper->mapping, ddloader_reaper_code_start, code_size);
    size_t context_offset = (uintptr_t)ddloader_reaper_code_context - (uintptr_t)ddloader_reaper_code_start;
    // Taking munmap's address in PIC loads its eagerly resolved GOT entry,
    // bypassing this DSO's PLT (see below) even though ordinary munmap() calls
    // use it. The copied pointer no longer depends on this DSO's GOT or PLT.
    //
    // Our undefined munmap symbol has st_value == 0, so lookup cannot select
    // this DSO's PLT. See musl's find_sym2() and glibc's check_match(), called
    // during DSO relocation to eagerly resolve GLOB_DAT GOT entries:
    // https://github.com/kraj/musl/blob/0784374d561435f7c787a555aeab8ede699ed298/ldso/dynlink.c#L331-L333
    // https://github.com/bminor/glibc/blob/3d1aed874918c466a4477af1da35983ab036690e/elf/dl-lookup.c#L73-L78
    // A canonical PLT entry in the main executable is safe:
    // it remains mapped after this DSO unloads.
    //
    // Also, we're assuming libc's munmap has not been preempted. If we wanted
    // to cover that, we'd need to use dlopen of libc.so.6 with dlsym
    ddloader_reaper_context context = {reaper->mapping_size, munmap};
    memcpy((char *)reaper->mapping + context_offset, &context, sizeof(context));
    __builtin___clear_cache(reaper->mapping, (char *)reaper->mapping + code_size);
    int protection = PROT_READ | PROT_EXEC;
#if defined(__aarch64__)
    // ELF properties do not protect this anonymous copy; enable BTI explicitly.
    if (getauxval(AT_HWCAP2) & HWCAP2_BTI) {
        protection |= PROT_BTI;
    }
#endif
    if (mprotect(reaper->mapping, reaper->mapping_size, protection)) {
        error = errno;
        goto fail;
    }
    return 0;

fail:
    ddloader_reaper_discard(reaper);
    return error;
}

int ddloader_reaper_start(ddloader_reaper *reaper, pid_t pid) {
    pthread_t thread;
    // No thread entry wrapper may live in this DSO: Zend can unload it as soon
    // as we return, even before the new thread is first scheduled.
    int error = pthread_create(&thread, &reaper->attributes,
                               (void *(*)(void *))reaper->mapping, (void *)(intptr_t)pid);
    if (!error) {
        reaper->mapping = NULL;
    }
    ddloader_reaper_discard(reaper);
    return error;
}

void ddloader_reaper_discard(ddloader_reaper *reaper) {
    if (reaper->mapping) {
        munmap(reaper->mapping, reaper->mapping_size);
        reaper->mapping = NULL;
    }
    pthread_attr_destroy(&reaper->attributes);
}
