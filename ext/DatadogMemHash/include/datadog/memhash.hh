#ifndef DATADOG_MEMHASH_HH
#define DATADOG_MEMHASH_HH

#include <cstdint>

namespace datadog {

namespace {

constexpr uint64_t rotl64(uint64_t x, uint8_t r) { return (x << r) | (x >> (64u - r)); }

//-----------------------------------------------------------------------------
// Finalization mix - force all bits of a hash block to avalanche

constexpr uint64_t fmix64(uint64_t k) noexcept {
    k ^= k >> 33u;
    k *= UINT64_C(0xff51afd7ed558ccd);
    k ^= k >> 33u;
    k *= UINT64_C(0xc4ceb9fe1a85ec53);
    k ^= k >> 33u;

    return k;
}

//-----------------------------------------------------------------------------

void MurmurHash3_x64_128(const void *key, const uint64_t len, const uint32_t seed, void *out) noexcept {
    auto *data = (const char *)key;
    const uint64_t nblocks = len / 16u;

    uint64_t h1 = seed;
    uint64_t h2 = seed;

    const uint64_t c1 = UINT64_C(0x87c37b91114253d5);
    const uint64_t c2 = UINT64_C(0x4cf5ad432745937f);

    //----------
    // body

    auto *blocks = (const uint64_t *)(key);

    for (uint64_t i = 0; i < nblocks; i++) {
        uint64_t k1 = blocks[(i * 2 + 0)];
        uint64_t k2 = blocks[(i * 2 + 1)];

        k1 *= c1;
        k1 = rotl64(k1, 31);
        k1 *= c2;
        h1 ^= k1;

        h1 = rotl64(h1, 27);
        h1 += h2;
        h1 = h1 * 5 + 0x52dce729;

        k2 *= c2;
        k2 = rotl64(k2, 33);
        k2 *= c1;
        h2 ^= k2;

        h2 = rotl64(h2, 31);
        h2 += h1;
        h2 = h2 * 5 + 0x38495ab5;
    }

    //----------
    // tail

    auto *tail = (const uint8_t *)(data + nblocks * 16);

    uint64_t k1 = 0;
    uint64_t k2 = 0;

    switch (len & 15u) {
        case 15:
            k2 ^= ((uint64_t)tail[14]) << 48u;
        case 14:
            k2 ^= ((uint64_t)tail[13]) << 40u;
        case 13:
            k2 ^= ((uint64_t)tail[12]) << 32u;
        case 12:
            k2 ^= ((uint64_t)tail[11]) << 24u;
        case 11:
            k2 ^= ((uint64_t)tail[10]) << 16u;
        case 10:
            k2 ^= ((uint64_t)tail[9]) << 8u;
        case 9:
            k2 ^= ((uint64_t)tail[8]) << 0u;
            k2 *= c2;
            k2 = rotl64(k2, 33);
            k2 *= c1;
            h2 ^= k2;

        case 8:
            k1 ^= ((uint64_t)tail[7]) << 56u;
        case 7:
            k1 ^= ((uint64_t)tail[6]) << 48u;
        case 6:
            k1 ^= ((uint64_t)tail[5]) << 40u;
        case 5:
            k1 ^= ((uint64_t)tail[4]) << 32u;
        case 4:
            k1 ^= ((uint64_t)tail[3]) << 24u;
        case 3:
            k1 ^= ((uint64_t)tail[2]) << 16u;
        case 2:
            k1 ^= ((uint64_t)tail[1]) << 8u;
        case 1:
            k1 ^= ((uint64_t)tail[0]) << 0u;
            k1 *= c1;
            k1 = rotl64(k1, 31);
            k1 *= c2;
            h1 ^= k1;
    }

    //----------
    // finalization

    h1 ^= len;
    h2 ^= len;

    h1 += h2;
    h2 += h1;

    h1 = fmix64(h1);
    h2 = fmix64(h2);

    h1 += h2;
    h2 += h1;

    ((uint64_t *)out)[0] = h1;
    ((uint64_t *)out)[1] = h2;
}

template <uint64_t N>
struct log2 {
    constexpr static uint64_t value = 1 + log2<(N >> 1u)>::value;
};

template <>
struct log2<1> {
    constexpr static uint64_t value = 0;
};

}  // namespace

inline uint64_t memhash(uint64_t size, const char str[]) noexcept {
    uint64_t buffer[2] = {0, 0};
    MurmurHash3_x64_128(str, size, 0, buffer);
    return buffer[0];
}

/**
 * Hashes two integers using the cantor pairing function:
 *   https://en.wikipedia.org/wiki/Pairing_function#Cantor_pairing_function
 *
 * This hash is perfect until overflow occurs, and then there are repeating
 * patterns due to the wrapped overflow. The hashes f(1, 2) and f(2, 1) are
 * different.
 *
 * This makes it a good hash for two ints that are offsets into an array.
 */
constexpr uint64_t cantor_hash(uint64_t x, uint64_t y) noexcept {
    return (x + y) * (x + y + 1) / 2 + y;
}

}  // namespace datadog

#endif  // DATADOG_MEMHASH_HH
