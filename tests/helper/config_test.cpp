// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include <config.hpp>
#include "common.hpp"

namespace dds {

TEST(ConfigTest, ValidConstruction) {
    int argc = 3;
    char *argv[] = {const_cast<char *>("tester"), const_cast<char *>("--key"),
        const_cast<char *>("value"), nullptr};
    EXPECT_NO_THROW(config::config(argc, argv));
}

TEST(ConfigTest, NonNullTerminatedListConstruction) {
    int argc = 2;
    char *argv[] = {const_cast<char *>("a"), const_cast<char *>("b")};

    EXPECT_NO_THROW(config::config(argc, argv));
}

TEST(ConfigTest, TestDefaultKeys) {
    config::config cfg(0, nullptr);
    EXPECT_NO_THROW(cfg.get<std::string_view>("lock_path"));
    EXPECT_NO_THROW(cfg.get<std::string_view>("socket_path"));
    EXPECT_NO_THROW(cfg.get<std::string_view>("log_level"));
}

TEST(ConfigTest, TestDefaultOverride) {
    int argc = 7;
    char *argv[] = {const_cast<char *>("tester"),
        const_cast<char *>("--lock_path"), const_cast<char *>("unknown"),
        const_cast<char *>("--socket_path"), const_cast<char *>("unknown"),
        const_cast<char *>("--log_level"), const_cast<char *>("unknown"),
        nullptr};

    config::config cfg(argc, argv);
    EXPECT_TRUE(cfg.get<std::string_view>("lock_path") == "unknown");
    EXPECT_TRUE(cfg.get<std::string_view>("socket_path") == "unknown");
    EXPECT_TRUE(cfg.get<std::string_view>("log_level") == "unknown");
}

TEST(ConfigTest, TestInvalidKeys) {
    config::config cfg(0, nullptr);
    EXPECT_THROW(cfg.get<std::string_view>("invalid"), std::out_of_range);
}

TEST(ConfigTest, TestKeyValue) {
    int argc = 7;
    char *argv[] = {const_cast<char *>("tester"), const_cast<char *>("--a_key"),
        const_cast<char *>("a_value"), const_cast<char *>("--b_key"),
        const_cast<char *>("b_value"), const_cast<char *>("--c_key"),
        const_cast<char *>("c_value"), nullptr};

    config::config cfg(argc, argv);
    EXPECT_TRUE(cfg.get<std::string_view>("a_key") == "a_value");
    EXPECT_TRUE(cfg.get<std::string_view>("b_key") == "b_value");
    EXPECT_TRUE(cfg.get<std::string_view>("c_key") == "c_value");
}

TEST(ConfigTest, TestModifiers) {
    int argc = 2;
    char *argv[] = {const_cast<char *>("tester"),
        const_cast<char *>("--modifier"), nullptr};

    config::config cfg(argc, argv);
    EXPECT_TRUE(cfg.get<bool>("modifier"));
}
} // namespace dds
