#include <ddprof/exporter.hh>
#include <cstddef>
#include <cstdio>
#include <cstring>

extern "C" {
#include <alloca.h>
#include <datadog/arena.h>
#include "profile.pb-c.h"
}

#include <datadog/memhash.hh>

using namespace std::chrono;

namespace ddprof {

namespace {

bool memeq(std::size_t size, const char *lhs, const char *rhs) noexcept {
    return memcmp(lhs, rhs, size) == 0;
}

void pprof_write(PProf__Profile &profile, FILE *stream, datadog_arena **arena) noexcept {
    auto len = pprof__profile__get_packed_size(&profile);
    auto checkpoint = datadog_arena_checkpoint(*arena);
    auto buf = (uint8_t *) datadog_arena_alloc(arena, len * sizeof(uint8_t));
    if (buf) {
        pprof__profile__pack(&profile, buf);
        fwrite(buf, len, 1, stream);
        datadog_arena_restore(arena, checkpoint);
    }
}

struct pprof_eq {
    bool operator()(const PProf__Function *lhs, const PProf__Function *rhs) const noexcept {
        size_t offset = offsetof(PProf__Function, name);
        auto addr_lhs = ((const char *) lhs) + offset;
        auto addr_rhs = ((const char *) rhs) + offset;
        size_t size = sizeof(PProf__Function) - offset;

        return memeq(size, addr_lhs, addr_rhs);
    }

    bool operator()(const PProf__Line *lhs, const PProf__Line *rhs) const noexcept {
        size_t offset = offsetof(PProf__Line, function_id);
        auto addr_lhs = ((const char *) lhs) + offset;
        auto addr_rhs = ((const char *) rhs) + offset;
        size_t size = sizeof(PProf__Function) - offset;

        return memeq(size, addr_lhs, addr_rhs);
    }

    bool operator()(const PProf__Location *lhs, const PProf__Location *rhs) const noexcept {
        if (lhs->mapping_id != rhs->mapping_id || lhs->address != rhs->address || lhs->n_line != rhs->n_line) {
            return false;
        }
        for (size_t i = 0; i != lhs->n_line; ++i) {
            if (!operator()(lhs->line[i], rhs->line[i])) {
                return false;
            }
        }
        return true;
    }
};

struct pprof_hash {
    size_t operator()(const PProf__Function *func) const noexcept {
        size_t offset = offsetof(PProf__Function, name);
        size_t size = sizeof(PProf__Function) - offset;

        return datadog::memhash(size, ((const char *) func) + offset);
    }

    size_t operator()(const PProf__Line *line) const noexcept {
        size_t offset = offsetof(PProf__Line, function_id);
        size_t size = sizeof(PProf__Line) - offset;

        return datadog::memhash(size, ((const char *) line) + offset);
    }

    size_t operator()(const PProf__Location *location) const noexcept {
        /* The address in location.line is not de-duplicated, so we pack the
         * contents of the lines. It should be small (atm exactly 1), so we
         * stack-allocate it.
         */
        size_t size = sizeof(location->mapping_id) + sizeof(location->address) + sizeof(location->n_line)
            + sizeof(PProf__Line) * location->n_line;

        auto bytes = (char *) alloca(size);
        size_t offset = 0;
        memcpy(bytes + offset, &location->mapping_id, sizeof location->mapping_id);
        offset += sizeof location->mapping_id;

        memcpy(bytes + offset, &location->address, sizeof location->address);
        offset += sizeof location->address;

        memcpy(bytes + offset, &location->n_line, sizeof location->n_line);
        offset += sizeof location->n_line;

        for (unsigned i = 0; i != location->n_line; ++i) {
            memcpy(bytes + offset, location->line[i], sizeof(PProf__Line));
            offset += sizeof(PProf__Line);
        }

        return datadog::memhash(size, bytes);
    }
};

/**
 * A pprof_set stores individual objects in a datadog_arena. Addresses of those
 * objects are stored in both a map and vector; the map translates from object
 * to offset into the vector.
 * @tparam T
 */
template<class T>
class pprof_set {
    using offset_t = uint64_t;
    datadog_arena **arena; // the set does _not_ own the arena
    std::unordered_map<T *, offset_t, pprof_hash, pprof_eq> map;
    std::vector<T *> vector;
    uint64_t id;

