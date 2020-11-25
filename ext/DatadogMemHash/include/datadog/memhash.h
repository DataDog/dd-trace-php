#ifndef DATADOG_MEMHASH_H
#define DATADOG_MEMHASH_H

#include <stdbool.h>
#include <stdint.h>

uint64_t datadog_memhash(uint64_t size, const char str[]);
uint64_t datadog_cantor_hash(uint64_t x, uint64_t y);

#endif  // DATADOG_MEMHASH_H
