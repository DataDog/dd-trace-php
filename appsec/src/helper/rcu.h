#pragma once

#include <algorithm>
#include <atomic>
#include <cassert>
#include <memory>
#include <mutex>
#include <vector>
#include <spdlog/spdlog.h>

/*
 * A simple RCU implementation, using a global generation counter and per-reader
 * state consisting of a generation.
 * Each thread need no more than one rcu_reader_state object. Reentrancy is not
 * supported.
 *
 * The implementation is not the fastest; it uses full fences on the read side.
 *
 * The protocol looks like this:
 *
 * Readers:
 * 1.1 load global generation (acquire)
 * 1.2 store loaded global generation in local state (relaxed)
 * 1.3 full fence
 * 1.4 load data (acquire)
 *
 * Writer (there should be only one at any given time):
 * 2.1 store new data (release)
 * 2.2 increment global counter (release)
 * 2.3 full fence
 * 2.4 read the local generation state of all readers (relaxed)
 * 2.5 garbage collect all the data older than the oldest active generation
 */

namespace dds {
template <typename T> class rcu_manager;
template <typename T> class rcu_reader_state;
template <typename T> class rcu_read_guard;

template <typename T> class rcu_garbage_collector {
    std::mutex mutex;
    std::vector<std::unique_ptr<T>> garbage;
    std::uint64_t last_collected_gen{0};

public:
    void add_garbage(std::unique_ptr<T> ptr)
    {
        std::lock_guard<std::mutex> lock{mutex};
        if (ptr) {
            garbage.push_back(std::move(ptr));
        }
    }

    void collect(std::uint64_t oldest_gen, std::uint64_t current_gen)
    {
        std::lock_guard<std::mutex> lock{mutex};

        if (oldest_gen == std::numeric_limits<std::uint64_t>::max()) {
            // no active threads (or, if they got active since our load, it
            // must have been with the live data), all can go
            garbage.clear();
            last_collected_gen = current_gen - 1;
            return;
        }

        // erase entries older than the oldest active generation
        // the vector is sorted by generation, and contains generations
        // from last_collected_gen + 1 to current_gen - 1
        std::size_t del_amount = oldest_gen - last_collected_gen - 1;
        if (del_amount > 0 && del_amount <= garbage.size()) {
            garbage.erase(garbage.begin(), garbage.begin() + del_amount);
            last_collected_gen = oldest_gen - 1;
        }
    }
};

template <typename T> class rcu_reader_state {
    std::atomic<std::uint64_t> generation{0};
    rcu_manager<T> &manager;

    friend class rcu_manager<T>;
    friend class rcu_read_guard<T>;

public:
    rcu_reader_state(rcu_manager<T> &man) : manager{man}
    {
        manager.register_thread_state(*this);
    }

    ~rcu_reader_state() { manager.unregister_thread_state(*this); }

    rcu_read_guard<T> lock() { return rcu_read_guard<T>(manager, *this); }
};

// RAII-style read lock
template <typename T> class rcu_read_guard {
private:
    rcu_reader_state<T> &state;
    T *data_ptr;

    friend class rcu_reader_state<T>;

public:
    rcu_read_guard(rcu_manager<T> &man, rcu_reader_state<T> &thread_state)
        : state{thread_state}
    {
        // Mark this thread as active with current generation
        auto global_gen = man.load_current_generation_acq();
        state.generation.store(global_gen, std::memory_order_relaxed);

        // we need a full fence here, otherwise the writer may see our local
        // generation too late (after we loaded the data)
        // Put another way, the following load cannot be reordered after
        // the previous store
        std::atomic_thread_fence(std::memory_order_seq_cst);

        // this loads at least the memory for the fetched generation
        // (maybe more recent) due to the acquire (data is written before
        // the release in the writer)
        // If we do see more recent data that the generation would suggest,
        // we see it fully initialized because of acquire-release on data
        data_ptr = man.get_data_acq();
    }

    ~rcu_read_guard()
    {
        // ensure all reads complete before releasing generation
        state.generation.store(0, std::memory_order_release);
    }

    T *get() const { return data_ptr; }

    T *operator->() const { return data_ptr; }

    T &operator*() const { return *data_ptr; }
};

template <typename T> class rcu_manager {
private:
    std::atomic<std::uint64_t> current_gen{1};
    std::atomic<T *> data_ptr;

    std::mutex thread_states_mutex;
    std::vector<rcu_reader_state<T> *> thread_states;

    rcu_garbage_collector<T> gc;

    friend class rcu_reader_state<T>;
    friend class rcu_read_guard<T>;

    void register_thread_state(rcu_reader_state<T> &state)
    {
        std::lock_guard<std::mutex> lock{thread_states_mutex};
        SPDLOG_TRACE(
            "Registering thread state {} on manager {} (current size: {})",
            static_cast<void *>(&state), static_cast<void *>(this),
            thread_states.size());
        thread_states.push_back(&state);
    }

    void unregister_thread_state(rcu_reader_state<T> &state)
    {
        std::lock_guard<std::mutex> lock{thread_states_mutex};
        SPDLOG_TRACE(
            "Unregistering thread state {} on manager {} (current size: {})",
            (void *)&state, (void *)this, thread_states.size());
        auto it = std::find(thread_states.begin(), thread_states.end(), &state);
        assert(it != thread_states.end());
        thread_states.erase(it);
    }

    std::uint64_t find_oldest_active_generation()
    {
        std::lock_guard<std::mutex> lock{thread_states_mutex};
        std::uint64_t oldest_gen = std::numeric_limits<std::uint64_t>::max();

        // the following loads can't move behind the stores in update()
        std::atomic_thread_fence(std::memory_order_seq_cst);

        for (auto *state : thread_states) {
            auto state_gen = state->generation.load(std::memory_order_relaxed);
            if (state_gen != 0 && state_gen < oldest_gen) {
                oldest_gen = state_gen;
            }
        }

        return oldest_gen;
    }

public:
    rcu_manager(std::unique_ptr<T> initial_data)
        : data_ptr{initial_data.release()}
    {}

    ~rcu_manager() {
        auto *data = data_ptr.exchange(nullptr, std::memory_order_release);
        if (data) {
            delete data;
        }
    }

    std::unique_ptr<rcu_reader_state<T>> create_thread_state()
    {
        return std::make_unique<rcu_reader_state<T>>(*this);
    }

    void update(std::unique_ptr<T> new_data)
    {
        T *old_data =
            data_ptr.exchange(new_data.release(), std::memory_order_release);
        // following release ensures that these two statements can't be
        // reordered (i.e. you can see data ahead of the read generation, but
        /// never behind, as long as you acquire the generation first)
        current_gen.fetch_add(1, std::memory_order_release);

        gc.add_garbage(std::unique_ptr<T>{old_data});

        collect_garbage();
    }

    // for internal use by rcu_read_guard
    std::uint64_t load_current_generation_acq() const
    {
        return current_gen.load(std::memory_order_acquire);
    }

    // for internal use by rcu_read_guard
    T *get_data_acq() const
    {
        return data_ptr.load(std::memory_order_acquire);
    }

    void collect_garbage()
    {
        auto gen = current_gen.load(std::memory_order_relaxed);
        auto oldest_gen = find_oldest_active_generation();
        gc.collect(oldest_gen, gen);
    }
};
} // namespace dds
