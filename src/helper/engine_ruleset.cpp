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
#include "utils.hpp"

namespace dds {

engine_ruleset::engine_ruleset(std::string_view ruleset)
{
    rapidjson::ParseResult const result = doc_.Parse(ruleset.data());
    if ((result == nullptr) || !doc_.IsObject()) {
        throw parsing_error("invalid json rule");
    }
}

engine_ruleset engine_ruleset::from_path(std::string_view path)
{
    auto ruleset = read_file(path);
    return engine_ruleset{ruleset};
}

} // namespace dds
