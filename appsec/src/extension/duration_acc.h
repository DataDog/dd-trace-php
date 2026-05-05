#pragma once

// php_compat.h must precede attributes.h: attributes.h defines `nonnull` as _Nonnull
// (empty on GCC), which breaks PHP 8.4+'s __has_attribute(nonnull) in zend_portability.h.
#include "php_compat.h"  // NOLINT(llvm-include-order)
#include "attributes.h"
#include <time.h>

static inline struct timespec dd_monotime_start(void)
{
    struct timespec ts;
    (void)clock_gettime(CLOCK_MONOTONIC_RAW, &ts);
    return ts;
}

void dd_duration_startup(void);
void dd_duration_shutdown(void);
void dd_duration_reset_globals(void); // call on rinit/user req begin
void dd_duration_flush_metrics(zend_object *nonnull span); // call on rshutdown/user req shutdown

// RASP round-trip time
void dd_duration_rasp_ext_account(const struct timespec *nonnull start);

// Non-RASP WAF round-trip time
void dd_duration_waf_ext_account(const struct timespec *nonnull start);
