#ifndef DDPROF_MEMHASH_HH
#define DDPROF_MEMHASH_HH

#include <cassert>
#include <cstdint>

namespace ddprof {

namespace {

/* The code in this inline namespace is a touch-up of Stephan Brumme's XXHash64:
 *     https://create.stephan-brumme.com/xxhash/xxhash64.h
 * Copyright (c) 2016 Stephan Brumme. All rights reserved.
 * See http://create.stephan-brumme.com/disclaimer.html
 * It is MIT licensed.
 *
 * Some small changes have been made by Datadog employees, such as to mark it
 * noexcept, eliminate warnings from clang-tidy, etc.
 */

class XXHash64 {
    public:
    inline XXHash64() noexcept : XXHash64(0) {}

    /// create new XXHash (64 bit)
    /** @param seed your seed value, even zero is a valid seed **/
    inline explicit XXHash64(uint64_t seed) noexcept :
        state{seed + Prime1 + Prime2, seed + Prime2, seed, seed - Prime1},
        buffer{'\0'},
        bufferSize{0},
        totalLength{0} {}

    /// add a chunk of bytes
    /** @param  input  pointer to a continuous block of data
        @param  length number of bytes
        @return false if parameters are invalid / zero **/
    bool add(const char* input, uint64_t length) noexcept {
        // no data ?
        if (!input || length == 0) return false;

        totalLength += length;
        // byte-wise access
        auto* data = (const char*)input;

        // unprocessed old data plus new data still fit in temporary buffer ?
        if (bufferSize + length < MaxBufferSize) {
            // just add new data
            while (length-- > 0) buffer[bufferSize++] = *data++;
            return true;
        }

        // point beyond last byte
        const char* stop = data + length;
        const char* stopBlock = stop - MaxBufferSize;

        // some data left from previous update ?
        if (bufferSize > 0) {
            // make sure temporary buffer is full (16 bytes)
            while (bufferSize < MaxBufferSize) buffer[bufferSize++] = *data++;

            // process these 32 bytes (4x8)
            process(buffer, state[0], state[1], state[2], state[3]);
        }

        // copying state to local variables helps optimizer A LOT
        uint64_t s0 = state[0], s1 = state[1], s2 = state[2], s3 = state[3];
        // 32 bytes at once
        while (data <= stopBlock) {
            // local variables s0..s3 instead of state[0]..state[3] are much
            // faster
            process(data, s0, s1, s2, s3);
            data += 32;
        }
        // copy back
        state[0] = s0;
        state[1] = s1;
        state[2] = s2;
        state[3] = s3;

        // copy remainder to temporary buffer
        bufferSize = stop - data;
        for (unsigned int i = 0; i < bufferSize; i++) buffer[i] = data[i];

        // done
        return true;
    }

    /// get current hash
    /** @return 64 bit XXHash **/
    inline uint64_t hash() const noexcept {
        // fold 256 bit state into one single 64 bit value
        uint64_t result{0};
        if (totalLength >= MaxBufferSize) {
            result = rotateLeft(state[0], 1) + rotateLeft(state[1], 7) +
                     rotateLeft(state[2], 12) + rotateLeft(state[3], 18);
            result = (result ^ processSingle(0, state[0])) * Prime1 + Prime4;
            result = (result ^ processSingle(0, state[1])) * Prime1 + Prime4;
            result = (result ^ processSingle(0, state[2])) * Prime1 + Prime4;
            result = (result ^ processSingle(0, state[3])) * Prime1 + Prime4;
        } else {
            // internal state wasn't set in add(), therefore original seed is
            // still stored in state2
            result = state[2] + Prime5;
        }

        result += totalLength;

        // process remaining bytes in temporary buffer
        const unsigned char* data = buffer;
        // point beyond last byte
        const unsigned char* stop = data + bufferSize;

        // at least 8 bytes left ? => eat 8 bytes per step
        for (; data + 8 <= stop; data += 8)
            result =
                rotateLeft(result ^ processSingle(0, *(uint64_t*)data), 27) *
                    Prime1 +
                Prime4;

        // 4 bytes left ? => eat those
        if (data + 4 <= stop) {
            result =
                rotateLeft(result ^ (*(uint32_t*)data) * Prime1, 23) * Prime2 +
                Prime3;
            data += 4;
        }

        // take care of remaining 0..3 bytes, eat 1 byte per step
        while (data != stop)
            result = rotateLeft(result ^ (*data++) * Prime5, 11) * Prime1;

        // mix bits
        result ^= result >> 33;
        result *= Prime2;
        result ^= result >> 29;
        result *= Prime3;
        result ^= result >> 32;
        return result;
    }

