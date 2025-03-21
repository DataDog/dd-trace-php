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
#include <type_traits>
#include <utility>

namespace dds {

template <typename... Args> inline constexpr std::size_t hash(Args... args)
{
    return (... ^ std::hash<std::remove_cv_t<Args>>{}(args));
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

inline std::string dd_tolower(std::string string)
{
    for (auto &c : string) {
        if (c > 'A' && c < 'Z') {
            c += ('a' - 'A');
        }
    }

    return string;
}

std::string read_file(std::string_view filename);

#ifdef __linux__
extern "C" int __xpg_strerror_r(int, char *, size_t);
#endif
inline std::string strerror_ts(int errnum)
{
    std::string buf(256, '\0'); // NOLINT

#ifdef __linux__
    (void)__xpg_strerror_r(errnum, buf.data(), buf.size());
#else
    (void)strerror_r(errnum, buf.data(), buf.size());
#endif

    buf.resize(std::strlen(buf.data()));

    return buf;
}

} // namespace dds
