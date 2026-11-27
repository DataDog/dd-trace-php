// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef _GNU_SOURCE
#    define _GNU_SOURCE // process_vm_readv
#endif

#include "curl_handle_validate.h"

#include <errno.h>
#include <inttypes.h>
#include <stdio.h>

#ifdef __linux__
#    include <fcntl.h>
#    include <sys/types.h>
#    include <sys/uio.h>
#    include <unistd.h>
#endif

// struct Curl_easy is malloc'ed: at least 8-byte aligned on our platforms
enum { DD_CURL_EASY_ALIGN = 8 };

const char *dd_curl_validate_handlers_offset(long offset)
{
    if (offset < (long)sizeof(void *)) {
        // cp is the first member of php_curl and std comes after it
        return "the CurlHandle object handlers' offset is smaller than a "
               "pointer";
    }
    if (offset % (long)sizeof(void *) != 0) {
        return "the CurlHandle object handlers' offset is not a multiple of "
               "the pointer size";
    }
    if (offset >= DD_CURL_MAX_HANDLERS_OFFSET) {
        return "the CurlHandle object handlers' offset is implausibly large";
    }
    return NULL;
}

dd_curl_easy_check dd_curl_validate_easy_ptr(uintptr_t cp,
    dd_curl_probe_fn probe, unsigned int libcurl_version_num, char *reason,
    size_t reason_size, int *probe_errno)
{
    if (cp % DD_CURL_EASY_ALIGN != 0) {
        (void)snprintf(reason, reason_size,
            "CURL handle pointer %#" PRIxPTR " is not 8-byte aligned", cp);
        return DD_CURL_EASY_INVALID;
    }

    uint32_t first;
    int res = probe(cp, &first, sizeof(first));
    if (res == EFAULT) {
        (void)snprintf(reason, reason_size,
            "CURL handle pointer %#" PRIxPTR " points to unreadable memory",
            cp);
        return DD_CURL_EASY_INVALID;
    }
    if (res != 0) {
        *probe_errno = res;
        (void)snprintf(reason, reason_size,
            "CURL handle pointer %#" PRIxPTR
            " cannot be validated: process_vm_readv failed:",
            cp);
        return DD_CURL_EASY_UNVERIFIED;
    }

    if (libcurl_version_num >= DD_CURL_MAGIC_AT_OFFSET_0_SINCE &&
        first != DD_CURLEASY_MAGIC_NUMBER) {
        (void)snprintf(reason, reason_size,
            "CURL handle pointer %#" PRIxPTR
            " does not point to a libcurl easy handle (magic %#" PRIx32
            ", expected %#" PRIx32 ")",
            cp, first, (uint32_t)DD_CURLEASY_MAGIC_NUMBER);
        return DD_CURL_EASY_INVALID;
    }
    return DD_CURL_EASY_VALID;
}

#ifdef __linux__

static int _probe_vm_readv(uintptr_t addr, void *buf, size_t len);
static int _probe_pipe(uintptr_t addr, void *buf, size_t len);

int dd_curl_probe(uintptr_t addr, void *buf, size_t len)
{
    int res = _probe_vm_readv(addr, buf, len);
    if (res == ENOSYS || res == EPERM) {
        // process_vm_readv is unavailable or refused (seccomp filters of the
        // container runtime, which a nested privileged container cannot
        // lift). Any other errno comes from the memory at addr.
        return _probe_pipe(addr, buf, len);
    }
    return res;
}

static int _probe_vm_readv(uintptr_t addr, void *buf, size_t len)
{
    struct iovec local = {.iov_base = buf, .iov_len = len};
    struct iovec remote = {.iov_base = (void *)addr, .iov_len = len};
    ssize_t res = process_vm_readv(getpid(), &local, 1, &remote, 1, 0);
    if (res == (ssize_t)len) {
        return 0;
    }
    if (res >= 0) {
        // partial read: the range crosses into an unreadable page
        return EFAULT;
    }
    return errno != 0 ? errno : EIO;
}

// write(2) of the range into a pipe: the kernel copies it with a fault-safe
// copy_from_user and fails with EFAULT instead of faulting. Reading it back
// yields the bytes. len must fit the pipe buffer (always true for the few
// bytes we probe).
static int _probe_pipe(uintptr_t addr, void *buf, size_t len)
{
    int fds[2];
    if (pipe2(fds, O_CLOEXEC) != 0) {
        return errno != 0 ? errno : EIO;
    }
    int ret = 0;
    ssize_t res = write(fds[1], (const void *)addr, len);
    if (res < 0) {
        ret = errno != 0 ? errno : EIO;
    } else if (res != (ssize_t)len) {
        ret = EFAULT; // partial write: the range crosses into unreadable memory
    } else if (read(fds[0], buf, len) != (ssize_t)len) {
        ret = EIO;
    }
    (void)close(fds[0]);
    (void)close(fds[1]);
    return ret;
}

#else // !__linux__

int dd_curl_probe(uintptr_t addr, void *buf, size_t len)
{
    (void)addr, (void)buf, (void)len;
    return ENOSYS;
}

#endif
