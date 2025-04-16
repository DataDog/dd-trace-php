#pragma once

#include <gmock/gmock.h>
#include <metrics.hpp>

namespace dds::mock {
class tel_submitter : public dds::metrics::telemetry_submitter {
public:
    MOCK_METHOD(void, submit_metric,
        (std::string_view, double, dds::metrics::telemetry_tags), (override));
    MOCK_METHOD(
        void, submit_span_metric, (std::string_view, double), (override));
    MOCK_METHOD(
        void, submit_span_meta, (std::string_view, std::string), (override));
    MOCK_METHOD(void, submit_span_meta_copy_key, (std::string, std::string),
        (override));
};
} // namespace dds::mock
