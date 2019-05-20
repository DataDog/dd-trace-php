#ifndef DD_CURLING_H
#define DD_CURLING_H
#include <stdint.h>

// uint32_t store_data(const char *blah);
// uint32_t flush_data();

uint32_t dd_trace_flush_data(const char *data, size_t size);
uint32_t dd_trace_coms_initialize();
uint32_t dd_trace_coms_consumer();

#endif //DD_CURLING_H
