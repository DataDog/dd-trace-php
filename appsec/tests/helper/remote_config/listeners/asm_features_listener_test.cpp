// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../../common.hpp"
#include "../mocks.hpp"
#include "base64.h"
#include "remote_config/exception.hpp"
#include "remote_config/listeners/asm_features_listener.hpp"
#include "remote_config/product.hpp"

namespace dds {

namespace mock = remote_config::mock;
auto &ASM_FEATURES = remote_config::known_products::ASM_FEATURES;

remote_config::config get_config_with_status(std::string status)
{
    return mock::get_config(
        ASM_FEATURES, "{\"asm\":{\"enabled\":" + status + "}}");
}

remote_config::config get_enabled_config(bool as_string = true)
{
    std::string quotes = as_string ? "\"" : "";
    return get_config_with_status(quotes + "true" + quotes);
}

remote_config::config get_disabled_config(bool as_string = true)
{
    std::string quotes = as_string ? "\"" : "";
    return get_config_with_status(quotes + "false" + quotes);
}

TEST(RemoteConfigAsmFeaturesListener, ByDefaultListenerIsNotSet)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerGetActiveWhenConfigSaysSoOnUpdateAsString)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);

    try {
        listener.on_update(get_enabled_config());
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
}

TEST(RemoteConfigAsmFeaturesListener, AsmParserIsCaseInsensitive)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());

    listener.on_update(get_config_with_status("\"TrUe\""));

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerGetDeactivedWhenConfigSaysSoOnUpdateAsString)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);

    try {
        listener.on_update(get_disabled_config());
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::DISABLED,
        remote_config_service->get_asm_enabled_status());
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerGetActiveWhenConfigSaysSoOnUpdateAsBoolean)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);

    try {
        listener.on_update(get_enabled_config(false));
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerGetDeactivedWhenConfigSaysSoOnUpdateAsBoolean)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);

    try {
        listener.on_update(get_disabled_config(false));
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::DISABLED,
        remote_config_service->get_asm_enabled_status());
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerThrowsAnErrorWhenContentOfConfigAreNotValidBase64)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    std::string invalid_content = "&&&";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config non_base_64_content_config =
        mock::get_config(ASM_FEATURES, invalid_content);

    try {
        listener.on_update(non_base_64_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerThrowsAnErrorWhenContentIsNotValidJson)
{
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    std::string invalid_content = "invalidJsonContent";
    remote_config::config config =
        mock::get_config(ASM_FEATURES, invalid_content);

    try {
        listener.on_update(config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener, ListenerThrowsAnErrorWhenAsmKeyMissing)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: asm key missing or invalid";
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    remote_config::config asm_key_missing =
        mock::get_config(ASM_FEATURES, "{}");

    try {
        listener.on_update(asm_key_missing);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener, ListenerThrowsAnErrorWhenAsmIsNotValid)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: asm key missing or invalid";
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    remote_config::config invalid_asm_key =
        mock::get_config(ASM_FEATURES, "{ \"asm\": 123}");

    try {
        listener.on_update(invalid_asm_key);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST(
    RemoteConfigAsmFeaturesListener, ListenerThrowsAnErrorWhenEnabledKeyMissing)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: enabled key missing";
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    remote_config::config enabled_key_missing =
        mock::get_config(ASM_FEATURES, "{ \"asm\": {}}");

    try {
        listener.on_update(enabled_key_missing);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerThrowsAnErrorWhenEnabledKeyIsInvalid)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: enabled key invalid";
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    remote_config::config enabled_key_invalid =
        mock::get_config(ASM_FEATURES, "{ \"asm\": { \"enabled\": 123}}");

    try {
        listener.on_update(enabled_key_invalid);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener, WhenListenerGetsUnapplyItGetsNotSet)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);

    listener.on_update(get_enabled_config(false));
    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());

    remote_config::config some_key;
    listener.on_unapply(some_key);

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
}

} // namespace dds
