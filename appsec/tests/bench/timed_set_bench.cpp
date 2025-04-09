#include <benchmark/benchmark.h>
#include <chrono>
#include <random>
#include <sampler.hpp>

constexpr std::size_t MAX_ITEMS = 4096;
constexpr std::size_t CAPACITY = 8192;
constexpr std::size_t NUM_TEST_NUMBERS = MAX_ITEMS;  // Number of random numbers to test

struct milliseconds_provider {
    std::uint32_t now()
    {
        return std::chrono::duration_cast<std::chrono::milliseconds>(
            std::chrono::steady_clock::now().time_since_epoch())
            .count() / 10;
    }
};

// global timed_set instance shared between all benchmarks
dds::timed_set<MAX_ITEMS, CAPACITY, milliseconds_provider, 30, dds::identity_hash>
    global_set;

// generate random 64-bit numbers for testing
std::vector<std::uint64_t> generateRandomNumbers(std::size_t count) {
    std::random_device rd;
    std::mt19937_64 gen(rd());
    std::uniform_int_distribution<std::uint64_t> dis(1, std::numeric_limits<std::uint64_t>::max());
    
    std::vector<std::uint64_t> numbers;
    numbers.reserve(count);
    for (std::size_t i = 0; i < count; ++i) {
        numbers.push_back(dis(gen));
    }
    
    return numbers;
}

std::vector<std::uint64_t> global_random_numbers;

class GlobalSetup {
public:
    GlobalSetup() {
        global_random_numbers = generateRandomNumbers(NUM_TEST_NUMBERS);
        
        auto acc = global_set.new_accessor();
        // Pre-populate the set with the numbers
        for (std::size_t i = 0; i < NUM_TEST_NUMBERS / 2; ++i) {
            acc.hit(global_random_numbers[i]);
        }
    }
};

GlobalSetup global_setup;

static void BM_TimedSetHit_SingleThread(benchmark::State& state) {
    std::size_t index = 0;
    auto acc = global_set.new_accessor();

    for (auto _ : state) {
        index = (index + 1) % NUM_TEST_NUMBERS;
        auto res = acc.hit(global_random_numbers[index]);
        benchmark::DoNotOptimize(res);
    }
}

static void BM_TimedSetHit_MultiThread(benchmark::State &state)
{
    std::size_t index = (state.thread_index() * 10) % NUM_TEST_NUMBERS;
    auto acc = global_set.new_accessor();

    for (auto _ : state) {
        index = (index + 1) % global_random_numbers.size();
        auto res = acc.hit(global_random_numbers[index]);
        benchmark::DoNotOptimize(res);
    }
}

BENCHMARK(BM_TimedSetHit_SingleThread);

BENCHMARK(BM_TimedSetHit_MultiThread)
    ->Threads(1)
    ->Threads(2)
    ->Threads(3)
    ->Threads(4)
    ->Threads(6)
    ->Threads(8)
    ->Threads(10)
    ->Threads(12);

BENCHMARK_MAIN();
