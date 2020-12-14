#ifndef DATADOG_HASHED_STRING_HH
#define DATADOG_HASHED_STRING_HH

#include <cstddef>
#include <cstring>
#include <functional>

namespace datadog {

class hashed_string {
    std::size_t hash_;
    std::size_t size_;
    const char *data_;

    public:
    constexpr hashed_string() noexcept : hash_{0}, size_{0}, data_{nullptr} {}

    /* Be responsible with the hashes passed in; they should be generated using
     * the same hash function as the other constructors.
     */
    constexpr hashed_string(std::size_t h, std::size_t s, const char *p) noexcept : hash_{h}, size_{s}, data_{p} {}

    hashed_string(std::size_t len, const char *ptr) noexcept;

    constexpr std::size_t hash() const noexcept { return hash_; }
    constexpr std::size_t size() const noexcept { return size_; }
    constexpr const char *data() const noexcept { return data_; }
};

constexpr bool operator==(const hashed_string &lhs, const hashed_string &rhs) noexcept {
    return lhs.size() == rhs.size() && (lhs.data() == rhs.data() || memcmp(lhs.data(), rhs.data(), lhs.size()) == 0);
}

}  // namespace datadog

namespace std {

template <>
struct hash<datadog::hashed_string> {
    constexpr std::size_t operator()(const datadog::hashed_string &hashed_string) const noexcept {
        return hashed_string.hash();
    }
};

}  // namespace std

#endif  // DATADOG_HASHED_STRING_HH
