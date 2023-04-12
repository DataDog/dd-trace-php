// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <remote_config/client.hpp>
#include <service.hpp>
#include <stdexcept>

namespace dds {

namespace mock {
class client : public remote_config::client {
public:
    client(service_identifier &sid)
        : remote_config::client(nullptr, std::move(sid), {})
    {}
    ~client() = default;
    MOCK_METHOD0(poll, bool());
    MOCK_METHOD0(is_remote_config_available, bool());
};
} // namespace mock

TEST(ServiceTest, NullEngine)
{
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine;
    auto client = std::make_unique<mock::client>(sid);
    EXPECT_CALL(*client, poll).Times(0);

    auto service_config = std::make_shared<dds::service_config>();
    auto client_handler = std::make_shared<remote_config::client_handler>(
        std::move(client), service_config, 1s);

    EXPECT_THROW(
        auto s = service(engine, service_config, std::move(client_handler)),
        std::runtime_error);
}

TEST(ServiceTest, NullServiceHandler)
{
    std::shared_ptr<engine> engine{engine::create()};
    auto service_config = std::make_shared<dds::service_config>();

    // A null service handler doesn't make a difference as remote config is
    // optional
    service svc{engine, service_config, nullptr};
    EXPECT_EQ(engine.get(), svc.get_engine().get());
}

} // namespace dds
