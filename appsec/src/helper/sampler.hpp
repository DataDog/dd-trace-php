// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <array>
#include <atomic>
#include <cassert>
#include <cmath>
#include <cstdint>
#include <queue>
#include <thread>

#include "rcu.h"

namespace dds {

struct seconds_provider {
    std::uint32_t now()
    {
        return std::chrono::duration_cast<std::chrono::seconds>(
            std::chrono::steady_clock::now().time_since_epoch())
            .count();
    }
};

struct identity_hash {
    std::uint64_t operator()(std::uint64_t number) const
    {
        return number | 0x8000000000000000UL; // 0 is not a valid key
    }
};

template <std::size_t MaxItems, std::size_t Capacity,
    typename TimeProvider = seconds_provider, std::uint32_t Threshold = 30,
    typename Hash = identity_hash>
class timed_set {
    // Capacity should be a power of two so that the compiler can optimize the
    // modulo operation to a shift
    static_assert(
        (Capacity & (Capacity - 1)) == 0, "Capacity must be a power of two");

    struct entry {
        struct alignas(8) edata {
            std::uint32_t last_accessed;
            std::uint32_t last_reported;

            bool operator==(const edata &other) const
            {
                return last_accessed == other.last_accessed &&
                       last_reported == other.last_reported;
            }
        };

        std::atomic<std::uint64_t> key;
        std::atomic<edata> data;

        static_assert(std::atomic<struct edata>::is_always_lock_free,
            "data is not lock-free");
    };

    struct table {
        std::array<entry, Capacity + 1> entries;
        std::atomic<std::size_t> size;
        [[no_unique_address]] Hash hash;

        // I tried an avx2 implementation of find_slot loading 4 numbers of once
        // with a gather operation (so without splitting entries into two
        // separate arrays -- one for keys and another for edata), but it was
        // slower than this at our desired load factor (0.5); only faster at
        // higher load factors.
        std::pair<entry &, bool /*exists*/> find_slot(std::uint64_t number)
        {
            const auto orig_idx = hash(number) % Capacity;
            auto idx = orig_idx;

            do [[likely]]
                {
                    entry &entry = entries[idx];
                    auto key = entry.key.load(std::memory_order_relaxed);
                    if (key == number) {
                        return {entry, true};
                    } else if (key == 0) {
                        return {entry, false};
                    }

                    idx = (idx + 1) % Capacity;
                }
            while (idx != orig_idx);
            // while(true) would recover a 2 ns penalty on my machine

            // should not happen... but if it does, return a fake entry
            return {entries[Capacity], true};
        }
    };

    bool hit(table &table, std::uint64_t number) noexcept
    {
        const std::uint32_t now = time_provider.now() - time_bias;
        const std::uint32_t report_threshold = now - Threshold;

    another_slot:
        auto [entry, exists] = table.find_slot(number);
        if (!exists) {
            auto old_size = table.size.fetch_add(1);

            if (old_size >= MaxItems) {
                bool expected = false;
                if (rebuild_in_progress.compare_exchange_strong(
                        expected, true, std::memory_order_relaxed)) {
                    std::thread([this, &table, report_threshold]() {
                        rebuild_table(table, report_threshold);
                    }).detach();
                }
            }
            if (old_size >= Capacity) {
                // no space to add anything
                table.size.fetch_add(-1);
                return false;
            }

            std::uint64_t exp_number{};
            if (!entry.key.compare_exchange_strong(
                    exp_number, number, std::memory_order_relaxed)) {
                table.size.fetch_add(-1);
                if (exp_number == number) {
                    // another thread inserted the same number
                    // presumably between find_slot() and the CAS only a very
                    // small amount of time passed
                    return false;
                }
                goto another_slot; // try and find another slot
            }

            typename entry::edata exp_data{};
            typename entry::edata desired_data{now, now};
            if (!entry.data.compare_exchange_strong(
                    exp_data, desired_data, std::memory_order_relaxed)) {
                // though we created the entry, another thread updated the data
                return false;
            }

            return true;
        }

        // else there is already something
        auto cur_data = entry.data.load(std::memory_order_relaxed);
        if (cur_data.last_reported <= report_threshold) {
            // potentially a hit
            typename entry::edata desired_data{now, now};

        retry:
            if (!entry.data.compare_exchange_strong(
                    cur_data, desired_data, std::memory_order_relaxed)) {
                // another thread just updated it
                // was it a hit?
                if (cur_data.last_accessed == cur_data.last_reported) {
                    // then this one should not be a hit
                    return false;
                }

                // the other thread did not register a hit
                // we retry if our idea of time is ahead
                if (cur_data.last_accessed < now) {
                    goto retry;
                }
                // otherwise we just return false
                return false;
            }

            return true;
        } else {
            // we just update the last accessed time
            typename entry::edata desired_data{now, cur_data.last_reported};

            // this would only be slightly innacurate:
            // entry.data.store(desired_data, std::memory_order_relaxed);

        retry2:
            if (cur_data.last_accessed >= now) {
                // we're behind the times
                return false;
            }

            if (!entry.data.compare_exchange_strong(
                    cur_data, desired_data, std::memory_order_relaxed)) {
                goto retry2;
            }

            return false;
        }
    }

public:
    class table_accessor : private rcu_reader_state<table> {
    public:
        table_accessor(timed_set &set)
            : rcu_reader_state<table>{set.rcu}, set{set}
        {}

