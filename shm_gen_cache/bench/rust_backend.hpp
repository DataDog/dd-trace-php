// The Rust port behind the C++ library surface that sgc_bench.cpp uses.
//
// sgc_bench.cpp is a copy of the C++ project's bench with only its "library
// binding" block switched on SGC_BENCH_BACKEND_RUST. This header reproduces,
// on top of the C API in ../shm_gen_cache.h, exactly the subset of the C++
// API that the harness touches:
//
//   cache::initialize(u8*, usize) -> expected<cache*, errors>
//   cache::register_participant() -> expected<lock, errors>
//   lock::insert(hash, key, value) -> expected<void, errors>
//   lock::lookup(hash, key, output_buffer&) -> expected<optional<span>, errors>
//   ~lock()                       -> unregister
//   output_buffer<N>              (8-aligned, whole words)
//
// Everything is inline, so without cross-language LTO a lookup costs one
// direct call into the staticlib (plus one for an insert) on top of what the
// library does; with SGC_BENCH_XLTO the calls are inlined at link time.
//
// The configuration is passed at run time (ddog_sgc_Config), which is the
// production shape: the Rust side resolves it once at cache creation and
// reads the derived parameters through the participant handle.
#pragma once

#include <cstddef>
#include <cstdint>
#include <expected>
#include <optional>
#include <span>
#include <utility>

#include "shm_gen_cache.h"

namespace rsb {

using u8 = std::uint8_t;
using u16 = std::uint16_t;
using u32 = std::uint32_t;
using u64 = std::uint64_t;
using usize = std::size_t;

// Status codes match the C++ sgc::errors numbering (2..15), so error prints
// read the same for both backends.
using errors = ddog_sgc_Status;

// The C++ sgc::output_buffer, reduced to what the harness needs.
template <usize Capacity>
class alignas(u64) output_buffer {
public:
    static constexpr usize word_count = (Capacity + 7) / 8;
    [[nodiscard]] u8* data() noexcept {
        return reinterpret_cast<u8*>(storage);
    }
    [[nodiscard]] u64* words() noexcept {
        return storage;
    }
    [[nodiscard]] static constexpr usize size() noexcept {
        return Capacity;
    }

private:
    u64 storage[word_count]{};
};

class lock {
public:
    lock() = default;
    explicit lock(ddog_sgc_Participant* p) noexcept : p_{p} {}
    lock(const lock&) = delete;
    lock& operator=(const lock&) = delete;
    lock(lock&& o) noexcept : p_{std::exchange(o.p_, nullptr)} {}
    lock& operator=(lock&& o) noexcept {
        if (this != &o) {
            reset();
            p_ = std::exchange(o.p_, nullptr);
        }
        return *this;
    }
    ~lock() {
        reset();
    }

    std::expected<void, errors> insert(u64 hash, std::span<const u8> key,
                                       std::span<const u8> value) noexcept {
        const ddog_sgc_Status rc = ddog_sgc_insert(
            p_, hash, key.data(), key.size(), value.data(), value.size());
        if (rc != DDOG_SGC_STATUS_OK) [[unlikely]] {
            return std::unexpected{rc};
        }
        return {};
    }

    template <usize Capacity>
    std::expected<std::optional<std::span<u8>>, errors> lookup(
        u64 hash, std::span<const u8> key,
        output_buffer<Capacity>& out) noexcept {
        usize len;
        const ddog_sgc_Status rc =
            ddog_sgc_lookup(p_, hash, key.data(), key.size(), out.words(),
                            Capacity, &len);
        if (rc == DDOG_SGC_STATUS_OK) [[likely]] {
            return std::span<u8>{out.data(), len};
        }
        if (rc == DDOG_SGC_STATUS_MISS) {
            return std::nullopt;
        }
        return std::unexpected{rc};
    }

private:
    void reset() noexcept {
        if (p_ != nullptr) {
            ddog_sgc_participant_unregister(p_);
            p_ = nullptr;
        }
    }

    ddog_sgc_Participant* p_ = nullptr;
};

// One cache configuration, fixed per binary like the C++ template argument,
// but handed to the library at run time.
template <const ddog_sgc_Config& Config>
class cache {
public:
    using lock_type = lock;

    // Bytes of the shared mapping (C++: sizeof(cache<cfg>)).
    static usize mapping_size() noexcept {
        return ddog_sgc_cache_mapping_size(&Config);
    }

    // The mapping stays owned by the caller, as with the C++ initialize().
    static std::expected<cache*, errors> initialize(u8* mapping,
                                                    usize len) noexcept {
        ddog_sgc_Cache* c = nullptr;
        const ddog_sgc_Status rc =
            ddog_sgc_cache_init_in(mapping, len, &Config, &c);
        if (rc != DDOG_SGC_STATUS_OK) {
            return std::unexpected{rc};
        }
        return new cache{c};
    }

    // Frees the handle (not the mapping, which the caller unmaps). Every
    // participant must be unregistered by now.
    static void destroy(cache* c) noexcept {
        if (c != nullptr) {
            ddog_sgc_cache_free(c->c_);
            delete c;
        }
    }

    std::expected<lock, errors> register_participant() noexcept {
        ddog_sgc_Participant* p = nullptr;
        const ddog_sgc_Status rc = ddog_sgc_participant_register(c_, &p);
        if (rc != DDOG_SGC_STATUS_OK) {
            return std::unexpected{rc};
        }
        return lock{p};
    }

private:
    explicit cache(ddog_sgc_Cache* c) noexcept : c_{c} {}
    ddog_sgc_Cache* c_;
};

}  // namespace rsb