    /// combine constructor, add() and hash() in one static function (C style)
    /** @param  input  pointer to a continuous block of data
        @param  length number of bytes
        @param  seed your seed value, e.g. zero is a valid seed
        @return 64 bit XXHash **/
    static uint64_t hash(const char* input, uint64_t length,
                         uint64_t seed) noexcept {
        XXHash64 hasher(seed);
        hasher.add(input, length);
        return hasher.hash();
    }

    private:
    /// magic constants :-)
    static const uint64_t Prime1 = UINT64_C(11400714785074694791);
    static const uint64_t Prime2 = UINT64_C(14029467366897019727);
    static const uint64_t Prime3 = UINT64_C(1609587929392839161);
    static const uint64_t Prime4 = UINT64_C(9650029242287828579);
    static const uint64_t Prime5 = UINT64_C(2870177450012600261);

    /// temporarily store up to 31 bytes between multiple add() calls
    static const uint64_t MaxBufferSize = 31 + 1;

    uint64_t state[4];
    unsigned char buffer[MaxBufferSize];
    unsigned int bufferSize;
    uint64_t totalLength;

    /// rotate bits, should compile to a single CPU instruction (ROL)
    static inline uint64_t rotateLeft(uint64_t x, unsigned char bits) noexcept {
        return (x << bits) | (x >> (64 - bits));
    }

    /// process a single 64 bit value
    static inline uint64_t processSingle(uint64_t previous,
                                         uint64_t input) noexcept {
        return rotateLeft(previous + input * Prime2, 31) * Prime1;
    }

    /// process a block of 4x4 bytes, this is the main part of the XXHash32
    /// algorithm
    static void process(const void* data, uint64_t& state0, uint64_t& state1,
                        uint64_t& state2, uint64_t& state3) noexcept {
        const auto* block = (const uint64_t*)data;
        state0 = processSingle(state0, block[0]);
        state1 = processSingle(state1, block[1]);
        state2 = processSingle(state2, block[2]);
        state3 = processSingle(state3, block[3]);
    }
};

}  // namespace

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
inline uint64_t cantor_hash(uint64_t x, uint64_t y) noexcept {
    return (x + y) * (x + y + 1) / 2 + y;
}

inline uint64_t cantor_hash(uint64_t x, uint64_t y, uint64_t z) noexcept {
    return cantor_hash(cantor_hash(x, y), z);
}

inline uint64_t inthash(uint64_t len, uint64_t values[]) noexcept {
    if (len == 0) return 0;
    if (len == 1) return values[0];

    uint64_t result = cantor_hash(values[0], values[1]);
    for (uint64_t i = 2; i < len; ++i) {
        result = cantor_hash(result, values[i]);
    }
    return result;
}

class memhash {
    // todo: seed?
    XXHash64 impl;

    public:
    inline memhash() noexcept : impl{0} {}
    inline explicit memhash(uint64_t seed) noexcept : impl{seed} {}

    void add(uint64_t len, const char mem[]) noexcept { impl.add(mem, len); }

    inline uint64_t hash() const noexcept { return impl.hash(); }

    static inline uint64_t hash(uint64_t seed, uint64_t len,
                                const char mem[]) noexcept {
        return XXHash64::hash(mem, len, seed);
    }

    static inline uint64_t hash(uint64_t x, uint64_t y) noexcept {
        return cantor_hash(x, y);
    }

    static inline uint64_t hash(uint64_t x, uint64_t y, uint64_t z) noexcept {
        return cantor_hash(x, y, z);
    }
};

}  // namespace ddprof

#endif  // DDPROF_MEMHASH_HH
