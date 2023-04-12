// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../common.hpp"
#include "remote_config/client_handler.hpp"

namespace dds {

namespace mock {
class client : public remote_config::client {
public:
    client(service_identifier &&sid)
        : remote_config::client(nullptr, std::move(sid), {})
    {}
    ~client() = default;
    MOCK_METHOD0(poll, bool());
    MOCK_METHOD0(is_remote_config_available, bool());
};

} // namespace mock

ACTION_P(SignalCall, promise) { promise->set_value(true); }

class ClientHandlerTest : public ::testing::Test {
public:
    service_identifier sid{
        "service", "env", "tracer_version", "app_version", "runtime_id"};
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
    rc_settings.enabled = false;

    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, false);

    EXPECT_FALSE(client_handler);
}

TEST_F(ClientHandlerTest, IfNoServiceConfigProvidedItDoesNotGenerateHandler)
{
    std::shared_ptr<dds::service_config> null_service_config = {};
    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, null_service_config, rc_settings,
        engine, false);

    EXPECT_FALSE(client_handler);
}

TEST_F(ClientHandlerTest, RuntimeIdIsNotGeneratedIfProvided)
{
    const char *runtime_id = "some runtime id";
    id.runtime_id = runtime_id;

    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, false);

    EXPECT_STREQ(runtime_id, client_handler->get_client()
                                 ->get_service_identifier()
                                 .runtime_id.c_str());
}

TEST_F(ClientHandlerTest, RuntimeIdIsGeneratedWhenNotProvided)
{
    id.runtime_id.clear();

    EXPECT_TRUE(id.runtime_id.empty());
    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, false);
    EXPECT_FALSE(client_handler->get_client()
                     ->get_service_identifier()
                     .runtime_id.empty());
}

TEST_F(ClientHandlerTest, AsmFeatureProductIsAddeWhenDynamicEnablement)
{
    auto dynamic_enablement = true;
    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, dynamic_enablement);

    auto products_list = client_handler->get_client()->get_products();
    EXPECT_TRUE(products_list.find("ASM_FEATURES") != products_list.end());
}

TEST_F(
    ClientHandlerTest, AsmFeatureProductIsNotAddeWhenDynamicEnablementDisabled)
{
    auto dynamic_enablement = false;

    // Clear rules file so at least some other products are added
    settings.rules_file.clear();
    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, dynamic_enablement);

    auto products_list = client_handler->get_client()->get_products();
    EXPECT_TRUE(products_list.find("ASM_FEATURES") == products_list.end());
}

TEST_F(ClientHandlerTest, SomeProductsDependOnDynamicEngineBeingSet)
{
    { // When rules file is not set, products are added
        settings.rules_file.clear();
        auto client_handler = remote_config::client_handler::from_settings(
            dds::service_identifier(id), settings, service_config, rc_settings,
            engine, true);

        auto products_list = client_handler->get_client()->get_products();
        EXPECT_TRUE(products_list.find("ASM_DATA") != products_list.end());
        EXPECT_TRUE(products_list.find("ASM_DD") != products_list.end());
        EXPECT_TRUE(products_list.find("ASM") != products_list.end());
    }

    { // When rules file is set, products not are added
        settings.rules_file = "/some/file";
        auto client_handler = remote_config::client_handler::from_settings(
            dds::service_identifier(id), settings, service_config, rc_settings,
            engine, true);

        auto products_list = client_handler->get_client()->get_products();
        EXPECT_TRUE(products_list.find("ASM_DATA") == products_list.end());
        EXPECT_TRUE(products_list.find("ASM_DD") == products_list.end());
        EXPECT_TRUE(products_list.find("ASM") == products_list.end());
    }
}

TEST_F(ClientHandlerTest, IfNoProductsAreRequiredRemoteClientIsNotGenerated)
{
    settings.rules_file = "/some/file";
    auto dynamic_enablement = false;
    auto client_handler = remote_config::client_handler::from_settings(
        dds::service_identifier(id), settings, service_config, rc_settings,
        engine, dynamic_enablement);

    EXPECT_FALSE(client_handler);
}

TEST_F(ClientHandlerTest, ValidateRCThread)
{
    std::promise<bool> poll_call_promise;
    auto poll_call_future = poll_call_promise.get_future();
    std::promise<bool> available_call_promise;
    auto available_call_future = available_call_promise.get_future();

    auto rc_client =
        std::make_unique<mock::client>(dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&available_call_promise), Return(true)));
    EXPECT_CALL(*rc_client, poll)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&poll_call_promise), Return(true)));

    auto client_handler = remote_config::client_handler(
        std::move(rc_client), service_config, 500ms);

    client_handler.start();

    // wait a little bit - this might end up being flaky
    poll_call_future.wait_for(1s);
    available_call_future.wait_for(500ms);
}

