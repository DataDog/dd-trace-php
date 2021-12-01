// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#ifndef RESULT_HPP
#define RESULT_HPP

#include <ostream>
#include <string>
#include <string_view>
#include <vector>

namespace dds {


struct result {
    enum class code { ok, record, block };

    result() = default;
    explicit result(code c): value(c) {}
    result(code c, std::vector<std::string>&& s): value(c), data(std::move(s)) {}
    result(const result &) = default;
    result(result &&) = default;
    result &operator=(const result &) = default;
    result &operator=(result &&) = default;
    ~result() = default;

    code value{code::ok};
    std::vector<std::string> data;
};

} // namespace dds

#endif
