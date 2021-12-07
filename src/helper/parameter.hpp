// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef PARAMETER_HPP
#define PARAMETER_HPP

#include <ddwaf.h>
#include <limits>
#include <string>
#include <string_view>

namespace dds {

class parameter : public ddwaf_object {
  public:
    parameter();
    explicit parameter(const ddwaf_object &arg);

    // FIXME: Copy and move semantics are unclear, perhaps copy should be
    //        deleted and move reimplemented to invalidate source parameter.
    parameter(const parameter &) = delete;            // fault;
    parameter &operator=(const parameter &) = delete; // fault;

    parameter(parameter &&) noexcept;
    parameter &operator=(parameter &&) noexcept;

    explicit parameter(uint64_t value);
    explicit parameter(int64_t value);
    explicit parameter(const std::string &str);
    explicit parameter(std::string_view str);

    // These will be freed by the WAF, if the parameters are not passed to the
    // WAF, expect a memory leak if "free" is not called.
    ~parameter() = default;
    void free() noexcept;
    void invalidate() noexcept { ddwaf_object_invalid(this); }

    static parameter map() noexcept;
    static parameter array() noexcept;

    // NOLINTNEXTLINE(google-runtime-references)
    bool add(parameter &entry) noexcept;
    // NOLINTNEXTLINE(google-runtime-references)
    bool add(parameter &&entry) noexcept;
    // NOLINTNEXTLINE(google-runtime-references)
    bool add(std::string_view name, parameter &entry) noexcept;
    // NOLINTNEXTLINE(google-runtime-references)
    bool add(std::string_view name, parameter &&entry) noexcept;

    // Container size
    [[nodiscard]] size_t size() const noexcept
    {
        return static_cast<size_t>(nbEntries);
    }
    parameter operator[](size_t index) const noexcept;

    explicit operator std::string_view() const noexcept;

    [[nodiscard]] std::string_view key() const noexcept
    {
        return {parameterName, parameterNameLength};
    };
    [[nodiscard]] bool is_map() const noexcept { return type == DDWAF_OBJ_MAP; }
    [[nodiscard]] bool is_container() const noexcept
    {
        return (type & (DDWAF_OBJ_MAP | DDWAF_OBJ_ARRAY)) != 0;
    }

    ddwaf_object *ptr() noexcept;

    [[nodiscard]] std::string debug_str() const noexcept;

    using length_type = decltype(nbEntries);
    static constexpr auto max_length{
        std::numeric_limits<length_type>::max() - 1};
};

} // namespace dds

#endif
