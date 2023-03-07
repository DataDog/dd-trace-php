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
    MOCK_METHOD0(is_remote_config_available, bool());
};
} // namespace mock

ACTION_P(SignalCall, promise) { promise->set_value(true); }

TEST(ServiceTest, ValidateRCThread)
{
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine{engine::create()};

    std::promise<bool> poll_call_promise;
    auto poll_call_future = poll_call_promise.get_future();
    std::promise<bool> available_call_promise;
    auto available_call_future = available_call_promise.get_future();

    auto client = std::make_unique<mock::client>(sid);
    EXPECT_CALL(*client, is_remote_config_available)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&available_call_promise), Return(true)));
    EXPECT_CALL(*client, poll)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&poll_call_promise), Return(true)));

    service svc{sid, engine, std::move(client),
        std::make_shared<service_config>(), 500ms};

    // wait a little bit - this might end up being flaky
    poll_call_future.wait_for(1s);
    available_call_future.wait_for(500ms);
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

TEST(ServiceTest, WhenRcNotAvailableItKeepsDiscovering)
{
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine{engine::create()};

    std::promise<bool> first_call_promise;
    std::promise<bool> second_call_promise;
    auto first_call_future = first_call_promise.get_future();
    auto second_call_future = second_call_promise.get_future();

    auto client = std::make_unique<mock::client>(sid);
    EXPECT_CALL(*client, is_remote_config_available)
        .Times(2)
        .WillOnce(DoAll(SignalCall(&first_call_promise), Return(false)))
        .WillOnce(DoAll(SignalCall(&second_call_promise), Return(false)));
    EXPECT_CALL(*client, poll).Times(0);

    service svc{sid, engine, std::move(client),
        std::make_shared<service_config>(), 500ms};

    // wait a little bit - this might end up being flaky
    first_call_future.wait_for(600ms);
    second_call_future.wait_for(1.2s);
}

TEST(ServiceTest, WhenPollFailsItGoesBackToDiscovering)
{
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine{engine::create()};

    std::promise<bool> first_call_promise;
    std::promise<bool> second_call_promise;
    std::promise<bool> third_call_promise;
    auto first_call_future = first_call_promise.get_future();
    auto second_call_future = second_call_promise.get_future();
    auto third_call_future = third_call_promise.get_future();

    auto client = std::make_unique<mock::client>(sid);
    EXPECT_CALL(*client, is_remote_config_available)
        .Times(2)
        .WillOnce(DoAll(SignalCall(&first_call_promise), Return(true)))
        .WillOnce(DoAll(SignalCall(&third_call_promise), Return(true)));
    EXPECT_CALL(*client, poll)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&second_call_promise),
            Throw(dds::remote_config::network_exception("some"))));

    service svc{sid, engine, std::move(client),
        std::make_shared<service_config>(), 500ms};

    // wait a little bit - this might end up being flaky
    first_call_future.wait_for(600ms);
    second_call_future.wait_for(1.2s);
    third_call_future.wait_for(1.8s);
}

TEST(ServiceTest, WhenDiscoverFailsItStaysOnDiscovering)
{
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine{engine::create()};

    std::promise<bool> first_call_promise;
    std::promise<bool> second_call_promise;
    std::promise<bool> third_call_promise;
    auto first_call_future = first_call_promise.get_future();
    auto second_call_future = second_call_promise.get_future();
    auto third_call_future = third_call_promise.get_future();

    auto client = std::make_unique<mock::client>(sid);
    EXPECT_CALL(*client, is_remote_config_available)
        .Times(3)
        .WillOnce(DoAll(SignalCall(&first_call_promise), Return(false)))
        .WillOnce(DoAll(SignalCall(&second_call_promise),
            Throw(dds::remote_config::network_exception("some"))))
        .WillOnce(DoAll(SignalCall(&third_call_promise),
            Throw(dds::remote_config::network_exception("some"))));
    EXPECT_CALL(*client, poll).Times(0);

    service svc{sid, engine, std::move(client),
        std::make_shared<service_config>(), 500ms};

    // wait a little bit - this might end up being flaky
    first_call_future.wait_for(600ms);
    second_call_future.wait_for(1.2s);
    third_call_future.wait_for(1.8s);
}

TEST(ServiceTest, ItKeepsPollingWhileNoError)
{
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine{engine::create()};

    std::promise<bool> first_call_promise;
    std::promise<bool> second_call_promise;
    std::promise<bool> third_call_promise;
    auto first_call_future = first_call_promise.get_future();
    auto second_call_future = second_call_promise.get_future();
    auto third_call_future = third_call_promise.get_future();

    auto client = std::make_unique<mock::client>(sid);
    EXPECT_CALL(*client, is_remote_config_available)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&first_call_promise), Return(true)));
    EXPECT_CALL(*client, poll)
        .Times(2)
        .WillOnce(DoAll(SignalCall(&second_call_promise), Return(true)))
        .WillOnce(DoAll(SignalCall(&third_call_promise), Return(true)));

    service svc{sid, engine, std::move(client),
        std::make_shared<service_config>(), 500ms};

    // wait a little bit - this might end up being flaky
    first_call_future.wait_for(600ms);
    second_call_future.wait_for(1.2s);
    third_call_future.wait_for(1.8s);
}

} // namespace dds
