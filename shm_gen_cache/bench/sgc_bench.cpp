// Throughput benchmark for shm_gen_cache, driven only through its C API
// (../shm_gen_cache.h), as a C or C++ embedder would use it:
// ddog_sgc_cache_init_in on a mapping the program owns,
// ddog_sgc_participant_register per thread, ddog_sgc_lookup and
// ddog_sgc_insert, built against the crate's staticlib.
//
// The primary workload is how the cache is used in practice: every operation
// is lookup(); a miss is followed by insert() of that key's one value. Hit
// rate, insert rate and rotation rate are therefore results, not inputs; the
// only knobs are the key-set size, the popularity skew and the thread count.
// Secondary groups isolate the read path (lookup_hit, lookup_miss) and the
// write path (insert_new, which also drives rotation).
//
// Rotations are not observable through the public API. They are estimated by
// replaying the exact same operation streams through a reference model of
// the documented two-generation semantics (see generation_model). For one
// thread the model is exact, which the measured hit count cross-checks; with
// several threads it replays a round-robin interleaving.

// Every result recorded so far was taken with the harness compiled without
// exceptions or RTTI (CMakeLists.txt). The dialect changes the harness's own
// code generation, so a build that silently dropped it would not be
// comparable with them.
#if defined(__cpp_exceptions) || defined(__cpp_rtti) || defined(__GXX_RTTI)
#error "sgc_bench must be built with -fno-exceptions -fno-rtti"
#endif

#include <pthread.h>

#include <sys/mman.h>

#if defined(__APPLE__)
#include <pthread/qos.h>
#endif

#if defined(__linux__)
#include <sched.h>
#endif

#include <algorithm>
#include <array>
#include <atomic>
#include <bit>
#include <chrono>
#include <cinttypes>
#include <cmath>
#include <cstddef>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <limits>
#include <memory>
#include <numeric>
#include <optional>
#include <span>
#include <string>
#include <string_view>
#include <thread>
#include <utility>
#include <vector>

#include "shm_gen_cache.h"

namespace {

using u8 = std::uint8_t;
using u16 = std::uint16_t;
using u32 = std::uint32_t;
using u64 = std::uint64_t;
using usize = std::size_t;

// 64Ki buckets: each arena's index is 512 KiB and its used record area is
// several MiB, so the working set lives in L2/SLC rather than L1, as a
// production cache of this kind would. Keys up to 64 bytes and values up to
// 512 bytes cover identifier-like keys and small serialized objects. The
// record area keeps the library's default sizing (every bucket can hold a
// maximal record), so rotation is driven by the occupancy target, never by
// running out of record space.
constexpr u32 bucket_count = u32{1} << 16;
constexpr u32 max_key_size = 64;
constexpr u32 max_value_size = 512;
constexpr u32 min_key_size = 16;
constexpr u32 min_value_size = 8;
constexpr u32 max_occupancy = bucket_count * 7 / 10;

// The cache-line size the cache's shared layout is built on (see
// ddog_sgc_cache_init_in); the bench pads its own shared state to it.
#if defined(__aarch64__)
constexpr usize cache_line_size = 128;
#else
constexpr usize cache_line_size = 64;
#endif

// The record area is spelled out rather than left zero (which selects the
// same default) so the header can print it. A zero reservation_chunk_size
// selects the default chunk (five maximal records), and the occupancy
// estimator stays selected.
constexpr ddog_sgc_Config bench_config{
    .participant_capacity = 128,
    .bucket_count = bucket_count,
    .max_key_size = max_key_size,
    .max_value_size = max_value_size,
    .record_area_size = bucket_count * (8 + 8 + max_key_size + max_value_size),
    .max_occupancy = max_occupancy,
    .reservation_chunk_size = 0,
    .always_exact_occupancy = false,
};
static_assert(bench_config.max_occupancy ==
              static_cast<u32>(bucket_count * 0.7));

// Bytes of the cache's mapping, as the library computes them for
// bench_config. Called from the main thread only.
usize bench_mapping_size() {
    static const usize size = ddog_sgc_cache_mapping_size(&bench_config);
    return size;
}

// Lookup output: the library copies whole 8-byte words into it.
struct value_buffer {
    static constexpr usize capacity = max_value_size;
    u64 words[(capacity + 7) / 8]{};

    [[nodiscard]] std::span<const u8> bytes(usize len) const noexcept {
        return {reinterpret_cast<const u8*>(words), len};
    }
};

// One thread's registration, unregistered on destruction. Each operation is
// exactly one call into the library, so the timed loop measures the C API
// as an embedder calls it.
class participant {
public:
    // Registers the calling thread; on failure the object is empty and
    // status() says why.
    explicit participant(const ddog_sgc_Cache* cache) noexcept
        : status_{ddog_sgc_participant_register(cache, &handle_)} {}
    participant(participant&& o) noexcept
        : handle_{std::exchange(o.handle_, nullptr)}, status_{o.status_} {}
    participant(const participant&) = delete;
    participant& operator=(const participant&) = delete;
    participant& operator=(participant&&) = delete;
    ~participant() {
        ddog_sgc_participant_unregister(handle_);  // NULL is a no-op
    }

    explicit operator bool() const noexcept {
        return handle_ != nullptr;
    }
    [[nodiscard]] ddog_sgc_Status status() const noexcept {
        return status_;
    }

    // OK with the value in out.bytes(len), MISS, or an error.
    ddog_sgc_Status lookup(u64 hash, std::span<const u8> key,
                           value_buffer& out, usize& len) noexcept {
        return ddog_sgc_lookup(handle_, hash, key.data(), key.size(),
                               out.words, value_buffer::capacity, &len);
    }

    ddog_sgc_Status insert(u64 hash, std::span<const u8> key,
                           std::span<const u8> value) noexcept {
        return ddog_sgc_insert(handle_, hash, key.data(), key.size(),
                               value.data(), value.size());
    }

private:
    ddog_sgc_Participant* handle_ = nullptr;
    ddog_sgc_Status status_;
};

// The scenario matrix. Primary key sets are relative to capacity: one
// generation admits max_occupancy (~45.9Ki) keys and the two generations
// together hold between ~46Ki and ~92Ki distinct keys. 16Ki fits outright,
// 64Ki sits at the effective capacity and 1Mi is ~11x it.
constexpr u32 universe_size = u32{1} << 20;
constexpr std::array<u32, 3> mixed_key_counts{u32{1} << 14, u32{1} << 16,
                                              universe_size};
constexpr std::array<double, 2> skews{0.8, 1.1};
// The lookup_hit set fits one generation; lookup_miss keys are never
// inserted. The skew of misses matters only for the caller's own data.
constexpr u32 lookup_hit_keys = u32{1} << 15;
constexpr u32 lookup_miss_keys = u32{1} << 16;
constexpr double miss_skew = 0.8;

enum class op_kind : u8 { lookup_or_insert, lookup, insert };

#if defined(__linux__)
constexpr bool huge_pages_default = true;
#else
constexpr bool huge_pages_default = false;
#endif

struct options {
    u32 reps = 7;
    u64 ops_per_thread = 250'000;
    u64 warmup_ops = 1'000'000;
    std::vector<u32> threads{1, 4, 8};
    std::vector<std::string> filters;
    std::string json_path;
    bool model = true;
    bool verify = false;
    bool check_pinning = false;
    bool list = false;
    bool pin = false;
    bool huge_pages = huge_pages_default;
};

// Key and value bytes are a fixed function of the key's index (fill_key,
// fill_words with value_seed), and only their lengths and the key's hash are
// stored. An operation generates its key just before the lookup and its
// value just before an insert, into the calling thread's buffers: a real
// caller holds the key it looks up and has just computed the value it
// inserts, so neither comes from main memory. Storing every key and value
// up front made the harness's own DRAM misses dominate insert-heavy runs.
struct key_record {
    u64 hash;
    u16 value_len;
    u8 key_len;
};

struct source_buffers {
    alignas(8) std::array<u8, max_key_size> key;
    alignas(8) std::array<u8, max_value_size> value;
};

struct dataset {
    std::vector<key_record> records;

