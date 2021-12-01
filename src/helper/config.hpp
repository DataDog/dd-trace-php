// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#ifndef CONFIG_HPP
#define CONFIG_HPP

#include <boost/lexical_cast.hpp>
#include <unordered_map>

namespace dds::config {
// TODO: Rename to ArgConfig or ArgParser?
//       Perhaps make this a "singleton"
class config {
  public:
    config() = default;
    config(int argc, char *argv[]); // NOLINT

    template <typename T> T get(std::string_view key) const {
        return boost::lexical_cast<T>(kv_.at(key));
    }

  protected:
    static const std::unordered_map<std::string_view, std::string_view>
        defaults;
    std::unordered_map<std::string_view, std::string_view> kv_{defaults};
};

template <> bool config::get<bool>(std::string_view key) const;

template <> std::string config::get<std::string>(std::string_view key) const;

template <>
std::string_view config::get<std::string_view>(std::string_view key) const;

} // namespace dds::config
#endif
