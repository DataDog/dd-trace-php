// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <config.hpp>

namespace dds {

namespace {
template <typename T, size_t size> constexpr size_t vsize(T (&)[size])
{
    return size;
}
} // namespace

TEST(ConfigTest, ValidConstruction)
{
    char *argv[] = {const_cast<char *>("tester"), const_cast<char *>("--key"),
        const_cast<char *>("value"), nullptr};
    EXPECT_NO_THROW(config::config(vsize(argv) - 1, argv));
}

TEST(ConfigTest, NonNullTerminatedListConstruction)
{
    char *argv[] = {const_cast<char *>("a"), const_cast<char *>("b")};
    EXPECT_NO_THROW(config::config(vsize(argv), argv));
}

TEST(ConfigTest, InvalidParameter)
{
    char *argv[] = {const_cast<char *>("tester"),
        const_cast<char *>("parameter_missing_dashes"), nullptr};
    EXPECT_NO_THROW(config::config(vsize(argv) - 1, argv));
}

TEST(ConfigTest, TestDefaultKeys)
{
    config::config cfg(0, nullptr);
    EXPECT_NO_THROW(cfg.get<std::string_view>("lock_path"));
    EXPECT_NO_THROW(cfg.get<std::string_view>("socket_path"));
    EXPECT_NO_THROW(cfg.get<std::string_view>("log_level"));
}

TEST(ConfigTest, TestDefaultOverride)
{
    char *argv[] = {const_cast<char *>("tester"),
        const_cast<char *>("--lock_path"), const_cast<char *>("unknown"),
        const_cast<char *>("--socket_path"), const_cast<char *>("unknown"),
        const_cast<char *>("--log_level"), const_cast<char *>("unknown"),
        nullptr};

    config::config cfg(vsize(argv) - 1, argv);
    EXPECT_TRUE(cfg.get<std::string_view>("lock_path") == "unknown");
    EXPECT_TRUE(cfg.get<std::string_view>("socket_path") == "unknown");
    EXPECT_TRUE(cfg.get<std::string_view>("log_level") == "unknown");
}

TEST(ConfigTest, TestInvalidKeys)
{
    config::config cfg(0, nullptr);
    EXPECT_THROW(cfg.get<std::string_view>("invalid"), std::out_of_range);
}

TEST(ConfigTest, TestKeyValue)
{
    char *argv[] = {const_cast<char *>("tester"), const_cast<char *>("--a_key"),
        const_cast<char *>("a_value"), const_cast<char *>("--b_key"),
        const_cast<char *>("b_value"), const_cast<char *>("--c_key=c_value"),
        nullptr};

    config::config cfg(vsize(argv) - 1, argv);
    EXPECT_TRUE(cfg.get<std::string_view>("a_key") == "a_value");
    EXPECT_TRUE(cfg.get<std::string_view>("b_key") == "b_value");
    EXPECT_TRUE(cfg.get<std::string>("c_key") == "c_value");
}

TEST(ConfigTest, TestModifiers)
{
    int argc = 2;
    char *argv[] = {const_cast<char *>("tester"),
        const_cast<char *>("--modifier"), nullptr};

    config::config cfg(argc, argv);
    EXPECT_TRUE(cfg.get<bool>("modifier"));
}
} // namespace dds