    // Key i's bytes, generated into b.key.
    [[nodiscard]] std::span<const u8> key(u32 i, source_buffers& b) const;
    // Key i's value, generated into b.value.
    [[nodiscard]] std::span<const u8> value(u32 i, source_buffers& b) const;
};

// Per-thread result of one phase; padded so threads never share a line.
struct alignas(128) thread_counters {
    u64 hits = 0;
    u64 misses = 0;
    u64 inserts = 0;
    u64 lookup_errors = 0;
    u64 insert_errors = 0;
    u64 bad_values = 0;
    u64 sink = 0;
    u32 pinned_location = std::numeric_limits<u32>::max();
    u32 unexpected_location = std::numeric_limits<u32>::max();
    u32 unexpected_location_rep = 0;
    bool registration_failed = false;
};

struct model_counts {
    u64 ops = 0;
    u64 hits = 0;
    u64 promotions = 0;
    u64 inserts = 0;
    u64 replacements = 0;
    u64 rotations = 0;
};

struct scenario_result {
    std::string name;
    std::string group;
    u32 keys = 0;
    double skew = 0;
    u32 threads = 0;
    u64 ops_per_rep = 0;
    std::vector<double> wall_ns;  // per repetition
    thread_counters totals;       // measured repetitions only
    std::optional<model_counts> model;
    std::vector<u32> cpu_locations;
    bool pinning_failed = false;
};

// How each thread's operation stream is produced and consumed.
struct phase_plan {
    std::string name;
    std::string group;
    op_kind kind = op_kind::lookup_or_insert;
    u32 keys = 0;
    double skew = 0;
    u32 threads = 1;
    u64 warmup_per_thread = 0;
    // Fills a thread's whole stream: warmup, then reps * ops_per_thread.
    void (*generate)(const void* ctx, u32 thread, u32 thread_count,
                     std::span<u32> out) = nullptr;
    const void* ctx = nullptr;
};

bool parse_options(int argc, char** argv, options& opt);
dataset build_dataset();
void run_mixed(const options& opt, const dataset& data,
               std::vector<scenario_result>& results);
void run_lookup(const options& opt, const dataset& data,
                std::vector<scenario_result>& results);
void run_insert(const options& opt, const dataset& data,
                std::vector<scenario_result>& results);
void print_scenarios(const options& opt);
void print_header(const options& opt, const dataset& data);
void print_table(const std::vector<scenario_result>& results);
bool write_json(const std::string& path, const options& opt,
                const std::vector<scenario_result>& results);
bool selected(const options& opt, std::string_view name);
std::string phase_name(std::string_view group, u32 keys, double skew,
                       u32 threads);
void raise_thread_priority();
void pin_worker(const options& opt, u32 t);
u32 current_cpu_location();

// ---------------------------------------------------------------------------
// Randomness, hashing and sampling. All of it runs before timing starts.

constexpr u64 fmix64(u64 x) {
    x ^= x >> 33;
    x *= 0xff51afd7ed558ccdULL;
    x ^= x >> 33;
    x *= 0xc4ceb9fe1a85ec53ULL;
    x ^= x >> 33;
    return x;
}

struct splitmix64 {
    u64 state;

    u64 operator()() {
        state += 0x9e3779b97f4a7c15ULL;
        u64 z = state;
        z = (z ^ (z >> 30)) * 0xbf58476d1ce4e5b9ULL;
        z = (z ^ (z >> 27)) * 0x94d049bb133111ebULL;
        return z ^ (z >> 31);
    }

    // Lemire's multiply-shift reduction; bias is irrelevant at these sizes.
    u32 below(u32 bound) {
        return static_cast<u32>(((*this)() >> 32) * bound >> 32);
    }

    double unit() {
        return static_cast<double>((*this)() >> 11) * 0x1.0p-53;
    }
};

// Word-at-a-time hash with a full-avalanche bijective mixer per word. Its
// cost is irrelevant (hashes are precomputed); what matters is that every
// key bit reaches the low bits the cache uses to pick a bucket.
u64 hash_bytes(std::span<const u8> bytes) {
    u64 h = 0x243f6a8885a308d3ULL ^ (bytes.size() * 0x9e3779b97f4a7c15ULL);
    usize i = 0;
    for (; i + 8 <= bytes.size(); i += 8) {
        u64 w;
        std::memcpy(&w, bytes.data() + i, 8);
        h = fmix64(h ^ w) + 0x9e3779b97f4a7c15ULL;
    }
    if (i < bytes.size()) {
        u64 w = 0;
        std::memcpy(&w, bytes.data() + i, bytes.size() - i);
        h = fmix64(h ^ w) + 0x9e3779b97f4a7c15ULL;
    }
    return fmix64(h ^ bytes.size());
}

void shuffle(std::span<u32> v, u64 seed) {
    splitmix64 rng{seed};
    for (usize i = v.size(); i > 1; --i) {
        const u32 j = rng.below(static_cast<u32>(i));
        std::swap(v[i - 1], v[j]);
    }
}

std::vector<u32> iota_from(u32 first, u32 count) {
    std::vector<u32> v(count);
    std::iota(v.begin(), v.end(), first);
    return v;
}

// Zipf(s) over ranks 1..n, sampled in O(1) with Walker/Vose alias tables.
// rank_to_key is shuffled by the caller, so popularity is uncorrelated with
// key content, hash and dataset position.
class zipf_sampler {
public:
    zipf_sampler(std::vector<u32> rank_to_key, double s)
        : keys(std::move(rank_to_key)),
          threshold(keys.size()),
          alias(keys.size()) {
        const usize n = keys.size();
        std::vector<double> p(n);
        double sum = 0;
        for (usize i = 0; i < n; ++i) {
            p[i] = 1.0 / std::pow(static_cast<double>(i + 1), s);
            sum += p[i];
        }
        std::vector<u32> small;
        std::vector<u32> large;
        for (usize i = 0; i < n; ++i) {
            p[i] = p[i] * static_cast<double>(n) / sum;
            (p[i] < 1.0 ? small : large).push_back(static_cast<u32>(i));
        }
        while (!small.empty() && !large.empty()) {
            const u32 lo = small.back();
            small.pop_back();
            const u32 hi = large.back();
            threshold[lo] = to_threshold(p[lo]);
            alias[lo] = hi;
            p[hi] -= 1.0 - p[lo];
            if (p[hi] < 1.0) {
                large.pop_back();
                small.push_back(hi);
            }
        }
        for (const u32 i : large) {
            threshold[i] = UINT32_MAX;
            alias[i] = i;
        }
        for (const u32 i : small) {  // rounding leftovers
            threshold[i] = UINT32_MAX;
            alias[i] = i;
        }
    }

    [[nodiscard]] u32 operator()(u64 r) const {
        const auto n = static_cast<u64>(keys.size());
        const auto column = static_cast<u32>(((r >> 32) * n) >> 32);
        const auto coin = static_cast<u32>(r);
        return keys[coin < threshold[column] ? column : alias[column]];
    }

    [[nodiscard]] u32 key_of_rank(usize rank) const {
        return keys[rank];
    }

    [[nodiscard]] usize size() const {
        return keys.size();
    }

private:
    static u32 to_threshold(double p) {
        const double scaled = p * 4294967296.0;
        return scaled >= 4294967295.0 ? UINT32_MAX : static_cast<u32>(scaled);
    }

    std::vector<u32> keys;
    std::vector<u32> threshold;
    std::vector<u32> alias;
};

// ---------------------------------------------------------------------------
// Reference model of the documented semantics: lookups probe the current
// generation, then the previous one, and promote a previous-generation hit
// into the current one; an insert or promotion of a key new to the current
// generation consumes occupancy; reaching max_occupancy rotates, which
// discards the previous generation. Operation-for-operation it is exact for
// a single thread; with several it replays a round-robin interleaving.

class generation_model {
public:
    explicit generation_model(usize universe) : newest(universe, 0) {}

