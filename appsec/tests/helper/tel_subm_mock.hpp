#pragma once

#include <gmock/gmock.h>
#include <telemetry.hpp>

namespace dds::mock {
class tel_submitter : public dds::telemetry::telemetry_submitter {
public:
    MOCK_METHOD(void, submit_metric,
        (std::string_view, double, dds::telemetry::telemetry_tags), (override));
    MOCK_METHOD(
        void, submit_span_metric, (std::string_view, double), (override));
    MOCK_METHOD(
        void, submit_span_meta, (std::string_view, std::string), (override));
    MOCK_METHOD(void, submit_span_meta_copy_key, (std::string, std::string),
        (override));
    MOCK_METHOD(void, submit_log,
        (dds::telemetry::telemetry_submitter::log_level, std::string,
            std::string, std::optional<std::string>, std::optional<std::string>,
            bool),
        (override));
};
} // namespace dds::mock
