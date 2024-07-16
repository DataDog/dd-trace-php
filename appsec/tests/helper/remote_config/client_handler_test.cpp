// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../common.hpp"
#include "metrics.hpp"
#include "mocks.hpp"
#include "remote_config/client_handler.hpp"

namespace dds {

namespace mock {

class client_handler : public remote_config::client_handler {
public:
    client_handler(remote_config::client::ptr &&rc_client,
        std::shared_ptr<service_config> service_config,
        std::shared_ptr<metrics::TelemetrySubmitter> msubmitter,
        const std::chrono::milliseconds &poll_interval)
        : remote_config::client_handler(
              std::move(rc_client), service_config, msubmitter, poll_interval)
    {}
    void set_max_interval(std::chrono::milliseconds new_interval)
    {
        max_interval = new_interval;
    }
    auto get_max_interval() { return max_interval; }
    const std::chrono::milliseconds get_current_interval() { return interval_; }
    void tick() { remote_config::client_handler::tick(); }

    auto get_errors() { return errors_; }
};

} // namespace mock

ACTION_P(SignalCall, promise) { promise->set_value(true); }

class ClientHandlerTest : public ::testing::Test {
public:
    service_identifier sid{"service", {"extra_service01", "extra_service02"},
        "env", "tracer_version", "app_version", "runtime_id"};
    dds::engine_settings settings;
    remote_config::settings rc_settings;
    std::shared_ptr<dds::service_config> service_config;
    service_identifier id;
    std::shared_ptr<dds::engine> engine;

    void SetUp()
    {
        service_config = std::make_shared<dds::service_config>();
        id = sid;
        engine = engine::create();
        rc_settings.enabled = true;
    }
};

TEST_F(ClientHandlerTest, IfRemoteConfigDisabledItDoesNotGenerateHandler)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new mock::tel_submitter);
    rc_settings.enabled = false;

    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, msubmitter, false);

    EXPECT_FALSE(client_handler);
}

TEST_F(ClientHandlerTest, IfNoServiceConfigProvidedItDoesNotGenerateHandler)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new mock::tel_submitter);
    std::shared_ptr<dds::service_config> null_service_config = {};
    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, null_service_config, rc_settings,
        engine, msubmitter, false);

    EXPECT_FALSE(client_handler);
}

TEST_F(ClientHandlerTest, RuntimeIdIsNotGeneratedIfProvided)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new mock::tel_submitter);
    const char *runtime_id = "some runtime id";
    id.runtime_id = runtime_id;

    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, msubmitter, false);

    EXPECT_STREQ(runtime_id, client_handler->get_client()
                                 ->get_service_identifier()
                                 .runtime_id.c_str());
}

TEST_F(ClientHandlerTest, AsmFeatureProductIsAddeWhenDynamicEnablement)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new mock::tel_submitter);
    auto dynamic_enablement = true;
    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, msubmitter, dynamic_enablement);

    auto products_list = client_handler->get_client()->get_products();
    EXPECT_TRUE(products_list.find("ASM_FEATURES") != products_list.end());
}

TEST_F(
    ClientHandlerTest, AsmFeatureProductIsNotAddeWhenDynamicEnablementDisabled)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new mock::tel_submitter);
    auto dynamic_enablement = false;

    // Clear rules file so at least some other products are added
    settings.rules_file.clear();
    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, msubmitter, dynamic_enablement);

    auto products_list = client_handler->get_client()->get_products();
    EXPECT_TRUE(products_list.find("ASM_FEATURES") == products_list.end());
}