    void apply(op_kind kind, u32 key) {
        ++counts.ops;
        switch (kind) {
            case op_kind::insert:
                ++counts.inserts;
                put(key);
                return;
            case op_kind::lookup:
            case op_kind::lookup_or_insert:
                break;
        }
        if (newest[key] == epoch) {
            ++counts.hits;
        } else if (newest[key] + 1 == epoch) {
            ++counts.hits;
            ++counts.promotions;
            put(key);
        } else if (kind == op_kind::lookup_or_insert) {
            ++counts.inserts;
            put(key);
        }
    }

    model_counts counts;

private:
    void put(u32 key) {
        if (newest[key] == epoch) {
            ++counts.replacements;  // replacement consumes no bucket
            return;
        }
        newest[key] = epoch;
        if (++occupancy >= max_occupancy) {
            ++counts.rotations;
            ++epoch;
            occupancy = 0;
        }
    }

    std::vector<u32> newest;  // newest generation holding the key; 0 = none
    u32 epoch = 2;
    u32 occupancy = 0;
};

// ---------------------------------------------------------------------------
// A fresh process-shared mapping per family, initialized the way a user
// would: mmap, then ddog_sgc_cache_init_in. Initialization writes every
// page, so no page faults happen inside timed regions.
//
// With --huge-pages (Linux) the mapping is instead private anonymous memory,
// aligned and padded to 2 MiB and advised MADV_HUGEPAGE before
// initialization, so its faults allocate transparent huge pages. That is the
// one kind of memory an unprivileged process gets THP for under the usual
// enabled=madvise, shmem_enabled=never settings; the bench is one process,
// so its threads still share the cache. README.md covers shared mappings.

// Lowest fraction of a --huge-pages mapping found THP-backed right after
// initialization, over all mappings of the run.
double thp_min_coverage = 1.0;

double thp_coverage(const u8* first, usize len);

class mapped_cache {
public:
    explicit mapped_cache(const options& opt) {
        if (opt.huge_pages) {
            map_huge();
        } else {
            map_shared();
        }
        const ddog_sgc_Status rc = ddog_sgc_cache_init_in(
            mapping, bench_mapping_size(), &bench_config, &instance);
        if (rc != DDOG_SGC_STATUS_OK) {
            std::fprintf(stderr, "cache initialization failed: %d\n",
                         static_cast<int>(rc));
            std::abort();
        }
        if (opt.huge_pages) {
            thp_min_coverage =
                std::min(thp_min_coverage, thp_coverage(mapping, huge_len));
        }
    }
    mapped_cache(const mapped_cache&) = delete;
    mapped_cache& operator=(const mapped_cache&) = delete;
    // Every participant must have been unregistered by now.
    ~mapped_cache() {
        ddog_sgc_cache_free(instance);  // the handle, not the mapping
        ::munmap(base, base_len);
    }

    [[nodiscard]] const ddog_sgc_Cache* get() const {
        return instance;
    }

private:
    void map_shared() {
        base_len = bench_mapping_size();
        base = map_or_die(MAP_SHARED | MAP_ANON);
        mapping = static_cast<u8*>(base);
    }

    void map_huge() {
#if defined(__linux__)
        constexpr usize huge_page_size = usize{2} << 20;
        const usize len = align_up(bench_mapping_size(), huge_page_size);
        huge_len = len;
        base_len = len + huge_page_size;
        base = map_or_die(MAP_PRIVATE | MAP_ANONYMOUS);
        mapping = reinterpret_cast<u8*>(
            align_up(reinterpret_cast<usize>(base), huge_page_size));
        if (::madvise(mapping, len, MADV_HUGEPAGE) != 0) {
            std::perror("madvise(MADV_HUGEPAGE)");
        }
#else
        map_shared();  // unreachable: parse_options leaves huge_pages off
#endif
    }

    void* map_or_die(int flags) const {
        void* p =
            ::mmap(nullptr, base_len, PROT_READ | PROT_WRITE, flags, -1, 0);
        if (p == MAP_FAILED) {
            std::perror("mmap");
            std::abort();
        }
        return p;
    }

    static constexpr usize align_up(usize n, usize a) {
        return (n + a - 1) & ~(a - 1);
    }

    void* base = nullptr;
    usize base_len = 0;
    usize huge_len = 0;  // the madvised span, whole huge pages
    u8* mapping = nullptr;
    ddog_sgc_Cache* instance = nullptr;
};

// The share of [first, first + len) backed by huge pages: AnonHugePages
// summed over the VMAs of /proc/self/smaps overlapping it (madvise may have
// split the mapping into several). len must cover whole huge pages, the
// madvised span, so that a fully backed range reads exactly 1.
double thp_coverage(const u8* first, usize len) {
    std::FILE* f = std::fopen("/proc/self/smaps", "r");
    if (f == nullptr) {
        return 0;
    }
    const auto lo = reinterpret_cast<uintptr_t>(first);
    const uintptr_t hi = lo + len;
    bool inside = false;
    u64 huge_kb = 0;
    char line[256];
    while (std::fgets(line, sizeof line, f) != nullptr) {
        uintptr_t start = 0;
        uintptr_t end = 0;
        unsigned long long kb = 0;
        if (std::sscanf(line, "%" SCNxPTR "-%" SCNxPTR " ", &start, &end) ==
            2) {
            inside = start < hi && lo < end;
        } else if (inside &&
                   std::sscanf(line, "AnonHugePages: %llu kB", &kb) == 1) {
            huge_kb += kb;
        }
    }
    std::fclose(f);
    return std::min(
        1.0, static_cast<double>(huge_kb) * 1024 / static_cast<double>(len));
}

participant register_or_die(const ddog_sgc_Cache* c) {
    participant p{c};
    if (!p) {
        std::fprintf(stderr, "register_participant failed: %d\n",
                     static_cast<int>(p.status()));
        std::abort();
    }
    return p;
}

// Untimed setup inserts from the calling thread.
void insert_all(participant& p, const dataset& data,
                std::span<const u32> keys, generation_model* model) {
    source_buffers buf;
    for (const u32 k : keys) {
        const auto& r = data.records[k];
        if (p.insert(r.hash, data.key(k, buf), data.value(k, buf)) !=
            DDOG_SGC_STATUS_OK) {
            std::fprintf(stderr, "setup insert failed\n");
            std::abort();
        }
        if (model != nullptr) {
            model->apply(op_kind::insert, k);
        }
    }
}

void insert_all(const ddog_sgc_Cache* c, const dataset& data,
                std::span<const u32> keys, generation_model* model) {
    auto p = register_or_die(c);
    insert_all(p, data, keys, model);
}

scenario_result run_phase(const options& opt, const dataset& data,
                          const ddog_sgc_Cache* c, const phase_plan& plan,
                          generation_model* model);

}  // namespace

int main(int argc, char** argv) {
    options opt;
    if (!parse_options(argc, argv, opt)) {
        return 2;
    }
    raise_thread_priority();
    if (opt.list) {
        print_scenarios(opt);
        return 0;
    }

    const dataset data = build_dataset();
    print_header(opt, data);

    std::vector<scenario_result> results;
    run_mixed(opt, data, results);
    run_lookup(opt, data, results);
    run_insert(opt, data, results);

    if (std::ranges::any_of(results,
                            [](const auto& r) { return r.pinning_failed; })) {
        std::fprintf(stderr, "CPU pinning verification failed\n");
        return 1;
    }
    print_table(results);
    if (opt.huge_pages) {
        std::printf("huge pages: every cache mapping >= %.1f%% THP-backed\n",
                    100 * thp_min_coverage);
    }
    if (!opt.json_path.empty() && !write_json(opt.json_path, opt, results)) {
        return 1;
    }
    for (const auto& r : results) {
        const auto& t = r.totals;
        if (t.registration_failed || t.bad_values != 0) {
            std::fprintf(stderr, "%s: registration failure or bad values\n",
                         r.name.c_str());
            return 1;
        }
    }
    return 0;
}

