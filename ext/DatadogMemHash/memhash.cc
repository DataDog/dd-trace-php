extern "C" {
#include <datadog/memhash.h>
}

#include <datadog/memhash.hh>

uint64_t datadog_memhash(uint64_t size, const char str[]) { return datadog::memhash(size, str); }
uint64_t datadog_cantor_hash(uint64_t x, uint64_t y) { return datadog::cantor_hash(x, y); }
