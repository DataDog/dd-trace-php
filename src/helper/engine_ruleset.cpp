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

void engine_ruleset::add_default_processors_and_scanners()
{
    std::string raw =
        R"({"processors":[{"id":"processor-001","generator":"extract_schema","conditions":[{"operator":"equals","parameters":{"inputs":[{"address":"waf.context.processor","key_path":["extract-schema"]}],"type":"boolean","value":true}}],"parameters":{"mappings":[{"inputs":[{"address":"server.request.body"}],"output":"_dd.appsec.s.req.body"},{"inputs":[{"address":"server.request.headers.no_cookies"}],"output":"_dd.appsec.s.req.headers"},{"inputs":[{"address":"server.request.query"}],"output":"_dd.appsec.s.req.query"},{"inputs":[{"address":"server.request.path_params"}],"output":"_dd.appsec.s.req.params"},{"inputs":[{"address":"server.request.cookies"}],"output":"_dd.appsec.s.req.cookies"},{"inputs":[{"address":"server.response.headers.no_cookies"}],"output":"_dd.appsec.s.res.headers"},{"inputs":[{"address":"server.response.body"}],"output":"_dd.appsec.s.res.body"}],"scanners":[{"tags":{"category":"pii"}}]},"evaluate":false,"output":true}],"scanners":[{"id":"d962f7ddb3f55041e39195a60ff79d4814a7c331","name":"US Passport Scanner","key":{"operator":"match_regex","parameters":{"regex":"passport","options":{"case_sensitive":false,"min_length":8}}},"value":{"operator":"match_regex","parameters":{"regex":"\\b[0-9A-Z]{9}\\b|\\b[0-9]{6}[A-Z][0-9]{2}\\b","options":{"case_sensitive":false,"min_length":8}}},"tags":{"type":"passport_number","category":"pii"}},{"id":"ac6d683cbac77f6e399a14990793dd8fd0fca333","name":"US Vehicle Identification Number Scanner","key":{"operator":"match_regex","parameters":{"regex":"vehicle[_\\s-]*identification[_\\s-]*number|vin","options":{"case_sensitive":false,"min_length":3}}},"value":{"operator":"match_regex","parameters":{"regex":"\\b[A-HJ-NPR-Z0-9]{17}\\b","options":{"case_sensitive":false,"min_length":17}}},"tags":{"type":"vin","category":"pii"}},{"id":"de0899e0cbaaa812bb624cf04c912071012f616d","name":"UK National Insurance Number Scanner","key":{"operator":"match_regex","parameters":{"regex":"national[\\s_]?(?:insurance(?:\\s+number)?)?|NIN|NI[\\s_]?number|insurance[\\s_]?number","options":{"case_sensitive":false,"min_length":3}}},"value":{"operator":"match_regex","parameters":{"regex":"\\b[A-Z]{2}\\d{6}[A-Z]?\\b","options":{"case_sensitive":false,"min_length":8}}},"tags":{"type":"uk_nin","category":"pii"}},{"id":"450239afc250a19799b6c03dc0e16fd6a4b2a1af","name":"Canadian Social Insurance Number Scanner","key":{"operator":"match_regex","parameters":{"regex":"social[\\s_]?(?:insurance(?:\\s+number)?)?|SIN|Canadian[\\s_]?(?:social[\\s_]?(?:insurance)?|insurance[\\s_]?number)?","options":{"case_sensitive":false,"min_length":3}}},"value":{"operator":"match_regex","parameters":{"regex":"\\b\\d{3}-\\d{3}-\\d{3}\\b","options":{"case_sensitive":false,"min_length":11}}},"tags":{"type":"canadian_sin","category":"pii"}}]})";
    rapidjson::Document::AllocatorType &alloc = doc_.GetAllocator();
    rapidjson::Document additions_doc(&alloc);
    rapidjson::ParseResult const parsed =
        additions_doc.Parse(raw.data());
    if ((parsed == nullptr) || !additions_doc.IsObject()) {
        return;
    }
    auto parsed_processors = additions_doc.FindMember("processors");
    if (parsed_processors == additions_doc.GetObject().MemberEnd()) {
        return;
    }
    doc_.AddMember("processors", parsed_processors->value, alloc);
    auto parsed_scanners = additions_doc.FindMember("scanners");
    if (parsed_scanners == additions_doc.GetObject().MemberEnd()) {
        return;
    }
    doc_.AddMember("scanners", parsed_scanners->value, alloc);
}

engine_ruleset engine_ruleset::from_path(std::string_view path)
{
    auto ruleset = read_file(path);
    auto engine = engine_ruleset{ruleset};
    engine.add_default_processors_and_scanners();

    return engine;
}

} // namespace dds
