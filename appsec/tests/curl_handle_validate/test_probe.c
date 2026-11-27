// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// dd_curl_validate_easy_ptr() with the real process_vm_readv(2) probe, also
// under seccomp filters that refuse the syscall. Each case runs in a child
// process, so that the filters it installs don't affect the others.

#ifndef _GNU_SOURCE
#    define _GNU_SOURCE
#endif
#include "../../src/extension/downstream/curl_handle_validate.h"

#include <errno.h>
#include <linux/filter.h>
#include <linux/seccomp.h>
#include <signal.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <sys/prctl.h>
#include <sys/syscall.h>
#include <sys/wait.h>
#include <unistd.h>

#define LIBCURL_NEW 0x075000 // magic number checked
#define LIBCURL_OLD 0x074a00 // no magic number

static int failures;

#define CHECK(cond)                                                            \
    do {                                                                       \
        if (!(cond)) {                                                         \
            fprintf(stderr, "  %s:%d: check failed: %s\n", __FILE__, __LINE__, \
                #cond);                                                        \
            _exit(1);                                                          \
        }                                                                      \
    } while (0)

// process_vm_readv gets `action` (SECCOMP_RET_*), as does write(2) if
// `and_write`; everything else is allowed
static void _filter_vm_readv(uint32_t action, bool and_write)
{
    struct sock_filter f[] = {
        BPF_STMT(BPF_LD | BPF_W | BPF_ABS, offsetof(struct seccomp_data, nr)),
        BPF_JUMP(BPF_JMP | BPF_JEQ | BPF_K, __NR_process_vm_readv, 2, 0),
        BPF_JUMP(BPF_JMP | BPF_JEQ | BPF_K, __NR_write, 1, 0),
        BPF_STMT(BPF_RET | BPF_K, SECCOMP_RET_ALLOW),
        BPF_STMT(BPF_RET | BPF_K, action),
    };
    if (!and_write) {
        f[2].k = (uint32_t)-1; // never matches
    }
    struct sock_fprog p = {.len = sizeof(f) / sizeof(f[0]), .filter = f};
    CHECK(prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) == 0);
    CHECK(prctl(PR_SET_SECCOMP, SECCOMP_MODE_FILTER, &p) == 0);
}

static dd_curl_easy_check _validate(
    uintptr_t cp, unsigned version, int *probe_errno)
{
    char reason[256] = "";
    dd_curl_easy_check res = dd_curl_validate_easy_ptr(
        cp, dd_curl_probe, version, reason, sizeof(reason), probe_errno);
    if (res != DD_CURL_EASY_VALID) {
        fprintf(stderr, "  refused: %s\n", reason);
    }
    return res;
}

static uint64_t _fake_easy[2] = {DD_CURLEASY_MAGIC_NUMBER, 0};
static uint64_t _garbage[2] = {0x4141414141414141, 0};

static void _case_valid(void)
{
    int err = 0;
    CHECK(_validate((uintptr_t)_fake_easy, LIBCURL_NEW, &err) ==
          DD_CURL_EASY_VALID);
    // no magic number before libcurl 7.75: only readability is checked
    CHECK(_validate((uintptr_t)_garbage, LIBCURL_OLD, &err) ==
          DD_CURL_EASY_VALID);
}

