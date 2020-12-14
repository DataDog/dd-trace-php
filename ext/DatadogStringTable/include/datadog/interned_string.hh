#ifndef DATADOG_INTERNED_STRING_HH
#define DATADOG_INTERNED_STRING_HH

#include <cstddef>

namespace datadog {

struct interned_string {
    std::size_t hash;
    std::size_t offset;
    std::size_t size;
    char data[1];  // struct hack, ahoy!

    // not copyable nor move-able due to struct hack
    interned_string(const interned_string &) = delete;
    interned_string(interned_string &&) = delete;
    interned_string &operator=(const interned_string &) = delete;
    interned_string &operator=(interned_string &&) = delete;
};

// interned_strings compare by address, not contents
constexpr bool operator==(interned_string &lhs, interned_string &rhs) { return &lhs == &rhs; }

}  // namespace datadog

namespace std {

template <>
class hash<datadog::interned_string> {
    constexpr std::size_t operator()(const datadog::interned_string &string) const noexcept { return string.hash; }
};

}  // namespace std

#endif  // DATADOG_INTERNED_STRING_HH
