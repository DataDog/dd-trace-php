// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.

namespace dds {

template <typename T>
struct defer {
    defer(T &&r_): runnable(std::move(r_)) {}
    ~defer() { runnable(); }
    T runnable;
};

} // namespace dds
