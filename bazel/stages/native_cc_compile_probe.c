#include <stddef.h>
#include <stdint.h>

uintptr_t dd_native_cc_compile_probe(const uint8_t *bytes, size_t length) {
    uintptr_t value = 0;
    for (size_t index = 0; index < length; ++index) {
        value = (value * 33u) ^ bytes[index];
    }
    return value;
}
