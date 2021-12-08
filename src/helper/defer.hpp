// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <utility>

namespace dds {

template <typename T> struct defer {
    explicit defer(T &&r_) : runnable(std::move(r_)) {}
    defer(const defer &) = delete;
    defer &operator=(const defer &) = delete;
    defer(defer &&) = delete;
    defer &operator=(defer &&) = delete;
    ~defer() { runnable(); }
    T runnable;
};

} // namespace dds
