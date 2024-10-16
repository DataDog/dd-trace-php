#include <gmock/gmock.h>
#include <metrics.hpp>

namespace dds::mock {
class tel_submitter : public dds::metrics::TelemetrySubmitter {
public:
    MOCK_METHOD(void, submit_metric, (std::string_view, double, std::string),
        (override));
    MOCK_METHOD(
        void, submit_legacy_metric, (std::string_view, double), (override));
    MOCK_METHOD(
        void, submit_legacy_meta, (std::string_view, std::string), (override));
    MOCK_METHOD(void, submit_legacy_meta_copy_key, (std::string, std::string),
        (override));
};
} // namespace dds::mock
