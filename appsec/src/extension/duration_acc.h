#pragma once

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
void dd_duration_req_finish(void); // call on rshutdown/user req shutdown

// RASP round-trip time
void dd_duration_rasp_ext_account(const struct timespec *start);
double dd_duration_rasp_ext_get_us(void);

// Non-RASP WAF round-trip time
void dd_duration_waf_ext_account(const struct timespec *start);
double dd_duration_waf_ext_get_us(void);
