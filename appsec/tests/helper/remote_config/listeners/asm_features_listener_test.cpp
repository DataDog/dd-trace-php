// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../../common.hpp"
#include "base64.h"
#include "remote_config/exception.hpp"
#include "remote_config/listeners/asm_features_listener.hpp"
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

remote_config::config get_config_with_status_and_sample_rate(
    std::string status, double sample_rate)
{
    return get_config("{\"asm\":{\"enabled\":" + status +
                      "}, \"api_security\": { \"request_sample_rate\": " +
                      std::to_string(sample_rate) + "}}");
}

remote_config::config get_config_with_status(std::string status)
{
    return get_config_with_status_and_sample_rate(status, 0.1);
}

remote_config::config get_config_with_sample_rate(double sample_rate)
{
    return get_config_with_status_and_sample_rate("true", sample_rate);
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

class RemoteConfigAsmFeaturesListenerTest : public ::testing::Test {
public:
    std::shared_ptr<dds::service_config> service_config;

    void SetUp() { service_config = std::make_shared<dds::service_config>(); }
};

TEST_F(RemoteConfigAsmFeaturesListenerTest, ByDefaultListenerIsNotSet)
{
    remote_config::asm_features_listener listener(service_config, true, false);

    EXPECT_EQ(
        enable_asm_status::NOT_SET, service_config->get_asm_enabled_status());
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ListenerGetActiveWhenConfigSaysSoOnUpdateAsString)
{
    remote_config::asm_features_listener listener(service_config, true, false);

    try {
        listener.on_update(get_enabled_config());
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(
        enable_asm_status::ENABLED, service_config->get_asm_enabled_status());
}

TEST_F(RemoteConfigAsmFeaturesListenerTest, AsmParserIsCaseInsensitive)
{
    remote_config::asm_features_listener listener(service_config, true, false);

    EXPECT_EQ(
        enable_asm_status::NOT_SET, service_config->get_asm_enabled_status());

    listener.on_update(get_config_with_status("\"TrUe\""));

    EXPECT_EQ(
        enable_asm_status::ENABLED, service_config->get_asm_enabled_status());
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ListenerGetDeactivedWhenConfigSaysSoOnUpdateAsString)
{
    remote_config::asm_features_listener listener(service_config, true, false);

    try {
        listener.on_update(get_disabled_config());
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(
        enable_asm_status::DISABLED, service_config->get_asm_enabled_status());
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ListenerGetActiveWhenConfigSaysSoOnUpdateAsBoolean)
{
    remote_config::asm_features_listener listener(service_config, true, false);

    try {
        listener.on_update(get_enabled_config(false));
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(
        enable_asm_status::ENABLED, service_config->get_asm_enabled_status());
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ListenerGetDeactivedWhenConfigSaysSoOnUpdateAsBoolean)
{
    remote_config::asm_features_listener listener(service_config, true, false);

    try {
        listener.on_update(get_disabled_config(false));
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(
        enable_asm_status::DISABLED, service_config->get_asm_enabled_status());
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ListenerThrowsAnErrorWhenContentOfConfigAreNotValidBase64)
{
    remote_config::asm_features_listener listener(service_config, true, false);
    std::string invalid_content = "&&&";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config non_base_64_content_config =
        get_config(invalid_content, false);

    try {
        listener.on_update(non_base_64_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(
        enable_asm_status::NOT_SET, service_config->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ListenerThrowsAnErrorWhenContentIsNotValidJson)
{
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::asm_features_listener listener(service_config, true, false);
    std::string invalid_content = "invalidJsonContent";
    remote_config::config config = get_config(invalid_content);

    try {
        listener.on_update(config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(
        enable_asm_status::NOT_SET, service_config->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST_F(
    RemoteConfigAsmFeaturesListenerTest, ListenerThrowsAnErrorWhenAsmIsNotValid)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: asm key invalid";
    remote_config::asm_features_listener listener(service_config, true, false);
    remote_config::config invalid_asm_key = get_config("{ \"asm\": 123}");

    try {
        listener.on_update(invalid_asm_key);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(
        enable_asm_status::NOT_SET, service_config->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ListenerThrowsAnErrorWhenEnabledKeyMissing)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: enabled key missing";
    remote_config::asm_features_listener listener(service_config, true, false);
    remote_config::config enabled_key_missing = get_config(
        "{ \"asm\": {}, \"api_security\": { \"request_sample_rate\": 0.1}}");

    try {
        listener.on_update(enabled_key_missing);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(
        enable_asm_status::NOT_SET, service_config->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ListenerThrowsAnErrorWhenEnabledKeyIsInvalid)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: enabled key invalid";
    remote_config::asm_features_listener listener(service_config, true, false);
    remote_config::config enabled_key_invalid =
        get_config("{ \"asm\": { \"enabled\": 123}, \"api_security\": { "
                   "\"request_sample_rate\": 0.1}}");

    try {
        listener.on_update(enabled_key_invalid);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(
        enable_asm_status::NOT_SET, service_config->get_asm_enabled_status());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST_F(RemoteConfigAsmFeaturesListenerTest, WhenListenerGetsUnapplyItGetsNotSet)
{
    remote_config::asm_features_listener listener(service_config, true, false);

    listener.on_update(get_enabled_config(false));
    EXPECT_EQ(
        enable_asm_status::ENABLED, service_config->get_asm_enabled_status());

    remote_config::config some_key;
    listener.on_unapply(some_key);

    EXPECT_EQ(
        enable_asm_status::NOT_SET, service_config->get_asm_enabled_status());
}

TEST_F(
    RemoteConfigAsmFeaturesListenerTest, ThrowsErrorWhenApiSecurityHasWrongType)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: api_security key invalid";
    remote_config::asm_features_listener listener(service_config, true, true);
    remote_config::config payload =
        get_config("{ \"asm\": { \"enabled\": true}, \"api_security\": 1234}");

    try {
        listener.on_update(payload);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ThrowsErrorWhenNoRequestSampleRatePresent)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: request_sample_rate key missing";
    remote_config::asm_features_listener listener(service_config, true, true);
    remote_config::config payload =
        get_config("{ \"asm\": { \"enabled\": true}, \"api_security\": {}}");

    try {
        listener.on_update(payload);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST_F(RemoteConfigAsmFeaturesListenerTest,
    ThrowsErrorWhenRequestSampleRateHasWrongType)
{
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json encoded contents: request_sample_rate is not "
        "double";
    remote_config::asm_features_listener listener(service_config, true, true);
    remote_config::config payload =
        get_config("{ \"asm\": { \"enabled\": true}, \"api_security\": { "
                   "\"request_sample_rate\": true}}");

    try {
        listener.on_update(payload);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST_F(RemoteConfigAsmFeaturesListenerTest, RequestSampleRateIsParsed)
{
    remote_config::asm_features_listener listener(service_config, true, true);

    { // It parses floats
        auto sample_rate = 0.12;
        try {
            listener.on_update(get_config_with_sample_rate(sample_rate));
        } catch (remote_config::error_applying_config &error) {
            std::cout << error.what() << std::endl;
        }
        EXPECT_EQ(sample_rate, service_config->get_request_sample_rate());
    }

    { // It parses integers
        auto sample_rate = 0;
        try {
            listener.on_update(get_config_with_sample_rate(sample_rate));
        } catch (remote_config::error_applying_config &error) {
            std::cout << error.what() << std::endl;
        }
        EXPECT_EQ(sample_rate, service_config->get_request_sample_rate());
    }
}

TEST_F(RemoteConfigAsmFeaturesListenerTest, DynamicEnablementIsDisabled)
{
    remote_config::asm_features_listener listener(service_config, false, true);

    try {
        listener.on_update(get_config_with_status_and_sample_rate("true", 0.2));
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }
    EXPECT_EQ(0.2, service_config->get_request_sample_rate());
    EXPECT_EQ(
        enable_asm_status::NOT_SET, service_config->get_asm_enabled_status());
}

TEST_F(RemoteConfigAsmFeaturesListenerTest, ApiSecurityIsDisabled)
{
    remote_config::asm_features_listener listener(service_config, true, false);

    { // Api security value is stored regardless
        try {
            listener.on_update(
                get_config_with_status_and_sample_rate("true", 0.2));
        } catch (remote_config::error_applying_config &error) {
            std::cout << error.what() << std::endl;
        }
        EXPECT_EQ(0.2, service_config->get_request_sample_rate());
        EXPECT_EQ(enable_asm_status::ENABLED,
            service_config->get_asm_enabled_status());
    }

    { // Api security can be missing
        try {
            auto missing_api_security =
                get_config("{ \"asm\": { \"enabled\": true}}");
            listener.on_update(missing_api_security);
        } catch (remote_config::error_applying_config &error) {
            std::cout << error.what() << std::endl;
        }
        EXPECT_EQ(
            0.2, service_config->get_request_sample_rate()); // same as before
        EXPECT_EQ(enable_asm_status::ENABLED,
            service_config->get_asm_enabled_status());
    }
}

TEST_F(RemoteConfigAsmFeaturesListenerTest, ProductsAreDynamic)
{
    { // All disabled
        remote_config::asm_features_listener listener(
            service_config, false, false);
        EXPECT_EQ(0, listener.get_supported_products().size());
    }

    { // Asm disabled
        remote_config::asm_features_listener listener(
            service_config, true, false);
        EXPECT_EQ(1, listener.get_supported_products().size());
        EXPECT_EQ(dds::remote_config::protocol::capabilities_e::ASM_ACTIVATION,
            listener.get_supported_products()["ASM_FEATURES"]);
    }

    { // Api security disabled
        remote_config::asm_features_listener listener(
            service_config, false, true);
        EXPECT_EQ(1, listener.get_supported_products().size());
        EXPECT_EQ(dds::remote_config::protocol::capabilities_e::
                      ASM_API_SECURITY_SAMPLE_RATE,
            listener.get_supported_products()["ASM_FEATURES"]);
    }

    { // All enabled
        remote_config::asm_features_listener listener(
            service_config, true, true);
        EXPECT_EQ(1, listener.get_supported_products().size());
        EXPECT_EQ(dds::remote_config::protocol::capabilities_e::ASM_ACTIVATION |
                      dds::remote_config::protocol::capabilities_e::
                          ASM_API_SECURITY_SAMPLE_RATE,
            listener.get_supported_products()["ASM_FEATURES"]);
    }
}

} // namespace dds