        bool hit(std::uint64_t number) noexcept
        {
            auto guard{this->lock()};
            auto &table = *guard;
            return set.hit(table, number);
        }

        // for testing
        auto approx_size() noexcept
        {
            auto guard{this->lock()};
            return guard->size.load(std::memory_order_relaxed);
        }

    private:
        timed_set &set;
    };
    friend class table_accessor;

    ~timed_set()
    {
        // we must wait for the update thread
        bool expected = false;
        while (rebuild_in_progress.compare_exchange_strong(
            expected, true, std::memory_order_relaxed)) {
            std::this_thread::yield();
        }
    }

    table_accessor new_accessor() { return table_accessor{*this}; }

    std::unique_ptr<table_accessor> new_accessor_up()
    {
        return std::make_unique<table_accessor>(*this);
    }

    std::size_t approx_size(table &table) const { return table.size.load(); }

private:
    void rebuild_table(table &old_table, std::uint32_t report_threshold)
    {
        auto new_table = std::make_unique<table>();

        struct copiable_entry {
            std::uint64_t key;
            typename entry::edata data;
        };
        struct comp_entry {
            bool operator()(
                const copiable_entry &lhs, const copiable_entry &rhs)
            {
                return lhs.data.last_accessed < rhs.data.last_accessed;
            }
        };

        // most recent at the top
        std::vector<copiable_entry> backing_vector;
        backing_vector.reserve(Capacity);
        std::priority_queue<copiable_entry, std::vector<copiable_entry>,
            comp_entry>
            heap{comp_entry{}, std::move(backing_vector)};
        for (std::size_t slot = 0; slot < Capacity; ++slot) {
            auto &entry = old_table.entries[slot];
            auto key = entry.key.load(std::memory_order_relaxed);
            auto data = entry.data.load(std::memory_order_relaxed);
            if (key != 0 && data.last_reported >= report_threshold) {
                heap.push(copiable_entry{key, data});
            }
        }

        // insert up to MaxItems * 2 / 3
        std::size_t count = 0;
        while (!heap.empty() && count < MaxItems * 2 / 3) {
            const auto &entry = heap.top();
            auto [new_entry, _] = new_table->find_slot(entry.key);
            new_entry.key.store(entry.key, std::memory_order_relaxed);
            new_entry.data.store(entry.data, std::memory_order_relaxed);
            heap.pop();
            count++;
        }
        new_table->size.store(count, std::memory_order_relaxed);

        // swap tables
        rcu.update(std::move(new_table));
        rebuild_in_progress.store(false, std::memory_order_relaxed);
    }

    rcu_manager<table> rcu{std::make_unique<table>()};
    std::atomic<bool> rebuild_in_progress{false};
    [[no_unique_address]] TimeProvider time_provider;
    // avoid problems with wrap arounds
    std::uint32_t time_bias{time_provider.now() - Threshold};
};
} // namespace dds
