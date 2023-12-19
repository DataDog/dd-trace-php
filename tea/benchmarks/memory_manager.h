#ifndef MEMORY_MANAGER_H
#define MEMORY_MANAGER_H

#include <memory>
#include <benchmark/benchmark.h>

// See https://github.com/google/benchmark/blob/c2de5261302fa307ebe06b24c0fc30653bed5e17/include/benchmark/benchmark.h#L375
class CustomMemoryManager : public benchmark::MemoryManager {
public:
    // The number of allocations made in total between Start and Stop.
    int64_t num_allocs;

    // The peak memory use between Start and Stop.
    int64_t max_bytes_used;

    void Start() BENCHMARK_OVERRIDE {
        num_allocs = 0;
        max_bytes_used = 0;
    }

    void Stop(Result& result) BENCHMARK_OVERRIDE {
        result.num_allocs = num_allocs;
        result.max_bytes_used = max_bytes_used;
    }
};

std::unique_ptr<CustomMemoryManager> mm(new CustomMemoryManager());

void *custom_malloc(size_t size) {
    void *p = malloc(size);
    mm.get()->num_allocs += 1;
    mm.get()->max_bytes_used += size;
    return p;
}
#define malloc(size) custom_malloc(size)

void *custom_calloc(size_t num, size_t size) {
    void *p = calloc(num, size);
    mm.get()->num_allocs += 1;
    mm.get()->max_bytes_used += size;
    return p;
}
#define calloc(num, size) custom_calloc(num, size)

void *custom_realloc(void *ptr, size_t size) {
    void *p = realloc(ptr, size);
    mm.get()->num_allocs += 1;
    mm.get()->max_bytes_used += size;
    return p;
}
#define realloc(ptr, size) custom_realloc(ptr, size)

void *custom_emalloc(size_t size, int persistent) {
    void *p = emalloc(size);
    mm.get()->num_allocs += 1;
    mm.get()->max_bytes_used += size;
    return p;
}
#define emalloc(size) custom_emalloc(size)

void *custom_ecalloc(size_t num, size_t size, int persistent) {
    void *p = ecalloc(num, size);
    mm.get()->num_allocs += 1;
    mm.get()->max_bytes_used += size;
    return p;
}
#define ecalloc(num, size) custom_ecalloc(num, size)

void *custom_erealloc(void *ptr, size_t size, int persistent) {
    void *p = erealloc(ptr, size);
    mm.get()->num_allocs += 1;
    mm.get()->max_bytes_used += size;
    return p;
}
#define erealloc(ptr, size) custom_erealloc(ptr, size)

void *valloc(size_t size) {
    void *p = valloc(size);
    mm.get()->num_allocs += 1;
    mm.get()->max_bytes_used += size;
    return p;
}
#define valloc(size) custom_valloc(size)

#endif