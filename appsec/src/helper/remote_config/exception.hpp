// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <string>

namespace dds::remote_config {

class error_applying_config : public std::exception {
public:
    explicit error_applying_config(std::string &&msg) : message(std::move(msg))
    {}
    [[nodiscard]] const char *what() const noexcept override
    {
        return message.c_str();
    }

protected:
    std::string message;
};

class invalid_path : public std::exception {};

} // namespace dds::remote_config