static void _case_unmapped(void)
{
    int err = 0;
    long page = sysconf(_SC_PAGESIZE);
    void *p =
        mmap(NULL, (size_t)page, PROT_NONE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    CHECK(p != MAP_FAILED);
    CHECK(_validate((uintptr_t)p, LIBCURL_NEW, &err) == DD_CURL_EASY_INVALID);
    CHECK(_validate((uintptr_t)p, LIBCURL_OLD, &err) == DD_CURL_EASY_INVALID);
    CHECK(_validate(0x8000, LIBCURL_NEW, &err) == DD_CURL_EASY_INVALID);
    CHECK(_validate(0x8, LIBCURL_NEW, &err) == DD_CURL_EASY_INVALID);
}

static void _case_garbage(void)
{
    int err = 0;
    CHECK(_validate((uintptr_t)_garbage, LIBCURL_NEW, &err) ==
          DD_CURL_EASY_INVALID);
    // not aligned
    CHECK(_validate((uintptr_t)_fake_easy + 4, LIBCURL_NEW, &err) ==
          DD_CURL_EASY_INVALID);
}

// non-canonical and kernel-half addresses need no check of our own: the
// kernel refuses to read them
static void _case_non_canonical(void)
{
    int err = 0;
    CHECK(_validate(UINT64_C(0x0000800000000000), LIBCURL_NEW, &err) ==
          DD_CURL_EASY_INVALID);
    CHECK(_validate(UINT64_C(0x1000000000000000), LIBCURL_NEW, &err) ==
          DD_CURL_EASY_INVALID);
    CHECK(_validate(UINT64_C(0xffff800000000000), LIBCURL_NEW, &err) ==
          DD_CURL_EASY_INVALID);
    CHECK(_validate(UINT64_C(0xfffffffffffffff8), LIBCURL_NEW, &err) ==
          DD_CURL_EASY_INVALID);
}

// process_vm_readv is refused: the pipe probe still works
static void _case_refused_fallback(int errnum)
{
    _filter_vm_readv(
        SECCOMP_RET_ERRNO | ((uint32_t)errnum & SECCOMP_RET_DATA), false);
    int err = 0;
    CHECK(_validate((uintptr_t)_fake_easy, LIBCURL_NEW, &err) ==
          DD_CURL_EASY_VALID);
    CHECK(_validate((uintptr_t)_garbage, LIBCURL_NEW, &err) ==
          DD_CURL_EASY_INVALID);
    long page = sysconf(_SC_PAGESIZE);
    void *p =
        mmap(NULL, (size_t)page, PROT_NONE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    CHECK(p != MAP_FAILED);
    CHECK(_validate((uintptr_t)p, LIBCURL_NEW, &err) == DD_CURL_EASY_INVALID);
    CHECK(_validate(0x8000, LIBCURL_NEW, &err) == DD_CURL_EASY_INVALID);
    CHECK(_validate(UINT64_C(0xffff800000000000), LIBCURL_NEW, &err) ==
          DD_CURL_EASY_INVALID);
}

// neither process_vm_readv nor the pipe probe can be used
static void _case_refused(int errnum)
{
    _filter_vm_readv(
        SECCOMP_RET_ERRNO | ((uint32_t)errnum & SECCOMP_RET_DATA), true);
    int err = 0;
    // refused probe: nothing is known about the pointer, valid or not
    CHECK(_validate((uintptr_t)_fake_easy, LIBCURL_NEW, &err) ==
          DD_CURL_EASY_UNVERIFIED);
    CHECK(err == errnum);
    err = 0;
    CHECK(_validate(0x8000, LIBCURL_NEW, &err) == DD_CURL_EASY_UNVERIFIED);
    CHECK(err == errnum);
}

static void _case_eperm_pipe(void) { _case_refused_fallback(EPERM); }
static void _case_enosys_pipe(void) { _case_refused_fallback(ENOSYS); }
static void _case_eperm(void) { _case_refused(EPERM); }
static void _case_enosys(void) { _case_refused(ENOSYS); }

static void _run(const char *name, void (*fn)(void))
{
    fflush(stdout);
    fflush(stderr);
    pid_t pid = fork();
    if (pid < 0) {
        perror("fork");
        exit(2);
    }
    if (pid == 0) {
        fn();
        _exit(0);
    }
    int status;
    if (waitpid(pid, &status, 0) != pid) {
        perror("waitpid");
        exit(2);
    }
    if (WIFEXITED(status) && WEXITSTATUS(status) == 0) {
        printf("ok %s\n", name);
        return;
    }
    failures++;
    if (WIFSIGNALED(status)) {
        printf("FAIL %s (killed by signal %d)\n", name, WTERMSIG(status));
    } else {
        printf("FAIL %s (exit %d)\n", name, WEXITSTATUS(status));
    }
}

int main(void)
{
    _run("valid", _case_valid);
    _run("unmapped", _case_unmapped);
    _run("garbage", _case_garbage);
    _run("non_canonical", _case_non_canonical);
    _run("vm_readv_eperm_pipe", _case_eperm_pipe);
    _run("vm_readv_enosys_pipe", _case_enosys_pipe);
    _run("vm_readv_eperm", _case_eperm);
    _run("vm_readv_enosys", _case_enosys);
    printf("%d failure(s)\n", failures);
    return failures == 0 ? 0 : 1;
}
