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
    client(const service_identifier &sid)
        : remote_config::client(nullptr, sid, {})
    {}
    ~client() = default;
    MOCK_METHOD0(poll, bool());
};
} // namespace mock

ACTION_P(SignalCall, promise) { promise->set_value(true); }

TEST(ServiceTest, ValidateRCThread)
{
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine{engine::create()};

    std::promise<bool> call_promise;
    auto call_future = call_promise.get_future();

    auto client = std::make_unique<mock::client>(sid);
    EXPECT_CALL(*client, poll)
        .WillOnce(DoAll(SignalCall(&call_promise), Return(true)));

    service svc{
        sid, engine, std::move(client), std::make_shared<service_config>(), 1s};

    // wait a little bit - this might end up being flaky
    call_future.wait_for(1s);
}

TEST(ServiceTest, NullEngine)
{
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine;

    auto client = std::make_unique<mock::client>(sid);
    EXPECT_CALL(*client, poll).Times(0);
    EXPECT_THROW(auto s = service(sid, engine, std::move(client),
                     std::make_shared<service_config>(), 1s),
        std::runtime_error);
}

TEST(ServiceTest, NullRCClient)
{
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine{engine::create()};

    // A null client doesn't make a difference as remote config is optional
    service svc{sid, engine, nullptr, std::make_shared<service_config>()};
    EXPECT_EQ(engine.get(), svc.get_engine().get());
}

} // namespace dds