  public:
    explicit pprof_set(datadog_arena **a) : arena{a}, id{0} {}
    offset_t insert(T &elem) {
        auto iterator = map.find(&elem);
        if (iterator == map.end()) {
            auto size = (offset_t) vector.size();
            auto inserted = (T *) datadog_arena_alloc(arena, sizeof(T));
            new(inserted) T(elem);
            inserted->id = id++;

            map[inserted] = size;
            vector.emplace_back(inserted);
            return size;
        } else {
            return iterator->second;
        }
    }

    size_t size() noexcept { return vector.size(); }
    T &operator[](size_t offset) noexcept { return *vector[offset]; }
    T **data() noexcept { return vector.data(); }
};

uint64_t add_function(datadog_arena **arena, pprof_set<PProf__Function> &functions, uint64_t function_name) noexcept {
    PProf__Function function = PPROF__FUNCTION__INIT;
    function.name = function_name;
    return functions.insert(function);
}

PProf__Line *add_line(datadog_arena **arena, uint64_t function_id, int64_t lineno) noexcept {
    PProf__Line tmpline = PPROF__LINE__INIT;
    tmpline.function_id = function_id;
    tmpline.line = lineno;

    auto line = (PProf__Line *) datadog_arena_alloc(arena, sizeof(PProf__Line));
    new(line) PProf__Line(tmpline);
    return line;
}

}

void pprof_exporter::operator()(const recorder::event_table_t &event_table, string_table &strings,
                                system_clock::time_point start_time, system_clock::time_point stop_time) {
    if (event_table.empty()) {
        return;
    }

    datadog_arena *arena = datadog_arena_create(4096);
    pprof_set<PProf__Function> functions{&arena};
    pprof_set<PProf__Location> locations{&arena};

    // wall-time
    // "thread id" "thread native id" as labels

    std::stringstream ss{};
    ss << "profile-" << num++ << ".pb";

    for (auto &pair : event_table) {
        if (pair.first == event::type::stack_event) {
            auto &events_vector = pair.second;
            for (auto &event : events_vector) {
                if (event->type == event::type::stack_event) {
                    PProf__Sample sample = PPROF__SAMPLE__INIT;
                    sample.n_location_id = event->stack.frames.size();
                    sample.location_id =
                        (uint64_t *) datadog_arena_alloc(&arena, sizeof(uint64_t) * sample.n_location_id);
                    unsigned local_locations = 0;

                    for (auto &frame : event->stack.frames) {
                        uint64_t function_id = add_function(&arena, functions, frame.function_name);
                        PProf__Line *line = add_line(&arena, function_id, frame.lineno);

                        PProf__Location location = PPROF__LOCATION__INIT;
                        location.line = &line;
                        location.n_line = 1;
                        uint64_t location_offset = locations.insert(location);

                        sample.location_id[local_locations++] = locations[location_offset].id;
                        sample.value = (int64_t *) datadog_arena_alloc(&arena, sizeof(int64_t));
                        *sample.value = 0; // todo: this is nanoseconds since... what?
                    }
                }
            }
        }
    }

    auto &php = strings.intern({sizeof "php" - 1, "php"});

    auto file = fopen(ss.str().c_str(), "w");

    PProf__Profile profile = PPROF__PROFILE__INIT;

    profile.n_string_table = strings.size();
    // Unsafe! Do not modify the table!
    profile.string_table = const_cast<char **>(strings.data());

    // todo: what to convey in mapping name? root script? SAPI?
    PProf__Mapping mapping = PPROF__MAPPING__INIT;
    mapping.id = 0;
    mapping.filename = php.offset;

    std::vector<PProf__Mapping *> mappings{1u};
    mappings.emplace_back(&mapping);
    profile.mapping = mappings.data();

    // todo: pass in start time
    profile.time_nanos = duration_cast<nanoseconds>(ddprof::system_clock::now().time_since_epoch()).count();
    profile.duration_nanos = duration_cast<nanoseconds>((stop_time - start_time)).count();

    pprof_write(profile, file, &arena);

    datadog_arena_destroy(arena);
}
}  // namespace ddprof