TEST_F(ClientHandlerTest, SomeProductsDependOnDynamicEngineBeingSet)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new mock::tel_submitter);

    { // When rules file is not set, products are added
        settings.rules_file.clear();
        auto client_handler = remote_config::client_handler::from_settings(
            dds::service_identifier(id), settings, service_config, rc_settings,
            engine, msubmitter, true);

        auto products_list = client_handler->get_client()->get_products();
        EXPECT_TRUE(products_list.find("ASM_DATA") != products_list.end());
        EXPECT_TRUE(products_list.find("ASM_DD") != products_list.end());
        EXPECT_TRUE(products_list.find("ASM") != products_list.end());
    }

    { // When rules file is set, products not are added
        settings.rules_file = "/some/file";
        auto client_handler = remote_config::client_handler::from_settings(
            dds::service_identifier(id), settings, service_config, rc_settings,
            engine, msubmitter, true);

        auto products_list = client_handler->get_client()->get_products();
        EXPECT_TRUE(products_list.find("ASM_DATA") == products_list.end());
        EXPECT_TRUE(products_list.find("ASM_DD") == products_list.end());
        EXPECT_TRUE(products_list.find("ASM") == products_list.end());
    }
}

TEST_F(ClientHandlerTest, IfNoProductsAreRequiredRemoteClientIsNotGenerated)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new mock::tel_submitter);
    settings.rules_file = "/some/file";
    auto dynamic_enablement = false;
    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, msubmitter, dynamic_enablement);

    EXPECT_FALSE(client_handler);
}

TEST_F(ClientHandlerTest, ValidateRCThread)
{
    auto msubmitter = std::make_shared<StrictMock<mock::tel_submitter>>();
    std::promise<bool> poll_call_promise;
    auto poll_call_future = poll_call_promise.get_future();
    std::promise<bool> available_call_promise;
    auto available_call_future = available_call_promise.get_future();

    auto rc_client = std::make_unique<remote_config::mock::client>(sid);
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&available_call_promise), Return(true)));
    EXPECT_CALL(*rc_client, poll)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&poll_call_promise), Return(true)));

    EXPECT_CALL(
        *msubmitter, submit_metric("remote_config.first_pull"sv, _, ""));
    auto client_handler = remote_config::client_handler(
        std::move(rc_client), service_config, msubmitter, 200ms);

    client_handler.start();

    // wait a little bit - this might end up being flaky
    poll_call_future.wait_for(400ms);
    available_call_future.wait_for(200ms);
}

