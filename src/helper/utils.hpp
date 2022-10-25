// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <algorithm>
#include <boost/uuid/uuid.hpp>
#include <boost/uuid/uuid_generators.hpp>
#include <boost/uuid/uuid_io.hpp>
#include <utility>

namespace dds {

template <typename T, typename... Args>
inline constexpr std::size_t hash(T &value, Args... args)
{
    using non_const_t = typename std::remove_cv<T>::type;
    if constexpr (sizeof...(Args) == 0) {
        return std::hash<non_const_t>{}(value);
    } else {
        return std::hash<non_const_t>{}(value) ^ hash<Args...>(args...);
    }
}

template <typename T> struct defer {
    explicit defer(T &&r_) : runnable(std::move(r_)) {}
    defer(const defer &) = delete;
    defer &operator=(const defer &) = delete;
    defer(defer &&) = delete;
    defer &operator=(defer &&) = delete;
    ~defer() { runnable(); }
    T runnable;
};

inline std::string generate_random_uuid()
{
    return boost::uuids::to_string(boost::uuids::random_generator()());
}

} // namespace dds
