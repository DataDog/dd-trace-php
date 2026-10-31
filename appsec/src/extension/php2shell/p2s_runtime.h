// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The request level (PHP 7.1+): the whole request is armed from appsec's RINIT
// to its RSHUTDOWN, the zend_interrupt_function wrapper raises a deferred
// error, and, on PHP 7.1-8.3, the gc_mem_caches() wrapper lets zend_mm_gc()
// free memory under our handlers. On PHP 7.0 only dd_p2s_runtime_rshutdown()
// and the testing function do anything.

#ifndef DD_P2S_RUNTIME_H
#define DD_P2S_RUNTIME_H

// Capture zend_interrupt_function and the handler of gc_mem_caches() and put
// our wrappers in them.
void dd_p2s_runtime_install(void);
// Restore the slots that still hold our wrappers, and forget the captured
// handlers.
void dd_p2s_runtime_uninstall(void);
// Arm the request, if the mitigation is active (appsec's RINIT).
void dd_p2s_runtime_rinit(void);
// Follow the WAF decision at each HTTP request of a worker (FrankenPHP).
void dd_p2s_runtime_user_req_begin(void);
// End the request level (appsec's RSHUTDOWN). On every version.
void dd_p2s_runtime_rshutdown(void);
// Log once per process if the slot was displaced.
void dd_p2s_runtime_check_interrupt(void);
void dd_p2s_runtime_check_gc_mem_caches(void);
// The MINFO hook rows.
void dd_p2s_runtime_minfo_interrupt(void);
void dd_p2s_runtime_minfo_gc_mem_caches(void);
#ifdef TESTING
void dd_p2s_runtime_register_testing(void);
#endif

#endif // DD_P2S_RUNTIME_H
