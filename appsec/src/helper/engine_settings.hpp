// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#pragma once

#include "utils.hpp"
#include <cstdint>
#include <msgpack.hpp>
#include <ostream>
#include <string>

namespace dds {

struct schema_extraction_settings {
    static constexpr double default_sample_rate = 0.1; // 10% of requests
    static constexpr bool default_enabled = false;

    bool enabled = default_enabled;
    double sample_rate = default_sample_rate;

    MSGPACK_DEFINE_MAP(enabled, sample_rate);
};

/* engine_settings are currently the same for the whole client session.
 * If this changes in the future, it will make sense to create a separation
 * between 1) settings used for creating the engine and 2) settings used after,
 * possibly when creating the subscriber listeners on every request */
struct engine_settings {
    static constexpr int default_waf_timeout_us = 10000;
    static constexpr int default_trace_rate_limit = 100;

    std::string rules_file;
    std::uint64_t waf_timeout_us = default_waf_timeout_us;
    std::uint32_t trace_rate_limit = default_trace_rate_limit;
    std::string obfuscator_key_regex;
    std::string obfuscator_value_regex;
    schema_extraction_settings schema_extraction;

    static const std::string &default_rules_file();

    [[nodiscard]] const std::string &rules_file_or_default() const
    {
        if (rules_file.empty()) {
            return default_rules_file();
        }

        return rules_file;
    }

    MSGPACK_DEFINE_MAP(rules_file, waf_timeout_us, trace_rate_limit,
        obfuscator_key_regex, obfuscator_value_regex, schema_extraction);

    bool operator==(const engine_settings &oth) const noexcept
    {
        return rules_file == oth.rules_file &&
               waf_timeout_us == oth.waf_timeout_us &&
               trace_rate_limit == oth.trace_rate_limit &&
               obfuscator_key_regex == oth.obfuscator_key_regex &&
               obfuscator_value_regex == oth.obfuscator_value_regex &&
               schema_extraction.enabled == oth.schema_extraction.enabled &&
               schema_extraction.sample_rate ==
                   oth.schema_extraction.sample_rate;
    }

    friend auto &operator<<(std::ostream &os, const engine_settings &c)
    {
        return os << "{rules_file=" << c.rules_file
                  << ", waf_timeout_us=" << c.waf_timeout_us
                  << ", trace_rate_limit=" << c.trace_rate_limit
                  << ", obfuscator_key_regex=" << c.obfuscator_key_regex
                  << ", obfuscator_value_regex=" << c.obfuscator_value_regex
                  << ", schema_extraction.enabled="
                  << c.schema_extraction.enabled
                  << ", schema_extraction.sample_rate=" << std::fixed
                  << c.schema_extraction.sample_rate << "}";
    }

    struct settings_hash {
        std::size_t operator()(const engine_settings &s) const noexcept
        {
            return hash(s.rules_file, s.waf_timeout_us, s.trace_rate_limit,
                s.obfuscator_key_regex, s.obfuscator_value_regex,
                s.schema_extraction.enabled, s.schema_extraction.sample_rate);
        }
    };
};
} // namespace dds
