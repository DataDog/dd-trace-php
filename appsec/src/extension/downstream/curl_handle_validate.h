// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// PHP-agnostic validation of the values involved in getting a libcurl easy
// handle (CURL *) out of a PHP curl handle without ext/curl's headers:
//  - PHP 8: the php_curl struct is found by subtracting the object handlers'
//    `offset` from the zend_object; the offset must be sane;
//  - the CURL * itself (the first member of php_curl) must look like a
//    user-space pointer to a live libcurl easy handle.
//
// These functions keep no state and never dereference the pointer they
// validate: memory at the candidate address is only read through a
// fault-safe probe, a single process_vm_readv(2) on the own process. There is
// no range check of our own (the kernel refuses non-canonical and kernel-half
// addresses with EFAULT) and no check of pointer tags, so that MTE and HWASan
// systems work.

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

// CURLEASY_MAGIC_NUMBER (lib/urldata.h, later lib/api.h); the first member of
// struct Curl_easy since libcurl 7.75.0. Older versions have it at the end of
// the struct, at an offset that depends on version and build options.
#define DD_CURLEASY_MAGIC_NUMBER 0xc0dedbadU
#define DD_CURL_MAGIC_AT_OFFSET_0_SINCE 0x074b00 // 7.75.0

// Upper bound (exclusive) for zend_object_handlers.offset of CurlHandle,
// i.e. XtOffsetOf(php_curl, std). The real value is a few hundred bytes.
#define DD_CURL_MAX_HANDLERS_OFFSET 4096

// The outcome of dd_curl_validate_easy_ptr()
typedef enum {
    DD_CURL_EASY_VALID = 0,
    // the pointer is not what we assume a CURL * to be
    DD_CURL_EASY_INVALID,
    // it could not be checked: the probe is refused (e.g. a seccomp filter
    // or an old kernel), which says nothing about the pointer
    DD_CURL_EASY_UNVERIFIED,
} dd_curl_easy_check;

// Reads `len` bytes at `addr` into `buf` without faulting if the memory is
// not readable. Returns 0 on success, or an errno value: EFAULT if the memory
// is not readable, anything else if the probe itself is unavailable.
typedef int (*dd_curl_probe_fn)(uintptr_t addr, void *buf, size_t len);

// process_vm_readv(2) on the own process. Where that syscall is unavailable
// or refused (ENOSYS/EPERM: old kernels, Docker's pre-4.8 check, a seccomp
// filter of the container runtime) the range is written to a pipe instead,
// which the kernel does fault-safely too. Only if that fails for another
// reason than EFAULT as well, the probe is unavailable. Under a seccomp filter
// whose action for these syscalls is to kill the process, calling it kills the
// process (a documented limit).
int dd_curl_probe(uintptr_t addr, void *buf, size_t len);

// Validates zend_object_handlers.offset for CurlHandle. NULL if valid,
// otherwise a static string with the reason.
const char *dd_curl_validate_handlers_offset(long offset);

// Validates a non-NULL CURL *, reading memory only through `probe`.
// `libcurl_version_num` (LIBCURL_VERSION_NUM of the libcurl ext/curl is bound
// to) decides whether the magic number is checked. Unless valid, the reason
// is in `reason` (of size `reason_size`). For DD_CURL_EASY_UNVERIFIED, the
// reason ends in a colon: the caller appends the description of
// `*probe_errno`, the errno of the probe (set only in that case).
dd_curl_easy_check dd_curl_validate_easy_ptr(uintptr_t cp,
    dd_curl_probe_fn probe, unsigned int libcurl_version_num, char *reason,
    size_t reason_size, int *probe_errno);
