// Outline-atomic helpers for aarch64
// These are needed when linking with Rust's libprofiler_builtins
// which was compiled with outline-atomics support.
// On older glibc/gcc versions, these functions are not provided.

#if defined(__aarch64__) && defined(__linux__)

#include <stdint.h>

// Compare-and-swap operation: atomically compare *ptr with expected,
// and if equal, replace with desired. Returns the previous value of *ptr.
uint64_t __aarch64_cas8_sync(uint64_t expected, uint64_t desired, uint64_t *ptr) {
    uint64_t prev;
    int tmp;
    __asm__ __volatile__(
        "1: ldaxr %0, [%3]\n"      // Load-acquire exclusive
        "   cmp %0, %1\n"           // Compare with expected
        "   b.ne 2f\n"              // Branch if not equal
        "   stlxr %w2, %4, [%3]\n"  // Store-release exclusive
        "   cbnz %w2, 1b\n"         // Retry if store failed
        "2:"
        : "=&r" (prev), "+r" (expected), "=&r" (tmp)
        : "r" (ptr), "r" (desired)
        : "cc", "memory");
    return prev;
}

// Atomic add operation: atomically add value to *ptr and return the previous value
uint64_t __aarch64_ldadd8_sync(uint64_t value, uint64_t *ptr) {
    uint64_t prev, tmp;
    __asm__ __volatile__(
        "1: ldaxr %0, [%2]\n"       // Load-acquire exclusive
        "   add %1, %0, %3\n"        // Add value
        "   stlxr %w1, %1, [%2]\n"   // Store-release exclusive
        "   cbnz %w1, 1b\n"          // Retry if store failed
        : "=&r" (prev), "=&r" (tmp)
        : "r" (ptr), "r" (value)
        : "cc", "memory");
    return prev;
}

#endif
