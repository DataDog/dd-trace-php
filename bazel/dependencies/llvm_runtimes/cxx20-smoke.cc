#include <atomic>
#include <concepts>
#include <stdexcept>
#include <thread>
#include <type_traits>
#include <vector>

template <std::integral T>
constexpr T twice(T value) { return value + value; }

static_assert(twice(21) == 42);
static_assert(std::is_same_v<std::vector<int>::value_type, int>);

std::atomic<int> tls_destructors{0};

struct ThreadLocalGuard {
    ~ThreadLocalGuard() { tls_destructors.fetch_add(1, std::memory_order_relaxed); }
};

thread_local ThreadLocalGuard thread_guard;

int main() {
    std::vector<int> values{20, 22};
    if (values[0] + values[1] != twice(21)) return 1;

    bool caught = false;
    try {
        throw std::runtime_error("runtime smoke exception");
    } catch (const std::runtime_error&) {
        caught = true;
    }
    if (!caught) return 2;

    std::thread worker([] { (void)&thread_guard; });
    worker.join();
    return tls_destructors.load(std::memory_order_relaxed) == 1 ? 0 : 3;
}
