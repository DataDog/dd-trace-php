#ifndef DATADOG_STRING_TABLE_HH
#define DATADOG_STRING_TABLE_HH

#include <cassert>
#include <cstddef>
#include <cstring>
#include <functional>
#include <unordered_map>
#include <vector>

extern "C" {
#include <datadog/arena.h>
}

#include "hashed_string.hh"
#include "interned_string.hh"

namespace datadog {

class string_table {
    datadog_arena **arena;  // does not own the arena
    std::unordered_map<hashed_string, std::size_t> map;
    std::vector<const char *> vector;

    public:
    // string tables aren't copyable
    string_table(const string_table &) = delete;
    string_table &operator=(const string_table &) = delete;

    // they are move-able
    string_table(string_table &&) noexcept;
    string_table &operator=(string_table &&) noexcept;

    explicit string_table(datadog_arena **arena) noexcept;

    ~string_table();

    interned_string &intern(hashed_string);
    interned_string &intern(interned_string &);

    std::size_t size() const noexcept;

    interned_string &operator[](std::size_t offset) noexcept;
    const interned_string &operator[](std::size_t offset) const noexcept;

    void swap(string_table &other) noexcept;

    inline const char *const *data() const noexcept { return vector.data(); }
};

inline string_table::string_table(string_table &&) noexcept = default;
inline string_table &string_table::operator=(string_table &&) noexcept = default;

inline string_table::string_table(datadog_arena **a) noexcept : arena{a}, map{}, vector{} {
    // the empty string is _always_ offset 0
    auto &interned = intern({0, nullptr});
    assert(interned.offset == 0);
}

inline interned_string &string_table::intern(interned_string &interned) {
    hashed_string hashed{interned.hash, interned.size, &interned.data[0]};
    return intern(hashed);
}

inline string_table::~string_table() = default;

inline std::size_t string_table::size() const noexcept {
    assert(vector.size() == map.size());
    return vector.size();
}

inline interned_string &string_table::operator[](std::size_t offset) noexcept {
    assert(offset < size());
    char *addr = const_cast<char *>(vector[offset] - offsetof(interned_string, data));
    return *reinterpret_cast<interned_string *>(addr);
}

inline const interned_string &string_table::operator[](std::size_t offset) const noexcept {
    assert(offset < size());
    const char *addr = vector[offset] - offsetof(interned_string, data);
    return *reinterpret_cast<const interned_string *>(addr);
}

inline void string_table::swap(string_table &other) noexcept {
    vector.swap(other.vector);
    map.swap(other.map);
}

}  // namespace datadog

#endif  // DATADOG_STRING_TABLE_HH