TEST_F(ClientHandlerTest, WhenRcNotAvailableItKeepsDiscovering)
{
    auto msubmitter = std::make_shared<mock::tel_submitter>();
    auto rc_client = std::make_unique<remote_config::mock::client>(
        dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(2)
        .WillOnce(Return(false))
        .WillOnce(Return(false));
    EXPECT_CALL(*rc_client, poll).Times(0);

    auto client_handler = mock::client_handler(
        std::move(rc_client), service_config, msubmitter, 500ms);

    client_handler.tick();
    client_handler.tick();
}

TEST_F(ClientHandlerTest, WhenPollFailsItGoesBackToDiscovering)
{
    auto msubmitter = std::make_shared<mock::tel_submitter>();
    auto rc_client = std::make_unique<remote_config::mock::client>(
        dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(2)
        .WillOnce(Return(true))
        .WillOnce(Return(true));
    EXPECT_CALL(*rc_client, poll)
        .Times(1)
        .WillOnce(Throw(dds::remote_config::network_exception("some")));

    auto client_handler = mock::client_handler(
        std::move(rc_client), service_config, msubmitter, 500ms);
    client_handler.tick();
    client_handler.tick();
    client_handler.tick();
}

TEST_F(ClientHandlerTest, WhenDiscoverFailsItStaysOnDiscovering)
{
    auto msubmitter = std::make_shared<mock::tel_submitter>();
    auto rc_client = std::make_unique<remote_config::mock::client>(
        dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(3)
        .WillOnce(Return(false))
        .WillOnce(Throw(dds::remote_config::network_exception("some")))
        .WillOnce(Throw(dds::remote_config::network_exception("some")));
    EXPECT_CALL(*rc_client, poll).Times(0);

    auto client_handler = mock::client_handler(
        std::move(rc_client), service_config, msubmitter, 50ms);
    client_handler.set_max_interval(100ms);
    client_handler.tick();
    client_handler.tick();
    client_handler.tick();
}

TEST_F(ClientHandlerTest, ItKeepsPollingWhileNoError)
{
    auto msubmitter = std::make_shared<StrictMock<mock::tel_submitter>>();
    auto rc_client = std::make_unique<remote_config::mock::client>(
        dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(1)
        .WillOnce(Return(true));
    EXPECT_CALL(*rc_client, poll)
        .Times(2)
        .WillOnce(Return(true))
        .WillOnce(Return(true));

    auto client_handler = mock::client_handler(
        std::move(rc_client), service_config, msubmitter, 500ms);

    EXPECT_CALL(
        *msubmitter, submit_metric("remote_config.first_pull"sv, _, ""));
    client_handler.tick();
    EXPECT_CALL(
        *msubmitter, submit_metric("remote_config.last_success"sv, _, ""));
    client_handler.tick();
    client_handler.tick();
}

TEST_F(ClientHandlerTest, ItDoesNotStartIfNoRcClientGiven)
{
    auto msubmitter = std::make_shared<mock::tel_submitter>();
    auto rc_client = nullptr;
    auto client_handler = remote_config::client_handler(
        rc_client, service_config, msubmitter, 500ms);

    EXPECT_FALSE(client_handler.start());
}

TEST_F(ClientHandlerTest, ItDoesNotGoOverMaxIfGivenInitialIntervalIsLower)
{
    auto msubmitter = std::make_shared<mock::tel_submitter>();
    auto rc_client = std::make_unique<remote_config::mock::client>(
        dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(3)
        .WillRepeatedly(Return(false));

    auto max_interval = 300ms;
    auto client_handler = mock::client_handler(
        std::move(rc_client), service_config, msubmitter, 299ms);
    client_handler.set_max_interval(max_interval);

    client_handler.tick();
    client_handler.tick();
    client_handler.tick();

    EXPECT_EQ(max_interval, client_handler.get_current_interval());
    EXPECT_EQ(3, client_handler.get_errors());
}

TEST_F(ClientHandlerTest, IfInitialIntervalIsHigherThanMaxItBecomesNewMax)
{
    auto msubmitter = std::make_shared<mock::tel_submitter>();
    auto rc_client = std::make_unique<remote_config::mock::client>(
        dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(3)
        .WillRepeatedly(Return(false));

    auto interval = 200ms;
    auto client_handler = mock::client_handler(
        std::move(rc_client), service_config, msubmitter, interval);
    client_handler.set_max_interval(100ms);

    client_handler.tick();
    client_handler.tick();
    client_handler.tick();

    EXPECT_EQ(interval, client_handler.get_current_interval());
    EXPECT_EQ(3, client_handler.get_errors());
}

TEST_F(ClientHandlerTest, ByDefaultMaxIntervalisFiveMinutes)
{
    auto msubmitter = std::make_shared<mock::tel_submitter>();
    auto rc_client = std::make_unique<remote_config::mock::client>(
        dds::service_identifier(sid));
    auto client_handler = mock::client_handler(
        std::move(rc_client), service_config, msubmitter, 200ms);

    EXPECT_EQ(5min, client_handler.get_max_interval());
}

TEST_F(ClientHandlerTest, RegisterAndUnregisterRuntimeID)
{
    auto msubmitter = std::make_shared<mock::tel_submitter>();
    auto rc_client = std::make_unique<remote_config::mock::client>(
        dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, register_runtime_id).Times(1);
    EXPECT_CALL(*rc_client, unregister_runtime_id).Times(1);

    auto client_handler = remote_config::client_handler(
        std::move(rc_client), service_config, msubmitter, 200ms);

    client_handler.register_runtime_id("something");
    client_handler.unregister_runtime_id("something");
}

} // namespace dds