TEST_F(ClientHandlerTest, WhenRcNotAvailableItKeepsDiscovering)
{
    std::promise<bool> first_call_promise;
    std::promise<bool> second_call_promise;
    auto first_call_future = first_call_promise.get_future();
    auto second_call_future = second_call_promise.get_future();

    auto rc_client =
        std::make_unique<mock::client>(dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(2)
        .WillOnce(DoAll(SignalCall(&first_call_promise), Return(false)))
        .WillOnce(DoAll(SignalCall(&second_call_promise), Return(false)));
    EXPECT_CALL(*rc_client, poll).Times(0);

    auto client_handler = remote_config::client_handler(
        std::move(rc_client), service_config, 500ms);

    client_handler.start();
    ;

    // wait a little bit - this might end up being flaky
    first_call_future.wait_for(600ms);
    second_call_future.wait_for(1.2s);
}

TEST_F(ClientHandlerTest, WhenPollFailsItGoesBackToDiscovering)
{
    std::promise<bool> first_call_promise;
    std::promise<bool> second_call_promise;
    std::promise<bool> third_call_promise;
    auto first_call_future = first_call_promise.get_future();
    auto second_call_future = second_call_promise.get_future();
    auto third_call_future = third_call_promise.get_future();

    auto rc_client =
        std::make_unique<mock::client>(dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(2)
        .WillOnce(DoAll(SignalCall(&first_call_promise), Return(true)))
        .WillOnce(DoAll(SignalCall(&third_call_promise), Return(true)));
    EXPECT_CALL(*rc_client, poll)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&second_call_promise),
            Throw(dds::remote_config::network_exception("some"))));

    auto client_handler = remote_config::client_handler(
        std::move(rc_client), service_config, 500ms);
    client_handler.start();
    ;

    // wait a little bit - this might end up being flaky
    first_call_future.wait_for(600ms);
    second_call_future.wait_for(1.2s);
    third_call_future.wait_for(1.8s);
}

TEST_F(ClientHandlerTest, WhenDiscoverFailsItStaysOnDiscovering)
{
    std::promise<bool> first_call_promise;
    std::promise<bool> second_call_promise;
    std::promise<bool> third_call_promise;
    auto first_call_future = first_call_promise.get_future();
    auto second_call_future = second_call_promise.get_future();
    auto third_call_future = third_call_promise.get_future();

    auto rc_client =
        std::make_unique<mock::client>(dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(3)
        .WillOnce(DoAll(SignalCall(&first_call_promise), Return(false)))
        .WillOnce(DoAll(SignalCall(&second_call_promise),
            Throw(dds::remote_config::network_exception("some"))))
        .WillOnce(DoAll(SignalCall(&third_call_promise),
            Throw(dds::remote_config::network_exception("some"))));
    EXPECT_CALL(*rc_client, poll).Times(0);

    auto client_handler = remote_config::client_handler(
        std::move(rc_client), service_config, 500ms);
    client_handler.start();

    // wait a little bit - this might end up being flaky
    first_call_future.wait_for(600ms);
    second_call_future.wait_for(1.2s);
    third_call_future.wait_for(1.8s);
}

TEST_F(ClientHandlerTest, ItKeepsPollingWhileNoError)
{
    std::promise<bool> first_call_promise;
    std::promise<bool> second_call_promise;
    std::promise<bool> third_call_promise;
    auto first_call_future = first_call_promise.get_future();
    auto second_call_future = second_call_promise.get_future();
    auto third_call_future = third_call_promise.get_future();

    auto rc_client =
        std::make_unique<mock::client>(dds::service_identifier(sid));
    EXPECT_CALL(*rc_client, is_remote_config_available)
        .Times(1)
        .WillOnce(DoAll(SignalCall(&first_call_promise), Return(true)));
    EXPECT_CALL(*rc_client, poll)
        .Times(2)
        .WillOnce(DoAll(SignalCall(&second_call_promise), Return(true)))
        .WillOnce(DoAll(SignalCall(&third_call_promise), Return(true)));

    auto client_handler = remote_config::client_handler(
        std::move(rc_client), service_config, 500ms);
    client_handler.start();

    // wait a little bit - this might end up being flaky
    first_call_future.wait_for(600ms);
    second_call_future.wait_for(1.2s);
    third_call_future.wait_for(1.8s);
}

TEST_F(ClientHandlerTest, ItDoesNotStartIfNoRcClientGiven)
{
    auto rc_client = nullptr;
    auto client_handler =
        remote_config::client_handler(rc_client, service_config, 500ms);

    EXPECT_FALSE(client_handler.start());
}

} // namespace dds
