// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "base64.h"
#include "common.hpp"
#include "remote_config/asm_features_listener.hpp"
#include "remote_config/product.hpp"

namespace dds {

remote_config::config get_config(const std::string &content, bool encode = true)
{
    std::string encoded_content = content;
    if (encode) {
        encoded_content = base64_encode(content);
    }

    return {"some product", "some id", encoded_content, "some path", {}, 123,
        321,
        remote_config::protocol::config_state::applied_state::UNACKNOWLEDGED,
        ""};
}

remote_config::config get_config_with_status(std::string status)
{
    return get_config("{\"asm\":{\"enabled\":\"" + status + "\"}}");
}

remote_config::config get_enabled_config()
{
    return get_config_with_status("true");
}

remote_config::config get_disabled_config()
{
    return get_config_with_status("false");
}

TEST(RemoteConfigAsmFeaturesListener, ByDefaultListenerIsNotActive)
{
    auto remote_config_service =
        std::make_shared<remote_config::remote_config_service>();
    remote_config::asm_features_listener listener(remote_config_service);

    EXPECT_FALSE(remote_config_service->is_asm_enabled());
}

TEST(RemoteConfigAsmFeaturesListener, ListenerGetActiveWhenConfigSaysSoOnUpdate)
{
    auto remote_config_service =
        std::make_shared<remote_config::remote_config_service>();
    remote_config::asm_features_listener listener(remote_config_service);

    try {
        listener.on_update(get_enabled_config());
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_TRUE(remote_config_service->is_asm_enabled());
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerGetDeactivedWhenConfigSaysSoOnUpdate)
{
    auto remote_config_service =
        std::make_shared<remote_config::remote_config_service>();
    remote_config::asm_features_listener listener(remote_config_service);

    try {
        listener.on_update(get_disabled_config());
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_FALSE(remote_config_service->is_asm_enabled());
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerThrowsAnErrorWhenContentOfConfigAreNotValidBase64)
{
    auto remote_config_service =
        std::make_shared<remote_config::remote_config_service>();
    remote_config::asm_features_listener listener(remote_config_service);
    std::string invalid_content = "&&&";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config base64 encoded contents:";
    remote_config::config non_base_64_content_config =
        get_config(invalid_content, false);

    try {
        listener.on_update(non_base_64_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_FALSE(remote_config_service->is_asm_enabled());
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerThrowsAnErrorWhenContentIsNotValidJson)
{
    std::string error_message = "";
    std::string expected_error_message = "Invalid config json contents";
    auto remote_config_service =
        std::make_shared<remote_config::remote_config_service>();
    remote_config::asm_features_listener listener(remote_config_service);
    std::string invalid_content = "invalidJsonContent";
    remote_config::config config = get_config(invalid_content);

    try {
        listener.on_update(config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_FALSE(remote_config_service->is_asm_enabled());
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener, ListenerThrowsAnErrorWhenAsmKeyMissing)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: asm key missing or invalid";
    auto remote_config_service =
        std::make_shared<remote_config::remote_config_service>();
    remote_config::asm_features_listener listener(remote_config_service);
    remote_config::config asm_key_missing = get_config("{}");

    try {
        listener.on_update(asm_key_missing);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_FALSE(remote_config_service->is_asm_enabled());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener, ListenerThrowsAnErrorWhenAsmIsNotValid)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: asm key missing or invalid";
    auto remote_config_service =
        std::make_shared<remote_config::remote_config_service>();
    remote_config::asm_features_listener listener(remote_config_service);
    remote_config::config invalid_asm_key = get_config("{ \"asm\": 123}");

    try {
        listener.on_update(invalid_asm_key);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_FALSE(remote_config_service->is_asm_enabled());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST(
    RemoteConfigAsmFeaturesListener, ListenerThrowsAnErrorWhenEnabledKeyMissing)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: enabled key missing";
    auto remote_config_service =
        std::make_shared<remote_config::remote_config_service>();
    remote_config::asm_features_listener listener(remote_config_service);
    remote_config::config enabled_key_missing = get_config("{ \"asm\": {}}");

    try {
        listener.on_update(enabled_key_missing);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_FALSE(remote_config_service->is_asm_enabled());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener,
    ListenerThrowsAnErrorWhenEnabledKeyIsInvalid)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: enabled key missing";
    auto remote_config_service =
        std::make_shared<remote_config::remote_config_service>();
    remote_config::asm_features_listener listener(remote_config_service);
    remote_config::config enabled_key_invalid =
        get_config("{ \"asm\": { \"enabled\": 123}}");

    try {
        listener.on_update(enabled_key_invalid);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_FALSE(remote_config_service->is_asm_enabled());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}
} // namespace dds