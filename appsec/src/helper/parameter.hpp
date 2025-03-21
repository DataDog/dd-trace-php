// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <string>
#include <string_view>

#include "parameter_base.hpp"

namespace dds {

class parameter : public parameter_base {
public:
    parameter() = default;
    explicit parameter(const ddwaf_object &arg);

    template <typename T,
        typename = std::enable_if_t<std::conjunction_v<
            std::is_base_of<ddwaf_object, std::remove_cv_t<std::decay_t<T>>>,
            std::negation<std::is_same<ddwaf_object,
                std::remove_cv_t<std::decay_t<T>>>>>>>
    parameter(T &&t) = delete;

    parameter(const parameter &) = delete;
    parameter &operator=(const parameter &) = delete;

    parameter(parameter &&) noexcept;
    parameter &operator=(parameter &&) noexcept;

    ~parameter() { ddwaf_object_free(this); }

    static parameter map() noexcept;
    static parameter array() noexcept;
    static parameter uint64(uint64_t value) noexcept;
    static parameter int64(int64_t value) noexcept;
    static parameter string(const std::string &str) noexcept;
    static parameter string(std::string_view str) noexcept;
    static parameter string(uint64_t value) noexcept;
    static parameter string(int64_t value) noexcept;
    static parameter as_boolean(bool value) noexcept;
    static parameter float64(float value) noexcept;
    static parameter null() noexcept;

    bool add(parameter &&entry) noexcept;
    bool add(std::string_view name, parameter &&entry) noexcept;
    bool merge(parameter other);

    // The reference should be considered invalid after adding an element
    parameter &operator[](size_t index) const;
};

} // namespace dds
