// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include <fstream>
#include <ios>
#include <rapidjson/error/en.h>

#include "engine_ruleset.hpp"
#include "exception.hpp"

namespace dds {
namespace {
std::string read_rule_file(std::string_view filename)
{
    std::ifstream rule_file(filename.data(), std::ios::in);
    if (!rule_file) {
        throw std::system_error(errno, std::generic_category());
    }

    // Create a buffer equal to the file size
    rule_file.seekg(0, std::ios::end);
    std::string buffer(rule_file.tellg(), '\0');
    buffer.resize(rule_file.tellg());
    rule_file.seekg(0, std::ios::beg);

    auto buffer_size = buffer.size();
    if (buffer_size > static_cast<typeof(buffer_size)>(
                          std::numeric_limits<std::streamsize>::max())) {
        throw std::runtime_error{"rule file is too large"};
    }

    rule_file.read(buffer.data(), static_cast<std::streamsize>(buffer.size()));
    buffer.resize(rule_file.gcount());
    rule_file.close();
    return buffer;
}
} // namespace

engine_ruleset::engine_ruleset(std::string_view ruleset)
{
    rapidjson::ParseResult result = doc_.Parse(ruleset.data());
    if ((result == nullptr) || !doc_.IsObject()) {
        throw parsing_error("invalid json rule");
    }
}

engine_ruleset engine_ruleset::from_path(std::string_view path)
{
    auto ruleset = read_rule_file(path);
    return engine_ruleset{ruleset};
}

} // namespace dds
