// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../mocks.hpp"
#include "remote_config/exception.hpp"
#include "remote_config/listeners/asm_features_listener.hpp"
#include "service_config.hpp"

namespace dds {

namespace rcmock = remote_config::mock;

remote_config::config get_auto_user_instrum_config(
    const std::string mode = "\"identification\"")
{
    const std::string content =
        R"({"auto_user_instrum": { "mode": )" + mode + " }}";
    return rcmock::get_config("ASM_FEATURES", content);
}

remote_config::config get_asm_enabled_config(bool as_string = true)
{
    std::string value = as_string ? "\"true\"" : "true";
    const std::string content = R"({"asm": { "enabled": )" + value + " }}";
    return rcmock::get_config("ASM_FEATURES", content);
}

remote_config::config get_asm_disabled_config(bool as_string = true)
{
    std::string value = as_string ? "\"false\"" : "false";
    const std::string content = R"({"asm": { "enabled": )" + value + " }}";
    return rcmock::get_config("ASM_FEATURES", content);
}

TEST(RemoteConfigAsmFeaturesListener, NotSetByDefault)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AsmSetToEnabledAsString)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_enabled_config());
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AsmSetToEnabledAsBool)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_enabled_config(false));
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AsmSetToDisabledAsString)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_disabled_config());
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::DISABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AsmSetToDisabledAsBool)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_disabled_config(false));
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::DISABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AsmParserIsCaseInsensitive)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        const std::string content = R"({"asm": { "enabled": "TrUe" }})";
        listener.on_update(rcmock::get_config("ASM_FEATURES", content));
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AutoUserInstrumSetToIdentication)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_enabled_config());
        listener.on_update(get_auto_user_instrum_config());
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::IDENTIFICATION,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AutoUserInstrumSetToAnon)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_enabled_config());
        listener.on_update(get_auto_user_instrum_config("\"anonymization\""));
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::ANONYMIZATION,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AutoUserInstrumSetToDisabled)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_enabled_config());
        listener.on_update(get_auto_user_instrum_config("\"disabled\""));
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::DISABLED,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AutoUserInstrumUnknownValue)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_enabled_config());
        listener.on_update(get_auto_user_instrum_config("\"invalid\""));
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNKNOWN,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AutoUserInstrumIgnoreInvalidType)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    std::string error_message = "";
    std::string expected_error_message = "Invalid type for auto_user_instrum";

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    listener.on_update(get_asm_enabled_config());

    try {
        const std::string content = R"({"auto_user_instrum": 123 })";
        listener.on_update(rcmock::get_config("ASM_FEATURES", content));
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    listener.commit();

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener, AutoUserInstrumWithoutMode)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_enabled_config());

        const std::string content = R"({"auto_user_instrum": {}})";
        listener.on_update(rcmock::get_config("ASM_FEATURES", content));

        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNKNOWN,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AutoUserInstrumInvalidModeType)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_asm_enabled_config());

        const std::string content = R"({"auto_user_instrum": { "mode": 123 }})";
        listener.on_update(rcmock::get_config("ASM_FEATURES", content));

        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::ENABLED,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNKNOWN,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, AutoUserInstrumWithoutAsm)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    try {
        listener.on_update(get_auto_user_instrum_config());
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::IDENTIFICATION,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, ErrorConfigInvalidContentBase64)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    std::string invalid_content = "&&&";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";

    remote_config::config non_base_64_content_config =
        rcmock::get_config("ASM_FEATURES", invalid_content);

    try {
        listener.on_update(non_base_64_content_config);
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener, ErrorConfigInvalidJsonContent)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    std::string invalid_content = "invalidJsonContent";

    remote_config::config config =
        rcmock::get_config("ASM_FEATURES", invalid_content);

    try {
        listener.on_update(config);
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener, ErrorConfigAsmKeyMissing)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    remote_config::config config = rcmock::get_config("ASM_FEATURES", "{}");

    try {
        listener.on_update(config);
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, ErrorConfigInvalidAsmKey)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    std::string error_message = "";
    std::string expected_error_message = "Invalid type for asm";

    remote_config::config config =
        rcmock::get_config("ASM_FEATURES", R"({ "asm": 123 })");

    try {
        listener.on_update(config);
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
    EXPECT_EQ(0, error_message.compare(expected_error_message));
}

TEST(RemoteConfigAsmFeaturesListener, ErrorConfigEnabledKeyMissing)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    remote_config::config config =
        rcmock::get_config("ASM_FEATURES", R"({ "asm": { "disabled": true }})");

    try {
        listener.on_update(config);
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
}

TEST(RemoteConfigAsmFeaturesListener, ErrorConfigInvalidEnabledKey)
{
    auto remote_config_service = std::make_shared<service_config>();
    remote_config::asm_features_listener listener(remote_config_service);
    listener.init();

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());

    remote_config::config config =
        rcmock::get_config("ASM_FEATURES", R"({ "asm": { "enabled": 123 }})");

    try {
        listener.on_update(config);
        listener.commit();
    } catch (remote_config::error_applying_config &error) {
        std::cout << error.what() << std::endl;
    }

    EXPECT_EQ(enable_asm_status::NOT_SET,
        remote_config_service->get_asm_enabled_status());
    EXPECT_EQ(auto_user_instrum_mode::UNDEFINED,
        remote_config_service->get_auto_user_intrum_mode());
}
} // namespace dds