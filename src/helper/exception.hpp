// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef WAF_EXCEPTION_HPP
#define WAF_EXCEPTION_HPP

#include <stdexcept>
#include <string>
#include <string_view>
#include <utility>

namespace dds {

using namespace std::literals;

class internal_error : public std::exception {
public:
    [[nodiscard]] const char *what() const noexcept override
    {
        return "internal error";
    }
};

class timeout_error : public std::exception {
public:
    [[nodiscard]] const char *what() const noexcept override
    {
        return "timeout error";
    }
};

class invalid_object : public std::exception {
public:
    invalid_object() : what_("invalid object") {}
    explicit invalid_object(std::string_view what) : what_(what) {}
    invalid_object(const std::string &key, const std::string &what)
        : what_("invalid object: key '" + key + "' " + what)
    {}
    [[nodiscard]] const char *what() const noexcept override
    {
        return what_.c_str();
    }

protected:
    std::string parent_;
    std::string what_;
};

class invalid_argument : public std::exception {
public:
    [[nodiscard]] const char *what() const noexcept override
    {
        return "invalid argument";
    }
};

class parsing_error : public std::exception {
public:
    explicit parsing_error(std::string what) : what_(std::move(what)) {}
    [[nodiscard]] const char *what() const noexcept override
    {
        return what_.c_str();
    }

protected:
    const std::string what_;
};

class bad_cast : public std::exception {
public:
    explicit bad_cast(std::string what) : what_(std::move(what)) {}
    [[nodiscard]] const char *what() const noexcept override
    {
        return what_.c_str();
    }

protected:
    const std::string what_;
};

} // namespace dds
#endif