namespace {

// ---------------------------------------------------------------------------
// Scenario families. Within a family the cache persists across thread
// counts, so each later phase starts from the steady state the previous one
// left; only the first phase needs the long warmup.

struct zipf_stream_ctx {
    const zipf_sampler* sampler;
    u64 seed;
};

void generate_zipf(const void* ctx, u32 thread, u32 thread_count,
                   std::span<u32> out) {
    const auto& z = *static_cast<const zipf_stream_ctx*>(ctx);
    splitmix64 rng{fmix64(z.seed ^ (u64{thread_count} << 32) ^ thread)};
    for (auto& v : out) {
        v = (*z.sampler)(rng());
    }
}

struct cyclic_stream_ctx {
    const std::vector<u32>* pool;
};

// Thread t walks its own contiguous slice of a shuffled pool, cyclically.
// The pool is so much larger than the two generations that a key has been
// rotated out long before its slice wraps around: every insert is new.
void generate_cyclic(const void* ctx, u32 thread, u32 thread_count,
                     std::span<u32> out) {
    const auto& pool = *static_cast<const cyclic_stream_ctx*>(ctx)->pool;
    const usize slice = pool.size() / thread_count;
    const usize first = slice * thread;
    for (usize i = 0; i < out.size(); ++i) {
        out[i] = pool[first + i % slice];
    }
}

bool family_selected(const options& opt, std::string_view group, u32 keys,
                     double skew) {
    return std::ranges::any_of(opt.threads, [&](u32 t) {
        return selected(opt, phase_name(group, keys, skew, t));
    });
}

void run_mixed(const options& opt, const dataset& data,
               std::vector<scenario_result>& results) {
    for (const u32 keys : mixed_key_counts) {
        for (const double s : skews) {
            if (!family_selected(opt, "mixed", keys, s)) {
                continue;
            }
            const u64 seed = fmix64(keys ^ std::bit_cast<u64>(s));
            auto rank_to_key = iota_from(0, keys);
            shuffle(rank_to_key, seed);
            const zipf_sampler sampler{std::move(rank_to_key), s};
            const zipf_stream_ctx ctx{.sampler = &sampler, .seed = seed};

            mapped_cache mc{opt};
            std::optional<generation_model> model;
            if (opt.model) {
                model.emplace(universe_size);
            }
            // Shortcut towards steady state: insert the hottest 1.5
            // generations' worth of keys, coldest first, so the hottest
            // keys end up in the current generation. Warmup does the rest.
            const usize prefill =
                std::min<usize>(keys, max_occupancy + max_occupancy / 2);
            std::vector<u32> order(prefill);
            for (usize i = 0; i < prefill; ++i) {
                order[i] = sampler.key_of_rank(prefill - 1 - i);
            }
            insert_all(mc.get(), data, order, model ? &*model : nullptr);

            bool first = true;
            for (const u32 t : opt.threads) {
                auto name = phase_name("mixed", keys, s, t);
                if (!selected(opt, name)) {
                    continue;
                }
                const u64 warm_total =
                    first ? opt.warmup_ops : opt.warmup_ops / 4;
                first = false;
                const phase_plan plan{
                    .name = std::move(name),
                    .group = "mixed",
                    .kind = op_kind::lookup_or_insert,
                    .keys = keys,
                    .skew = s,
                    .threads = t,
                    .warmup_per_thread = warm_total / t,
                    .generate = generate_zipf,
                    .ctx = &ctx,
                };
                results.push_back(run_phase(opt, data, mc.get(), plan,
                                            model ? &*model : nullptr));
                std::fprintf(stderr, "  done %s\n",
                             results.back().name.c_str());
            }
        }
    }
}

void run_lookup(const options& opt, const dataset& data,
                std::vector<scenario_result>& results) {
    const bool any =
        std::ranges::any_of(skews,
                            [&](double s) {
                                return family_selected(opt, "lookup_hit",
                                                       lookup_hit_keys, s);
                            }) ||
        family_selected(opt, "lookup_miss", lookup_miss_keys, miss_skew);
    if (!any) {
        return;
    }

    // Dataset layout: [0, A) hit set, [A, A + F) filler, then the miss set.
    // Filler reaches the rotation target, moving that generation into the
    // previous arena; the hit set then fills the current one. Keep one setup
    // registration across both batches so its process-local occupancy
    // estimate retains the samples that brought the first arena to its
    // target. Hits never promote during the timed phase. A miss probes the
    // current generation's roughly 50%-occupied hash table, followed by the
    // previous generation's roughly 70%-occupied hash table.
    const u32 filler_first = lookup_hit_keys;
    const u32 miss_first = filler_first + max_occupancy;
    mapped_cache mc{opt};
    {
        auto p = register_or_die(mc.get());
        insert_all(p, data, iota_from(filler_first, max_occupancy), nullptr);
        insert_all(p, data, iota_from(0, lookup_hit_keys), nullptr);
        value_buffer out;
        source_buffers buf;
        for (u32 k = 0; k < miss_first + lookup_miss_keys; ++k) {
            if (k >= filler_first && k < miss_first) {
                continue;  // a filler lookup would promote it
            }
            const auto& r = data.records[k];
            usize len;
            const ddog_sgc_Status rc =
                p.lookup(r.hash, data.key(k, buf), out, len);
            const bool want_hit = k < lookup_hit_keys;
            if (rc != (want_hit ? DDOG_SGC_STATUS_OK : DDOG_SGC_STATUS_MISS)) {
                std::fprintf(stderr, "lookup setup: unexpected state\n");
                std::abort();
            }
        }
    }

    auto run_set = [&](std::string_view group, op_kind kind, u32 first_key,
                       u32 keys, double s) {
        const u64 seed = fmix64(first_key ^ std::bit_cast<u64>(s));
        auto rank_to_key = iota_from(first_key, keys);
        shuffle(rank_to_key, seed);
        const zipf_sampler sampler{std::move(rank_to_key), s};
        const zipf_stream_ctx ctx{.sampler = &sampler, .seed = seed};
        for (const u32 t : opt.threads) {
            auto name = phase_name(group, keys, s, t);
            if (!selected(opt, name)) {
                continue;
            }
            const phase_plan plan{
                .name = std::move(name),
                .group = std::string{group},
                .kind = kind,
                .keys = keys,
                .skew = s,
                .threads = t,
                .warmup_per_thread = opt.ops_per_thread / 2,
                .generate = generate_zipf,
                .ctx = &ctx,
            };
            // State never changes, so a model would only restate the
            // obvious: no promotions, inserts or rotations.
            auto r = run_phase(opt, data, mc.get(), plan, nullptr);
            if (opt.model) {
                r.model = model_counts{.ops = r.ops_per_rep * opt.reps,
                                       .hits = r.totals.hits};
            }
            results.push_back(std::move(r));
            std::fprintf(stderr, "  done %s\n", results.back().name.c_str());
        }
    };
    for (const double s : skews) {
        run_set("lookup_hit", op_kind::lookup, 0, lookup_hit_keys, s);
    }
    run_set("lookup_miss", op_kind::lookup, miss_first, lookup_miss_keys,
            miss_skew);
}

void run_insert(const options& opt, const dataset& data,
                std::vector<scenario_result>& results) {
    if (!family_selected(opt, "insert_new", universe_size, 0)) {
        return;
    }
    auto pool = iota_from(0, universe_size);
    shuffle(pool, 0x1257ULL);
    const cyclic_stream_ctx ctx{.pool = &pool};
    for (const u32 t : opt.threads) {
        auto name = phase_name("insert_new", universe_size, 0, t);
        if (!selected(opt, name)) {
            continue;
        }
        // A fresh cache per thread count keeps every insert new regardless
        // of where the previous phase's slices stopped.
        mapped_cache mc{opt};
        std::optional<generation_model> model;
        if (opt.model) {
            model.emplace(universe_size);
        }
        const phase_plan plan{
            .name = std::move(name),
            .group = "insert_new",
            .kind = op_kind::insert,
            .keys = universe_size,
            .skew = 0,
            .threads = t,
            // Fill both generations so rotation is already periodic.
            .warmup_per_thread = u64{2} * bucket_count / t,
            .generate = generate_cyclic,
            .ctx = &ctx,
        };
        results.push_back(
            run_phase(opt, data, mc.get(), plan, model ? &*model : nullptr));
        std::fprintf(stderr, "  done %s\n", results.back().name.c_str());
    }
}

// ---------------------------------------------------------------------------
// Timed execution.

template <op_kind Kind, bool Verify>
void run_ops(participant& p, const dataset& data, std::span<const u32> seq,
             value_buffer& out, thread_counters& c) {
    u64 hits = 0;
    u64 misses = 0;
    u64 inserts = 0;
    u64 lookup_errors = 0;
    u64 insert_errors = 0;
    u64 bad_values = 0;
    u64 sink = c.sink;
    source_buffers buf;
    for (const u32 index : seq) {
        const key_record& r = data.records[index];
        const auto key = data.key(index, buf);
        if constexpr (Kind == op_kind::insert) {
            ++inserts;
            if (p.insert(r.hash, key, data.value(index, buf)) !=
                DDOG_SGC_STATUS_OK) [[unlikely]] {
                ++insert_errors;
            }
            continue;
        } else {
            usize len;
            const ddog_sgc_Status rc = p.lookup(r.hash, key, out, len);
            if (rc == DDOG_SGC_STATUS_OK) [[likely]] {
                const std::span<const u8> v = out.bytes(len);
                ++hits;
                if constexpr (Verify) {
                    const auto expected = data.value(index, buf);
                    bad_values +=
                        v.size() != expected.size() ||
                        std::memcmp(v.data(), expected.data(), v.size()) != 0;
                } else {
                    bad_values += v.size() != r.value_len;
                }
                sink += v.size() + v[0];
                continue;
            }
            if (rc != DDOG_SGC_STATUS_MISS) {
                ++lookup_errors;
                continue;
            }
            ++misses;
            if constexpr (Kind == op_kind::lookup_or_insert) {
                ++inserts;
                if (p.insert(r.hash, key, data.value(index, buf)) !=
                    DDOG_SGC_STATUS_OK) [[unlikely]] {
                    ++insert_errors;
                }
            }
        }
    }
    c.hits += hits;
    c.misses += misses;
    c.inserts += inserts;
    c.lookup_errors += lookup_errors;
    c.insert_errors += insert_errors;
    c.bad_values += bad_values;
    c.sink = sink;
}

template <bool Verify>
void dispatch_ops(op_kind kind, participant& p, const dataset& data,
                  std::span<const u32> seq, value_buffer& out,
                  thread_counters& c) {
    switch (kind) {
        case op_kind::lookup_or_insert:
            run_ops<op_kind::lookup_or_insert, Verify>(p, data, seq, out, c);
            return;
        case op_kind::lookup:
            run_ops<op_kind::lookup, Verify>(p, data, seq, out, c);
            return;
        case op_kind::insert:
            run_ops<op_kind::insert, Verify>(p, data, seq, out, c);
            return;
    }
}

u64 now_ns() {
    return static_cast<u64>(
        std::chrono::duration_cast<std::chrono::nanoseconds>(
            std::chrono::steady_clock::now().time_since_epoch())
            .count());
}

// The spin-wait hint for workers waiting between stages.
void spin_pause() {
#if defined(__x86_64__) || defined(__i386__)
    asm volatile("pause" ::: "memory");
#elif defined(__aarch64__)
    asm volatile("yield" ::: "memory");
#else
    std::atomic_signal_fence(std::memory_order_seq_cst);
#endif
}

void do_not_optimize(u64 value) {
    asm volatile("" : : "r"(value) : "memory");
}

void raise_thread_priority() {
#if defined(__APPLE__)
    // Keeps workers on performance cores while there are enough of them.
    pthread_set_qos_class_self_np(QOS_CLASS_USER_INTERACTIVE, 0);
#endif
}

// Binds worker t to the t-th CPU of the process's affinity mask (wrapping),
// so that `taskset -c <one CPU per core> sgc_bench --pin` gives every worker
// its own physical core and a deterministic placement across caches.
void pin_worker(const options& opt, u32 t) {
#if defined(__linux__)
    if (!opt.pin) {
        return;
    }
    cpu_set_t allowed;
    CPU_ZERO(&allowed);
    if (sched_getaffinity(0, sizeof allowed, &allowed) != 0) {
        return;
    }
    const int count = CPU_COUNT(&allowed);
    if (count == 0) {
        return;
    }
    int want = static_cast<int>(t % static_cast<u32>(count));
    for (int cpu = 0; cpu < CPU_SETSIZE; ++cpu) {
        if (CPU_ISSET(cpu, &allowed) && want-- == 0) {
            cpu_set_t one;
            CPU_ZERO(&one);
            CPU_SET(cpu, &one);
            pthread_setaffinity_np(pthread_self(), sizeof one, &one);
            return;
        }
    }
#else
    (void)opt;
    (void)t;
#endif
}

constexpr u32 no_cpu_location = std::numeric_limits<u32>::max();
constexpr u64 cpu_number_mask = 0xfff;
constexpr u64 cpu_cluster_mask = 0xff000;

u32 current_cpu_location() {
#if defined(__APPLE__) && defined(__aarch64__)
    // XNU publishes the current CPU and logical cluster in TPIDR_EL0. This
    // layout matches _os_cpu_number() and _os_cpu_cluster_number().
    u64 value;
    asm volatile("mrs %0, TPIDR_EL0" : "=r"(value) : : "memory");
    return static_cast<u32>(value & (cpu_number_mask | cpu_cluster_mask));
#else
    return no_cpu_location;
#endif
}

u32 cpu_number(u32 location) {
    return static_cast<u32>(location & cpu_number_mask);
}

u32 cpu_cluster(u32 location) {
    return static_cast<u32>((location & cpu_cluster_mask) >> 12);
}

// Stage 0 is the untimed warmup; stages 1..reps are the measured
// repetitions. Workers spin between stages (the gaps are microseconds); the
// coordinating thread sleeps in atomic wait so it never steals a core.
struct phase_sync {
    alignas(cache_line_size) std::atomic<u32> stage{0};
    alignas(cache_line_size) std::atomic<u32> arrived{0};
};

void wait_arrivals(phase_sync& sync, u32 target) {
    for (u32 seen = sync.arrived.load(std::memory_order_acquire); seen < target;
         seen = sync.arrived.load(std::memory_order_acquire)) {
        sync.arrived.wait(seen, std::memory_order_acquire);
    }
}

void arrive(phase_sync& sync, u32 participants) {
    const u32 arrived =
        sync.arrived.fetch_add(1, std::memory_order_acq_rel) + 1;
    if (arrived % participants == 0) {
        sync.arrived.notify_one();
    }
}

scenario_result run_phase(const options& opt, const dataset& data,
                          const ddog_sgc_Cache* c, const phase_plan& plan,
                          generation_model* model) {
    const u32 nthreads = plan.threads;
    const u64 warm = plan.warmup_per_thread;
    const u64 per_rep = opt.ops_per_thread;
    const u64 stream_len = warm + per_rep * opt.reps;
    const u32 stages = opt.reps + 1;

    std::vector<std::vector<u32>> streams(nthreads);
    std::vector<thread_counters> counters(nthreads);
    std::vector<u64> end_ns(static_cast<usize>(nthreads) * stages, 0);
    phase_sync sync;

    auto worker = [&](u32 t) {
        raise_thread_priority();
        pin_worker(opt, t);
        auto& seq = streams[t];
        seq.resize(stream_len);
        plan.generate(plan.ctx, t, nthreads, seq);
        participant registration{c};
        value_buffer out;
        if (!registration) {
            counters[t].registration_failed = true;
        }
        arrive(sync, nthreads);
        thread_counters discard;
        auto check_cpu_location = [&](u32 repetition) {
            if (!opt.check_pinning || repetition == 0) {
                return;
            }
            auto& counter = counters[t];
            const u32 location = current_cpu_location();
            if (counter.pinned_location == no_cpu_location) {
                counter.pinned_location = location;
            } else if (location != counter.pinned_location &&
                       counter.unexpected_location == no_cpu_location) {
                counter.unexpected_location = location;
                counter.unexpected_location_rep = repetition;
            }
        };
        for (u32 s = 0; s < stages; ++s) {
            while (sync.stage.load(std::memory_order_acquire) <= s) {
                spin_pause();
            }
            check_cpu_location(s);
            const u64 begin = s == 0 ? 0 : warm + (s - 1) * per_rep;
            const u64 len = s == 0 ? warm : per_rep;
            if (registration) {
                const auto slice = std::span<const u32>{seq}.subspan(
                    static_cast<usize>(begin), static_cast<usize>(len));
                auto& into = s == 0 ? discard : counters[t];
                if (opt.verify) {
                    dispatch_ops<true>(plan.kind, registration, data, slice,
                                       out, into);
                } else {
                    dispatch_ops<false>(plan.kind, registration, data, slice,
                                        out, into);
                }
            }
            check_cpu_location(s);
            end_ns[static_cast<usize>(t) * stages + s] = now_ns();
            arrive(sync, nthreads);
        }
        counters[t].sink += discard.sink;
        do_not_optimize(counters[t].sink);
    };

    std::vector<std::thread> pool;
    pool.reserve(nthreads);
    for (u32 t = 0; t < nthreads; ++t) {
        pool.emplace_back(worker, t);
    }
    wait_arrivals(sync, nthreads);

    scenario_result result;
    result.name = plan.name;
    result.group = plan.group;
    result.keys = plan.keys;
    result.skew = plan.skew;
    result.threads = nthreads;
    result.ops_per_rep = per_rep * nthreads;
    for (u32 s = 0; s < stages; ++s) {
        const u64 start = now_ns();
        sync.stage.store(s + 1, std::memory_order_release);
        wait_arrivals(sync, nthreads * (s + 2));
        if (s == 0) {
            continue;
        }
        u64 last = start;
        for (u32 t = 0; t < nthreads; ++t) {
            last = std::max(last, end_ns[static_cast<usize>(t) * stages + s]);
        }
        result.wall_ns.push_back(static_cast<double>(last - start));
    }
    for (auto& th : pool) {
        th.join();
    }

    for (const auto& tc : counters) {
        auto& total = result.totals;
        total.hits += tc.hits;
        total.misses += tc.misses;
        total.inserts += tc.inserts;
        total.lookup_errors += tc.lookup_errors;
        total.insert_errors += tc.insert_errors;
        total.bad_values += tc.bad_values;
        total.sink += tc.sink;
        total.registration_failed |= tc.registration_failed;
    }

    if (opt.check_pinning) {
        result.cpu_locations.reserve(nthreads);
        std::fprintf(stderr, "  placement %s:", result.name.c_str());
        for (u32 t = 0; t < nthreads; ++t) {
            const auto& counter = counters[t];
            result.cpu_locations.push_back(counter.pinned_location);
            std::fprintf(stderr, " w%u=c%u/cpu%u", t,
                         cpu_cluster(counter.pinned_location),
                         cpu_number(counter.pinned_location));
            if (counter.unexpected_location != no_cpu_location) {
                result.pinning_failed = true;
            }
        }
        for (u32 i = 0; i < nthreads; ++i) {
            for (u32 j = i + 1; j < nthreads; ++j) {
                if (result.cpu_locations[i] == result.cpu_locations[j]) {
                    result.pinning_failed = true;
                    std::fprintf(stderr, "\n    workers %u and %u share CPU %u",
                                 i, j, cpu_number(result.cpu_locations[i]));
                }
            }
        }
        for (u32 t = 0; t < nthreads; ++t) {
            const auto& counter = counters[t];
            if (counter.unexpected_location != no_cpu_location) {
                std::fprintf(
                    stderr,
                    "\n    worker %u moved in repetition %u: c%u/cpu%u -> "
                    "c%u/cpu%u",
                    t, counter.unexpected_location_rep,
                    cpu_cluster(counter.pinned_location),
                    cpu_number(counter.pinned_location),
                    cpu_cluster(counter.unexpected_location),
                    cpu_number(counter.unexpected_location));
            }
        }
        std::fprintf(stderr, " %s\n",
                     result.pinning_failed ? "FAILED" : "stable");
    }

    if (model != nullptr) {
        // Replay warmup and then the measured window, each interleaved
        // round-robin, counting only the measured window.
        auto replay = [&](u64 begin, u64 len) {
            for (u64 i = begin; i < begin + len; ++i) {
                for (u32 t = 0; t < nthreads; ++t) {
                    model->apply(plan.kind, streams[t][i]);
                }
            }
        };
        replay(0, warm);
        const model_counts before = model->counts;
        replay(warm, per_rep * opt.reps);
        const model_counts& after = model->counts;
        result.model = model_counts{
            .ops = after.ops - before.ops,
            .hits = after.hits - before.hits,
            .promotions = after.promotions - before.promotions,
            .inserts = after.inserts - before.inserts,
            .replacements = after.replacements - before.replacements,
            .rotations = after.rotations - before.rotations,
        };
    }
    return result;
}

// ---------------------------------------------------------------------------
// Data. Every key has exactly one value, fixed here; no scenario ever
// inserts a different value for a key that may be present.

// Fills [out, out + n) with seed, seed + k, seed + 2k, ... a word at a
// time, writing whole words: the last may run up to 7 bytes past n, so out
// must have room for n rounded up to 8. Only the keys' identity matters to
// the cache (their hashes are stored), so the bytes need no randomness, and
// generating them must stay much cheaper than a lookup: an add and a store
// per 8 bytes, with no variable-length copy.
[[gnu::always_inline]] inline void fill_words(u64 seed, u8* out, usize n) {
    constexpr u64 k = 0x9e3779b97f4a7c15ULL;
    u64 w = seed;
    for (usize j = 0; j < n; j += 8, w += k) {
        std::memcpy(out + j, &w, 8);
    }
}

[[gnu::always_inline]] inline u64 key_seed(u32 i) {
    return fmix64(i ^ 0x3c6ef372fe94f82bULL);
}

[[gnu::always_inline]] inline u64 value_seed(u32 i) {
    return fmix64(i ^ 0xa54ff53a5f1d36f1ULL);
}

// Key i, written as a whole max_key_size buffer: a fixed number of words
// avoids the mispredicted exit a loop over the key's own length (16-64
// bytes) took on most keys, which cost lookups more than the stores. Only
// the key's first key_len bytes are used. Its first 8 bytes are a bijection
// of i, so keys are distinct.
[[gnu::always_inline]] inline void fill_key(u32 i, u8* out) {
    const u64 id = fmix64(i + 0x6a09e667f3bcc909ULL);
    std::memcpy(out, &id, 8);
    fill_words(key_seed(i), out + 8, max_key_size - 8);
}

std::span<const u8> dataset::key(u32 i, source_buffers& b) const {
    const auto len = records[i].key_len;
    fill_key(i, b.key.data());
    return {b.key.data(), len};
}

std::span<const u8> dataset::value(u32 i, source_buffers& b) const {
    const auto len = records[i].value_len;
    fill_words(value_seed(i), b.value.data(), len);
    return {b.value.data(), len};
}

dataset build_dataset() {
    dataset d;
    d.records.resize(universe_size);
    splitmix64 rng{0x5eed5eedULL};
    for (u32 i = 0; i < universe_size; ++i) {
        // Keys uniform in [16, 64]; values log-uniform in [8, 512] (most
        // values small, a long tail of larger ones; mean ~120 bytes).
        auto& r = d.records[i];
        r.key_len = static_cast<u8>(min_key_size +
                                    rng.below(max_key_size - min_key_size + 1));
        const double v =
            static_cast<double>(min_value_size) *
            std::exp(rng.unit() * std::log(static_cast<double>(max_value_size) /
                                           min_value_size));
        r.value_len = static_cast<u16>(
            std::min<u32>(max_value_size, static_cast<u32>(v)));
    }
    source_buffers buf;
    for (u32 i = 0; i < universe_size; ++i) {
        d.records[i].hash = hash_bytes(d.key(i, buf));
    }
    return d;
}

// ---------------------------------------------------------------------------
// Reporting.

struct rep_stats {
    double median_mops;
    double min_mops;
    double max_mops;
    double mad_pct;
};

double median_of(std::vector<double> v) {
    std::ranges::sort(v);
    const usize n = v.size();
    if (n == 0) {
        return 0;
    }
    return n % 2 == 1 ? v[n / 2] : (v[n / 2 - 1] + v[n / 2]) / 2;
}

rep_stats stats_of(const scenario_result& r) {
    std::vector<double> mops;
    for (const double ns : r.wall_ns) {
        mops.push_back(static_cast<double>(r.ops_per_rep) * 1e3 / ns);
    }
    const double med = median_of(mops);
    std::vector<double> dev;
    for (const double m : mops) {
        dev.push_back(std::abs(m - med));
    }
    return {
        .median_mops = med,
        .min_mops = mops.empty() ? 0 : *std::ranges::min_element(mops),
        .max_mops = mops.empty() ? 0 : *std::ranges::max_element(mops),
        .mad_pct = med > 0 ? 100 * median_of(dev) / med : 0,
    };
}

double total_wall_s(const scenario_result& r) {
    return std::accumulate(r.wall_ns.begin(), r.wall_ns.end(), 0.0) / 1e9;
}

u64 measured_ops(const scenario_result& r) {
    return r.ops_per_rep * r.wall_ns.size();
}

double ratio(u64 a, u64 b) {
    return b == 0 ? 0 : static_cast<double>(a) / static_cast<double>(b);
}

std::string keys_label(u32 keys) {
    if (keys % (u32{1} << 20) == 0) {
        return std::to_string(keys >> 20) + "Mi";
    }
    if (keys % (u32{1} << 10) == 0) {
        return std::to_string(keys >> 10) + "Ki";
    }
    return std::to_string(keys);
}

std::string phase_name(std::string_view group, u32 keys, double skew,
                       u32 threads) {
    std::string name{group};
    name += "/" + keys_label(keys);
    if (skew > 0) {
        char buf[16];
        std::snprintf(buf, sizeof buf, "/s%.1f", skew);
        name += buf;
    }
    name += "/t" + std::to_string(threads);
    return name;
}

bool selected(const options& opt, std::string_view name) {
    if (opt.filters.empty()) {
        return true;
    }
    return std::ranges::any_of(opt.filters, [&](const std::string& f) {
        return name.find(f) != std::string_view::npos;
    });
}

void print_scenarios(const options& opt) {
    std::vector<std::string> names;
    for (const u32 k : mixed_key_counts) {
        for (const double s : skews) {
            for (const u32 t : opt.threads) {
                names.push_back(phase_name("mixed", k, s, t));
            }
        }
    }
    for (const double s : skews) {
        for (const u32 t : opt.threads) {
            names.push_back(phase_name("lookup_hit", lookup_hit_keys, s, t));
        }
    }
    for (const u32 t : opt.threads) {
        names.push_back(
            phase_name("lookup_miss", lookup_miss_keys, miss_skew, t));
    }
    for (const u32 t : opt.threads) {
        names.push_back(phase_name("insert_new", universe_size, 0, t));
    }
    for (const auto& n : names) {
        if (selected(opt, n)) {
            std::printf("%s\n", n.c_str());
        }
    }
}

void print_header(const options& opt, const dataset& data) {
    double kmean = 0;
    double vmean = 0;
    for (const auto& r : data.records) {
        kmean += r.key_len;
        vmean += r.value_len;
    }
    kmean /= static_cast<double>(data.records.size());
    vmean /= static_cast<double>(data.records.size());
    std::printf(
        "sgc_bench: buckets=%u max_occupancy=%u participants=%u "
        "max_key=%u max_value=%u record_area=%u B/arena mapping=%.1f MiB "
        "backend=c-api\n",
        bucket_count, max_occupancy, bench_config.participant_capacity,
        max_key_size, max_value_size, bench_config.record_area_size,
        static_cast<double>(bench_mapping_size()) / (1 << 20));
    std::printf(
        "data: %u keys, key bytes mean %.1f [%u,%u], value bytes mean %.1f "
        "[%u,%u]\n",
        universe_size, kmean, min_key_size, max_key_size, vmean, min_value_size,
        max_value_size);
    std::printf("run: reps=%u ops/thread/rep=%" PRIu64 " warmup=%" PRIu64
                " model=%s verify=%s check_pinning=%s huge_pages=%s\n\n",
                opt.reps, opt.ops_per_thread, opt.warmup_ops,
                opt.model ? "on" : "off", opt.verify ? "on" : "off",
                opt.check_pinning ? "on" : "off",
                opt.huge_pages ? "on" : "off");
}

void print_table(const std::vector<scenario_result>& results) {
    std::printf("%-26s %8s %17s %5s %8s %6s %6s %8s %7s %8s %6s %6s %6s\n",
                "scenario", "Mops/s", "[min - max]", "MAD%", "ns/op/t", "hit%",
                "ins%", "Mins/s", "rot/rep", "rot/s", "~hit%", "~prm%", "errs");
    for (const auto& r : results) {
        const auto st = stats_of(r);
        const auto& t = r.totals;
        const u64 ops = measured_ops(r);
        const double wall = total_wall_s(r);
        const double ns_per_op_thread =
            st.median_mops > 0 ? 1e3 * r.threads / st.median_mops : 0;
        char rot_rep[16] = "-";
        char rot[16] = "-";
        char mhit[16] = "-";
        char mprom[16] = "-";
        if (r.model) {
            std::snprintf(rot_rep, sizeof rot_rep, "%.1f",
                          static_cast<double>(r.model->rotations) /
                              static_cast<double>(r.wall_ns.size()));
            std::snprintf(rot, sizeof rot, "%.1f",
                          static_cast<double>(r.model->rotations) / wall);
            std::snprintf(mhit, sizeof mhit, "%.2f",
                          100 * ratio(r.model->hits, r.model->ops));
            std::snprintf(mprom, sizeof mprom, "%.2f",
                          100 * ratio(r.model->promotions, r.model->ops));
        }
        std::printf(
            "%-26s %8.2f %8.2f - %6.2f %5.1f %8.1f %6.2f %6.2f %8.3f %7s %8s "
            "%6s %6s %6" PRIu64 "\n",
            r.name.c_str(), st.median_mops, st.min_mops, st.max_mops,
            st.mad_pct, ns_per_op_thread,
            100 * ratio(t.hits, t.hits + t.misses), 100 * ratio(t.inserts, ops),
            static_cast<double>(t.inserts) / wall / 1e6, rot_rep, rot, mhit,
            mprom, t.lookup_errors + t.insert_errors);
    }
    std::printf(
        "\nMops/s: aggregate over threads, median of reps. ns/op/t: per "
        "thread at the median.\nhit%%/ins%%: measured over all reps. rot/rep "
        "/rot/s/~hit%%/~prm%% (promotions): reference model replay\n(exact for "
        "t1, "
        "round-robin interleaving otherwise); compare ~hit%% with hit%%.\n");
}

bool write_json(const std::string& path, const options& opt,
                const std::vector<scenario_result>& results) {
    std::FILE* f = std::fopen(path.c_str(), "w");
    if (f == nullptr) {
        std::perror(path.c_str());
        return false;
    }
    std::fprintf(f,
                 "{\"config\":{\"bucket_count\":%u,\"max_occupancy\":%u,"
                 "\"max_key_size\":%u,\"max_value_size\":%u,"
                 "\"participant_capacity\":%u,\"universe\":%u,\"reps\":%u,"
                 "\"ops_per_thread\":%" PRIu64 ",\"warmup_ops\":%" PRIu64
                 ",\"check_pinning\":%s,\"huge_pages\":%s,"
                 "\"thp_min_coverage\":%.4f},\n\"results\":[",
                 bucket_count, max_occupancy, max_key_size, max_value_size,
                 bench_config.participant_capacity, universe_size, opt.reps,
                 opt.ops_per_thread, opt.warmup_ops,
                 opt.check_pinning ? "true" : "false",
                 opt.huge_pages ? "true" : "false",
                 opt.huge_pages ? thp_min_coverage : 0.0);
    bool first = true;
    for (const auto& r : results) {
        const auto st = stats_of(r);
        const auto& t = r.totals;
        const u64 ops = measured_ops(r);
        const double wall = total_wall_s(r);
        std::fprintf(f, "%s\n{\"name\":\"%s\",\"group\":\"%s\",\"keys\":%u,",
                     first ? "" : ",", r.name.c_str(), r.group.c_str(), r.keys);
        first = false;
        std::fprintf(f,
                     "\"skew\":%.2f,\"threads\":%u,\"ops_per_rep\":%" PRIu64
                     ",\"median_mops\":%.4f,\"min_mops\":%.4f,"
                     "\"max_mops\":%.4f,\"mad_pct\":%.3f,"
                     "\"ns_per_op\":%.3f,\"ns_per_op_thread\":%.3f,",
                     r.skew, r.threads, r.ops_per_rep, st.median_mops,
                     st.min_mops, st.max_mops, st.mad_pct, 1e3 / st.median_mops,
                     1e3 * r.threads / st.median_mops);
        std::fprintf(f, "\"rep_mops\":[");
        for (usize i = 0; i < r.wall_ns.size(); ++i) {
            std::fprintf(
                f, "%s%.4f", i == 0 ? "" : ",",
                static_cast<double>(r.ops_per_rep) * 1e3 / r.wall_ns[i]);
        }
        std::fprintf(f,
                     "],\"hit_rate\":%.6f,\"insert_rate\":%.6f,"
                     "\"inserts_per_s\":%.1f,\"lookup_errors\":%" PRIu64
                     ",\"insert_errors\":%" PRIu64,
                     ratio(t.hits, t.hits + t.misses), ratio(t.inserts, ops),
                     static_cast<double>(t.inserts) / wall, t.lookup_errors,
                     t.insert_errors);
        if (r.model) {
            const auto& m = *r.model;
            std::fprintf(
                f,
                ",\"model\":{\"hit_rate\":%.6f,\"promotion_rate\":%.6f,"
                "\"rotations\":%" PRIu64
                ",\"rotations_per_rep\":%.3f,\"rotations_per_s\":%.3f,"
                "\"replacements\":%" PRIu64 "}",
                ratio(m.hits, m.ops), ratio(m.promotions, m.ops), m.rotations,
                static_cast<double>(m.rotations) /
                    static_cast<double>(r.wall_ns.size()),
                static_cast<double>(m.rotations) / wall, m.replacements);
        }
        std::fprintf(f, "}");
    }
    std::fprintf(f, "\n]}\n");
    return std::fclose(f) == 0;
}

// ---------------------------------------------------------------------------
// Command line.

bool parse_u64(std::string_view s, u64& out) {
    if (s.empty()) {
        return false;
    }
    u64 v = 0;
    for (const char ch : s) {
        if (ch < '0' || ch > '9') {
            return false;
        }
        v = v * 10 + static_cast<u64>(ch - '0');
    }
    out = v;
    return true;
}

std::vector<std::string> split_commas(std::string_view s) {
    std::vector<std::string> out;
    while (!s.empty()) {
        const auto comma = s.find(',');
        out.emplace_back(s.substr(0, comma));
        if (comma == std::string_view::npos) {
            break;
        }
        s.remove_prefix(comma + 1);
    }
    return out;
}

void usage() {
    std::fprintf(
        stderr,
        "usage: sgc_bench [--quick] [--reps N] [--ops N] [--warmup N]\n"
        "                 [--threads 1,4,8] [--filter SUBSTR[,SUBSTR...]]\n"
        "                 [--json FILE] [--no-model] [--verify] [--list]\n"
        "                 [--pin] [--check-pinning]\n"
        "                 [--huge-pages|--no-huge-pages]\n"
        "  --ops     operations per thread per repetition (default 250000)\n"
        "  --warmup  untimed operations before a family's first phase\n"
        "  --quick   --reps 5 --ops 100000 (same warmup)\n"
        "  --verify  compare every hit's bytes (slower; not for timing)\n"
        "  --check-pinning  (Apple silicon) require stable, distinct worker "
        "CPUs\n"
        "  --pin     (Linux) bind worker t to the t-th CPU of the affinity "
        "mask\n"
        "  --huge-pages  (Linux) map the cache as private anonymous memory\n"
        "            advised MADV_HUGEPAGE (the Linux default)\n"
        "  --no-huge-pages  use a shared mapping without requesting THP\n");
}

bool parse_options(int argc, char** argv, options& opt) {
    for (int i = 1; i < argc; ++i) {
        const std::string_view arg = argv[i];
        auto value = [&](std::string_view& out) {
            if (i + 1 >= argc) {
                return false;
            }
            out = argv[++i];
            return true;
        };
        std::string_view v;
        u64 n = 0;
        if (arg == "--quick") {
            opt.reps = 5;
            opt.ops_per_thread = 100'000;
            opt.warmup_ops = 1'000'000;
        } else if (arg == "--reps" && value(v) && parse_u64(v, n) && n >= 1) {
            opt.reps = static_cast<u32>(n);
        } else if (arg == "--ops" && value(v) && parse_u64(v, n) && n >= 1) {
            opt.ops_per_thread = n;
        } else if (arg == "--warmup" && value(v) && parse_u64(v, n)) {
            opt.warmup_ops = n;
        } else if (arg == "--threads" && value(v)) {
            opt.threads.clear();
            for (const auto& t : split_commas(v)) {
                if (!parse_u64(t, n) || n == 0 || n > 64) {
                    usage();
                    return false;
                }
                opt.threads.push_back(static_cast<u32>(n));
            }
        } else if (arg == "--filter" && value(v)) {
            opt.filters = split_commas(v);
        } else if (arg == "--json" && value(v)) {
            opt.json_path = v;
        } else if (arg == "--no-model") {
            opt.model = false;
        } else if (arg == "--verify") {
            opt.verify = true;
        } else if (arg == "--check-pinning") {
#if defined(__APPLE__) && defined(__aarch64__)
            opt.check_pinning = true;
#else
            std::fprintf(stderr, "--check-pinning requires Apple silicon\n");
            return false;
#endif
        } else if (arg == "--list") {
            opt.list = true;
        } else if (arg == "--pin") {
            opt.pin = true;
        } else if (arg == "--huge-pages") {
#if defined(__linux__)
            opt.huge_pages = true;
#else
            std::fprintf(stderr,
                         "--huge-pages: unsupported here; using the "
                         "shared mapping\n");
#endif
        } else if (arg == "--no-huge-pages") {
            opt.huge_pages = false;
        } else {
            usage();
            return false;
        }
    }
    return true;
}

}  // namespace
