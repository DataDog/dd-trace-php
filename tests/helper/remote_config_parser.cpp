// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include <rapidjson/document.h>
#include <string>

#include "common.hpp"
#include "remote_config/protocol/tuf/parser.hpp"

namespace dds {

std::string get_example_response()
{
    std::string response(
        "{\"roots\": [], \"targets\": "
        "\"ewogICAgInNpZ25hdHVyZXMiOiBbCiAgICAgICAgewogICAgICAgICAgICAia2V5aWQi"
        "OiAiNWM0ZWNlNDEyNDFhMWJiNTEzZjZlM2U1ZGY3NGFiN2Q1MTgzZGZmZmJkNzFiZmQ0Mz"
        "EyNzkyMGQ4ODA1NjlmZCIsCiAgICAgICAgICAgICJzaWciOiAiNDliOTBmNWY0YmZjMjdj"
        "Y2JkODBkOWM4NDU4ZDdkMjJiYTlmYTA4OTBmZDc3NWRkMTE2YzUyOGIzNmRkNjA1YjFkZj"
        "c2MWI4N2I2YzBlYjliMDI2NDA1YTEzZWZlZjQ4Mjc5MzRkNmMyNWE3ZDZiODkyNWZkYTg5"
        "MjU4MDkwMGYiCiAgICAgICAgfQogICAgXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgIl"
        "90eXBlIjogInRhcmdldHMiLAogICAgICAgICJjdXN0b20iOiB7CiAgICAgICAgICAgICJv"
        "cGFxdWVfYmFja2VuZF9zdGF0ZSI6ICJleUoyWlhKemFXOXVJam94TENKemRHRjBaU0k2ZX"
        "lKbWFXeGxYMmhoYzJobGN5STZXeUpTS3pKRFZtdGxkRVJ6WVc1cFdrZEphMFphWkZKTlQy"
        "RllhM1Z6TURGMWVsUTFNM3BuZW1sU1RHRTBQU0lzSWtJd1dtTTNUMUlyVWxWTGNuZE9iMF"
        "ZFV2pZM1VYVjVXRWxyYTJjeGIyTkhWV1IzZWtac1MwZERaRlU5SWl3aWVIRnFUbFV4VFV4"
        "WFUzQlJiRFpOYWt4UFUyTnZTVUoyYjNsU2VsWnJkelp6TkdFcmRYVndPV2d3UVQwaVhYMT"
        "kiCiAgICAgICAgfSwKICAgICAgICAiZXhwaXJlcyI6ICIyMDIyLTExLTA0VDEzOjMxOjU5"
        "WiIsCiAgICAgICAgInNwZWNfdmVyc2lvbiI6ICIxLjAuMCIsCiAgICAgICAgInRhcmdldH"
        "MiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFtaWNfcmF0"
        "ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgICAgICAgIC"
        "AgICAgICAgICAidiI6IDM2NzQwCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAg"
        "ICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAic2hhMjU2IjogIjA3NDY1Y2"
        "VjZTQ3ZTQ1NDJhYmMwZGEwNDBkOWViYjQyZWM5NzIyNDkyMGQ2ODcwNjUxZGMzMzE2NTI4"
        "NjA5ZDUiLAogICAgICAgICAgICAgICAgICAgICJzaGE1MTIiOiAic2hhNTEyaGFzaGhlcm"
        "UwMSIKICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAibGVuZ3RoIjogNjYz"
        "OTkKICAgICAgICAgICAgfSwKICAgICAgICAgICAgImRhdGFkb2cvMi9ERUJVRy9sdWtlLn"
        "N0ZWVuc2VuL2NvbmZpZyI6IHsKICAgICAgICAgICAgICAgICJjdXN0b20iOiB7CiAgICAg"
        "ICAgICAgICAgICAgICAgInYiOiAzCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAgIC"
        "AgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAic2hhMjU2IjogImM2YThj"
        "ZDUzNTMwYjU5MmE1MDk3YTMyMzJjZTQ5Y2EwODA2ZmEzMjQ3MzU2NGMzYWIzODZiZWJhZW"
        "E3ZDg3NDAiLAogICAgICAgICAgICAgICAgICAgICJzaGE1MTIiOiAic2hhNTEyaGFzaGhl"
        "cmUwMiIKICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAibGVuZ3RoIjogMT"
        "MKICAgICAgICAgICAgfSwKICAgICAgICAgICAgImVtcGxveWVlL0RFQlVHX0RELzIudGVz"
        "dDEuY29uZmlnL2NvbmZpZyI6IHsKICAgICAgICAgICAgICAgICJjdXN0b20iOiB7CiAgIC"
        "AgICAgICAgICAgICAgICAgInYiOiAxCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAg"
        "ICAgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAic2hhMjU2IjogIjQ3ZW"
        "Q4MjU2NDdhZDBlYzZhNzg5OTE4ODkwNTY1ZDQ0YzM5YTVlNGJhY2QzNWJiMzRmOWRmMzgz"
        "Mzg5MTJkYWUiLAogICAgICAgICAgICAgICAgICAgICJzaGE1MTIiOiAic2hhNTEyaGFzaG"
        "hlcmUwMyIKICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAibGVuZ3RoIjog"
        "NDEKICAgICAgICAgICAgfQogICAgICAgIH0sCiAgICAgICAgInZlcnNpb24iOiAyNzQ4Nz"
        "E1NgogICAgfQp9\", \"target_files\": [{\"path\": "
        "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
        "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
        "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
        "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
        "[\"datadog/2/DEBUG/luke.steensen/config\", "
        "\"employee/DEBUG_DD/2.test1.config/config\"] }");

    return response;
}

void assert_parser_error(std::string payload,
    remote_config::protocol::remote_config_parser_result result)
{
    remote_config::protocol::remote_config_parser_result error;
    try {
        remote_config::protocol::parse(payload);
    } catch (remote_config::protocol::parser_exception &e) {
        error = e.get_error();
    }

    EXPECT_EQ(result, error);
}

TEST(RemoteConfigParser, ItReturnsErrorWhenInvalidBodyIsGiven)
{
    assert_parser_error("invalid_json",
        remote_config::protocol::remote_config_parser_result::invalid_json);
}

TEST(RemoteConfigParser, TargetsFieldIsRequired)
{
    assert_parser_error("{\"target_files\": [], \"client_configs\": [] }",
        remote_config::protocol::remote_config_parser_result::
            targets_field_missing);
}

TEST(RemoteConfigParser, TargetsFieldMustBeString)
{
    assert_parser_error(
        "{\"targets\": [], \"target_files\": [], \"client_configs\": [] }",
        remote_config::protocol::remote_config_parser_result::
            targets_field_invalid_type);
}

TEST(RemoteConfigParser, targetFilesFieldIsRequired)
{
    assert_parser_error("{\"targets\": \"\", \"client_configs\": [] }",
        remote_config::protocol::remote_config_parser_result::
            target_files_field_missing);
}

TEST(RemoteConfigParser, targetFilesFieldMustBeArray)
{
    assert_parser_error(
        "{\"targets\": \"\", \"target_files\": \"\", \"client_configs\": [] }",
        remote_config::protocol::remote_config_parser_result::
            target_files_field_invalid_type);
}

TEST(RemoteConfigParser, clientConfigsFieldIsRequired)
{
    assert_parser_error("{\"targets\": \"\", \"target_files\": [] }",
        remote_config::protocol::remote_config_parser_result::
            client_config_field_missing);
}

TEST(RemoteConfigParser, clientConfigsFieldMustBeArray)
{
    assert_parser_error(
        "{\"targets\": \"\", \"target_files\": [], \"client_configs\": \"\" }",
        remote_config::protocol::remote_config_parser_result::
            client_config_field_invalid_type);
}

TEST(RemoteConfigParser, TargetFilesAreParsed)
{
    std::string response = get_example_response();

    auto gcr = remote_config::protocol::parse(response);

    EXPECT_EQ(2, gcr.target_files.size());

    auto target_files = gcr.target_files;

    EXPECT_EQ("employee/DEBUG_DD/2.test1.config/config",
        target_files.find("employee/DEBUG_DD/2.test1.config/config")
            ->second.path);
    EXPECT_EQ("UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=",
        target_files.find("employee/DEBUG_DD/2.test1.config/config")
            ->second.raw);

    EXPECT_EQ("datadog/2/DEBUG/luke.steensen/config",
        target_files.find("datadog/2/DEBUG/luke.steensen/config")->second.path);
    EXPECT_EQ("aGVsbG8gdmVjdG9yIQ==",
        target_files.find("datadog/2/DEBUG/luke.steensen/config")->second.raw);
}

TEST(RemoteConfigParser, TargetFilesWithoutPathAreInvalid)
{
    assert_parser_error(
        "{\"roots\": [], \"targets\": \"b2s=\", \"target_files\": [{\"path\": "
        "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
        "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
        "{ \"raw\": "
        "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
        "[\"datadog/2/DEBUG/luke.steensen/config\", "
        "\"employee/DEBUG_DD/2.test1.config/config\"] }",
        remote_config::protocol::remote_config_parser_result::
            target_files_path_field_missing);
}

TEST(RemoteConfigParser, TargetFilesWithNonStringPathAreInvalid)
{
    assert_parser_error(
        "{\"roots\": [], \"targets\": \"b2s=\", \"target_files\": [{\"path\": "
        "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
        "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
        "{\"path\": [], \"raw\": "
        "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
        "[\"datadog/2/DEBUG/luke.steensen/config\", "
        "\"employee/DEBUG_DD/2.test1.config/config\"] }",
        remote_config::protocol::remote_config_parser_result::
            target_files_path_field_invalid_type);
}

TEST(RemoteConfigParser, TargetFilesWithoutRawAreInvalid)
{
    assert_parser_error(
        "{\"roots\": [], \"targets\": \"b2s=\", \"target_files\": [{\"path\": "
        "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
        "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
        "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\"} ], "
        "\"client_configs\": "
        "[\"datadog/2/DEBUG/luke.steensen/config\", "
        "\"employee/DEBUG_DD/2.test1.config/config\"] }",
        remote_config::protocol::remote_config_parser_result::
            target_files_raw_field_missing);
}

TEST(RemoteConfigParser, TargetFilesWithNonNonStringRawAreInvalid)
{
    assert_parser_error(
        "{\"roots\": [], \"targets\": \"b2s=\", \"target_files\": [{\"path\": "
        "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
        "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
        "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": []} ], "
        "\"client_configs\": "
        "[\"datadog/2/DEBUG/luke.steensen/config\", "
        "\"employee/DEBUG_DD/2.test1.config/config\"] }",
        remote_config::protocol::remote_config_parser_result::
            target_files_raw_field_invalid_type);
}

TEST(RemoteConfigParser, TargetFilesMustBeObjects)
{
    assert_parser_error(
        "{\"roots\": [], \"targets\": \"b2s=\", \"target_files\": [ "
        "\"invalid\", "
        "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
        "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
        "[\"datadog/2/DEBUG/luke.steensen/config\", "
        "\"employee/DEBUG_DD/2.test1.config/config\"] }",
        remote_config::protocol::remote_config_parser_result::
            target_files_object_invalid);
}

TEST(RemoteConfigParser, ClientConfigsAreParsed)
{
    std::string response = get_example_response();

    auto gcr = remote_config::protocol::parse(response);

    EXPECT_EQ(2, gcr.client_configs.size());

    auto client_configs = gcr.client_configs;

    EXPECT_EQ("datadog/2/DEBUG/luke.steensen/config", client_configs[0]);
    EXPECT_EQ("employee/DEBUG_DD/2.test1.config/config", client_configs[1]);
}

TEST(RemoteConfigParser, ClientConfigsMustBeStrings)
{
    assert_parser_error("{\"roots\": [], \"targets\": \"b2s=\", "
                        "\"target_files\": [], \"client_configs\": "
                        "[[\"invalid\"], "
                        "\"employee/DEBUG_DD/2.test1.config/config\"] }",
        remote_config::protocol::remote_config_parser_result::
            client_config_field_invalid_entry);
}

TEST(RemoteConfigParser, TargetsMustBeNotEmpty)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": \"\", "
         "\"target_files\": [{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");

    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            targets_field_empty);
}

TEST(RemoteConfigParser, TargetsnMustBeValidBase64Encoded)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": \"nonValid%Base64Here\", "
         "\"target_files\": [{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            targets_field_invalid_base64);
}

TEST(RemoteConfigParser, TargetsDecodedMustBeValidJson)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": \"nonJsonHere\", \"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            targets_field_invalid_json);
}

TEST(RemoteConfigParser, SignedFieldOnTargetsMustBeObject)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbCiAgICAgICAgewogICAgICAgICAgICAia2V5aWQ"
         "iOiAiNWM0ZWNlNDEyNDFhMWJiNTEzZjZlM2U1ZGY3NGFiN2Q1MTgzZGZmZmJkNzFiZmQ0"
         "MzEyNzkyMGQ4ODA1NjlmZCIsCiAgICAgICAgICAgICJzaWciOiAiNDliOTBmNWY0YmZjM"
         "jdjY2JkODBkOWM4NDU4ZDdkMjJiYTlmYTA4OTBmZDc3NWRkMTE2YzUyOGIzNmRkNjA1Yj"
         "FkZjc2MWI4N2I2YzBlYjliMDI2NDA1YTEzZWZlZjQ4Mjc5MzRkNmMyNWE3ZDZiODkyNWZ"
         "kYTg5MjU4MDkwMGYiCiAgICAgICAgfQogICAgXSwKICAgICJzaWduZWQiOiAiaW52YWxp"
         "ZCIKfQ==\", \"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            signed_targets_field_invalid);
}

TEST(RemoteConfigParser, SignedFieldOnTargetsMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbICAgICAgICAKICAgIF0KfQ==\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            signed_targets_field_missing);
}

TEST(RemoteConfigParser, _TypeFieldOnSignedTargetsMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgImN"
         "1c3RvbSI6IHsKICAgICAgICAgICAgIm9wYXF1ZV9iYWNrZW5kX3N0YXRlIjogInNvbWV0"
         "aGluZyIKICAgICAgICB9LAogICAgICAgICJleHBpcmVzIjogIjIwMjItMTEtMDRUMTM6M"
         "zE6NTlaIiwKICAgICAgICAic3BlY192ZXJzaW9uIjogIjEuMC4wIiwKICAgICAgICAidG"
         "FyZ2V0cyI6IHsKICAgICAgICAgICAgImRhdGFkb2cvMi9BUE1fU0FNUExJTkcvZHluYW1"
         "pY19yYXRlcy9jb25maWciOiB7CiAgICAgICAgICAgICAgICAiY3VzdG9tIjogewogICAg"
         "ICAgICAgICAgICAgICAgICJ2IjogMQogICAgICAgICAgICAgICAgfSwKICAgICAgICAgI"
         "CAgICAgICJoYXNoZXMiOiB7CiAgICAgICAgICAgICAgICAgICAgInNoYTI1NiI6ICJibG"
         "FoIiwKICAgICAgICAgICAgICAgICAgICAic2hhNTEyIjogInNoYTUxMmhhc2hoZXJlMDE"
         "iCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgImxlbmd0aCI6IDIKICAg"
         "ICAgICAgICAgfQogICAgICAgIH0sCiAgICAgICAgInZlcnNpb24iOiAyNzQ4NzE1NgogI"
         "CAgfQp9\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}], "
         "\"client_configs\": "
         "[ \"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            type_signed_targets_field_missing);
}

TEST(RemoteConfigParser, _TypeFieldOnSignedTargetsMustBeString)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgIl9"
         "0eXBlIjoge30sCiAgICAgICAgImN1c3RvbSI6IHsKICAgICAgICAgICAgIm9wYXF1ZV9i"
         "YWNrZW5kX3N0YXRlIjogInNvbWV0aGluZyIKICAgICAgICB9LAogICAgICAgICJleHBpc"
         "mVzIjogIjIwMjItMTEtMDRUMTM6MzE6NTlaIiwKICAgICAgICAic3BlY192ZXJzaW9uIj"
         "ogIjEuMC4wIiwKICAgICAgICAidGFyZ2V0cyI6IHsKICAgICAgICAgICAgImRhdGFkb2c"
         "vMi9BUE1fU0FNUExJTkcvZHluYW1pY19yYXRlcy9jb25maWciOiB7CiAgICAgICAgICAg"
         "ICAgICAiY3VzdG9tIjogewogICAgICAgICAgICAgICAgICAgICJ2IjogMQogICAgICAgI"
         "CAgICAgICAgfSwKICAgICAgICAgICAgICAgICJoYXNoZXMiOiB7CiAgICAgICAgICAgIC"
         "AgICAgICAgInNoYTI1NiI6ICJibGFoIiwKICAgICAgICAgICAgICAgICAgICAic2hhNTE"
         "yIjogInNoYTUxMmhhc2hoZXJlMDEiCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAg"
         "ICAgICAgImxlbmd0aCI6IDIKICAgICAgICAgICAgfQogICAgICAgIH0sCiAgICAgICAgI"
         "nZlcnNpb24iOiAyNzQ4NzE1NgogICAgfQp9\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}], "
         "\"client_configs\": "
         "[ \"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            type_signed_targets_field_invalid);
}

TEST(RemoteConfigParser, _TypeFieldOnSignedTargetsMustBeEqualToTargets)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgIl9"
         "0eXBlIjogIm5vbl9hY2NlcHRlZF90eXBlIiwKICAgICAgICAiY3VzdG9tIjogewogICAg"
         "ICAgICAgICAib3BhcXVlX2JhY2tlbmRfc3RhdGUiOiAic29tZXRoaW5nIgogICAgICAgI"
         "H0sCiAgICAgICAgImV4cGlyZXMiOiAiMjAyMi0xMS0wNFQxMzozMTo1OVoiLAogICAgIC"
         "AgICJzcGVjX3ZlcnNpb24iOiAiMS4wLjAiLAogICAgICAgICJ0YXJnZXRzIjogewogICA"
         "gICAgICAgICAiZGF0YWRvZy8yL0FQTV9TQU1QTElORy9keW5hbWljX3JhdGVzL2NvbmZp"
         "ZyI6IHsKICAgICAgICAgICAgICAgICJjdXN0b20iOiB7CiAgICAgICAgICAgICAgICAgI"
         "CAgInYiOiAxCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgImhhc2hlcy"
         "I6IHsKICAgICAgICAgICAgICAgICAgICAic2hhMjU2IjogImJsYWgiLAogICAgICAgICA"
         "gICAgICAgICAgICJzaGE1MTIiOiAic2hhNTEyaGFzaGhlcmUwMSIKICAgICAgICAgICAg"
         "ICAgIH0sCiAgICAgICAgICAgICAgICAibGVuZ3RoIjogMgogICAgICAgICAgICB9CiAgI"
         "CAgICAgfSwKICAgICAgICAidmVyc2lvbiI6IDI3NDg3MTU2CiAgICB9Cn0=\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}], "
         "\"client_configs\": "
         "[ \"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            type_signed_targets_field_invalid_type);
}

TEST(RemoteConfigParser, VersionFieldOnSignedTargetsMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbCiAgICBdLAogICAgInNpZ25lZCI6IHsKICAgICA"
         "gICAiY3VzdG9tIjogewogICAgICAgICAgICAib3BhcXVlX2JhY2tlbmRfc3RhdGUiOiAi"
         "ZXlKMlpYSnphVzl1SWpveExDSnpkR0YwWlNJNmV5Sm1hV3hsWDJoaGMyaGxjeUk2V3lKU"
         "0t6SkRWbXRsZEVSellXNXBXa2RKYTBaYVpGSk5UMkZZYTNWek1ERjFlbFExTTNwbmVtbF"
         "NUR0UwUFNJc0lrSXdXbU0zVDFJclVsVkxjbmRPYjBWRVdqWTNVWFY1V0VscmEyY3hiMk5"
         "IVldSM2VrWnNTMGREWkZVOUlpd2llSEZxVGxVeFRVeFhVM0JSYkRaTmFreFBVMk52U1VK"
         "MmIzbFNlbFpyZHpaek5HRXJkWFZ3T1dnd1FUMGlYWDE5IgogICAgICAgIH0sCiAgICAgI"
         "CAgInRhcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2"
         "R5bmFtaWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHs"
         "KICAgICAgICAgICAgICAgICAgICAidiI6IDM2NzQwCiAgICAgICAgICAgICAgICB9LAog"
         "ICAgICAgICAgICAgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAic2hhM"
         "jU2IjogIjA3NDY1Y2VjZTQ3ZTQ1NDJhYmMwZGEwNDBkOWViYjQyZWM5NzIyNDkyMGQ2OD"
         "cwNjUxZGMzMzE2NTI4NjA5ZDUiCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICA"
         "gICAgImxlbmd0aCI6IDY2Mzk5CiAgICAgICAgICAgIH0sCiAgICAgICAgICAgICJkYXRh"
         "ZG9nLzIvREVCVUcvbHVrZS5zdGVlbnNlbi9jb25maWciOiB7CiAgICAgICAgICAgICAgI"
         "CAiY3VzdG9tIjogewogICAgICAgICAgICAgICAgICAgICJ2IjogMwogICAgICAgICAgIC"
         "AgICAgfSwKICAgICAgICAgICAgICAgICJoYXNoZXMiOiB7CiAgICAgICAgICAgICAgICA"
         "gICAgInNoYTI1NiI6ICJjNmE4Y2Q1MzUzMGI1OTJhNTA5N2EzMjMyY2U0OWNhMDgwNmZh"
         "MzI0NzM1NjRjM2FiMzg2YmViYWVhN2Q4NzQwIgogICAgICAgICAgICAgICAgfSwKICAgI"
         "CAgICAgICAgICAgICJsZW5ndGgiOiAxMwogICAgICAgICAgICB9LAogICAgICAgICAgIC"
         "AiZW1wbG95ZWUvREVCVUdfREQvMi50ZXN0MS5jb25maWcvY29uZmlnIjogewogICAgICA"
         "gICAgICAgICAgImN1c3RvbSI6IHsKICAgICAgICAgICAgICAgICAgICAidiI6IDEKICAg"
         "ICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAiaGFzaGVzIjogewogICAgICAgI"
         "CAgICAgICAgICAgICJzaGEyNTYiOiAiNDdlZDgyNTY0N2FkMGVjNmE3ODk5MTg4OTA1Nj"
         "VkNDRjMzlhNWU0YmFjZDM1YmIzNGY5ZGYzODMzODkxMmRhZSIKICAgICAgICAgICAgICA"
         "gIH0sCiAgICAgICAgICAgICAgICAibGVuZ3RoIjogNDEKICAgICAgICAgICAgfQogICAg"
         "ICAgIH0KICAgIH0KfQ==\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            version_signed_targets_field_missing);
}

TEST(RemoteConfigParser, VersionFieldOnSignedTargetsMustBeNumber)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbCiAgICBdLAogICAgInNpZ25lZCI6IHsKICAgICA"
         "gICAiY3VzdG9tIjogewogICAgICAgICAgICAib3BhcXVlX2JhY2tlbmRfc3RhdGUiOiAi"
         "ZXlKMlpYSnphVzl1SWpveExDSnpkR0YwWlNJNmV5Sm1hV3hsWDJoaGMyaGxjeUk2V3lKU"
         "0t6SkRWbXRsZEVSellXNXBXa2RKYTBaYVpGSk5UMkZZYTNWek1ERjFlbFExTTNwbmVtbF"
         "NUR0UwUFNJc0lrSXdXbU0zVDFJclVsVkxjbmRPYjBWRVdqWTNVWFY1V0VscmEyY3hiMk5"
         "IVldSM2VrWnNTMGREWkZVOUlpd2llSEZxVGxVeFRVeFhVM0JSYkRaTmFreFBVMk52U1VK"
         "MmIzbFNlbFpyZHpaek5HRXJkWFZ3T1dnd1FUMGlYWDE5IgogICAgICAgIH0sCiAgICAgI"
         "CAgInRhcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2"
         "R5bmFtaWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHs"
         "KICAgICAgICAgICAgICAgICAgICAidiI6IDM2NzQwCiAgICAgICAgICAgICAgICB9LAog"
         "ICAgICAgICAgICAgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAic2hhM"
         "jU2IjogIjA3NDY1Y2VjZTQ3ZTQ1NDJhYmMwZGEwNDBkOWViYjQyZWM5NzIyNDkyMGQ2OD"
         "cwNjUxZGMzMzE2NTI4NjA5ZDUiCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICA"
         "gICAgImxlbmd0aCI6IDY2Mzk5CiAgICAgICAgICAgIH0sCiAgICAgICAgICAgICJkYXRh"
         "ZG9nLzIvREVCVUcvbHVrZS5zdGVlbnNlbi9jb25maWciOiB7CiAgICAgICAgICAgICAgI"
         "CAiY3VzdG9tIjogewogICAgICAgICAgICAgICAgICAgICJ2IjogMwogICAgICAgICAgIC"
         "AgICAgfSwKICAgICAgICAgICAgICAgICJoYXNoZXMiOiB7CiAgICAgICAgICAgICAgICA"
         "gICAgInNoYTI1NiI6ICJjNmE4Y2Q1MzUzMGI1OTJhNTA5N2EzMjMyY2U0OWNhMDgwNmZh"
         "MzI0NzM1NjRjM2FiMzg2YmViYWVhN2Q4NzQwIgogICAgICAgICAgICAgICAgfSwKICAgI"
         "CAgICAgICAgICAgICJsZW5ndGgiOiAxMwogICAgICAgICAgICB9LAogICAgICAgICAgIC"
         "AiZW1wbG95ZWUvREVCVUdfREQvMi50ZXN0MS5jb25maWcvY29uZmlnIjogewogICAgICA"
         "gICAgICAgICAgImN1c3RvbSI6IHsKICAgICAgICAgICAgICAgICAgICAidiI6IDEKICAg"
         "ICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAiaGFzaGVzIjogewogICAgICAgI"
         "CAgICAgICAgICAgICJzaGEyNTYiOiAiNDdlZDgyNTY0N2FkMGVjNmE3ODk5MTg4OTA1Nj"
         "VkNDRjMzlhNWU0YmFjZDM1YmIzNGY5ZGYzODMzODkxMmRhZSIKICAgICAgICAgICAgICA"
         "gIH0sCiAgICAgICAgICAgICAgICAibGVuZ3RoIjogNDEKICAgICAgICAgICAgfQogICAg"
         "ICAgIH0sCiAgICAgICAgInZlcnNpb24iOiB7fQogICAgfQp9\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            version_signed_targets_field_invalid);
}

TEST(RemoteConfigParser, CustomFieldOnSignedTargetsMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgICAgICJzaWduYXR1cmVzIjogWwogICAgICAgICAgICAgICAgewogICAgICA"
         "gICAgICAgICAgICAgICAgICAia2V5aWQiOiAiNWM0ZWNlNDEyNDFhMWJiNTEzZjZlM2U1"
         "ZGY3NGFiN2Q1MTgzZGZmZmJkNzFiZmQ0MzEyNzkyMGQ4ODA1NjlmZCIsCiAgICAgICAgI"
         "CAgICAgICAgICAgICAgICJzaWciOiAiNDliOTBmNWY0YmZjMjdjY2JkODBkOWM4NDU4ZD"
         "dkMjJiYTlmYTA4OTBmZDc3NWRkMTE2YzUyOGIzNmRkNjA1YjFkZjc2MWI4N2I2YzBlYjl"
         "iMDI2NDA1YTEzZWZlZjQ4Mjc5MzRkNmMyNWE3ZDZiODkyNWZkYTg5MjU4MDkwMGYiCiAg"
         "ICAgICAgICAgICAgICB9CiAgICAgICAgXSwKICAgICAgICAic2lnbmVkIjogewogICAgI"
         "CAgICAgICAgICAgIl90eXBlIjogInRhcmdldHMiLAogICAgICAgICAgICAgICAgImV4cG"
         "lyZXMiOiAiMjAyMi0xMS0wNFQxMzozMTo1OVoiLAogICAgICAgICAgICAgICAgInNwZWN"
         "fdmVyc2lvbiI6ICIxLjAuMCIsCiAgICAgICAgICAgICAgICAidGFyZ2V0cyI6IHsKICAg"
         "ICAgICAgICAgICAgICAgICAgICAgImRhdGFkb2cvMi9GRUFUVVJFUy9keW5hbWljX3Jhd"
         "GVzL2NvbmZpZyI6IHsKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAiY3VzdG"
         "9tIjogewogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgInYiOiA"
         "zNjc0MAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAg"
         "ICAgICAgICAgICAgICAgICAgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgI"
         "CAgICAgICAgICAgICAgICAgICAgICJzaGEyNTYiOiAiMDc0NjVjZWNlNDdlNDU0MmFiYz"
         "BkYTA0MGQ5ZWJiNDJlYzk3MjI0OTIwZDY4NzA2NTFkYzMzMTY1Mjg2MDlkNSIKICAgICA"
         "gICAgICAgICAgICAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgICAgICAg"
         "ICAgICAgICAgICJsZW5ndGgiOiA2NjM5OQogICAgICAgICAgICAgICAgICAgICAgICB9L"
         "AogICAgICAgICAgICAgICAgICAgICAgICAiZGF0YWRvZy8yL0ZFQVRVUkVTL2x1a2Uuc3"
         "RlZW5zZW4vY29uZmlnIjogewogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICJ"
         "jdXN0b20iOiB7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAi"
         "diI6IDMKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICB9LAogICAgICAgICAgI"
         "CAgICAgICAgICAgICAgICAgICAgICJoYXNoZXMiOiB7CiAgICAgICAgICAgICAgICAgIC"
         "AgICAgICAgICAgICAgICAgICAgICAic2hhMjU2IjogImM2YThjZDUzNTMwYjU5MmE1MDk"
         "3YTMyMzJjZTQ5Y2EwODA2ZmEzMjQ3MzU2NGMzYWIzODZiZWJhZWE3ZDg3NDAiCiAgICAg"
         "ICAgICAgICAgICAgICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgICAgICAgI"
         "CAgICAgICAgICAibGVuZ3RoIjogMTMKICAgICAgICAgICAgICAgICAgICAgICAgfSwKIC"
         "AgICAgICAgICAgICAgICAgICAgICAgImVtcGxveWVlL0ZFQVRVUkVTLzIudGVzdDEuY29"
         "uZmlnL2NvbmZpZyI6IHsKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAiY3Vz"
         "dG9tIjogewogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgInYiO"
         "iAxCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgIC"
         "AgICAgICAgICAgICAgICAgICAiaGFzaGVzIjogewogICAgICAgICAgICAgICAgICAgICA"
         "gICAgICAgICAgICAgICAgICAgInNoYTI1NiI6ICI0N2VkODI1NjQ3YWQwZWM2YTc4OTkx"
         "ODg5MDU2NWQ0NGMzOWE1ZTRiYWNkMzViYjM0ZjlkZjM4MzM4OTEyZGFlIgogICAgICAgI"
         "CAgICAgICAgICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAgICAgICAgIC"
         "AgICAgICAgImxlbmd0aCI6IDQxCiAgICAgICAgICAgICAgICAgICAgICAgIH0KICAgICA"
         "gICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAidmVyc2lvbiI6IDI3NDg3MTU2CiAg"
         "ICAgICAgfQp9\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            custom_signed_targets_field_missing);
}

TEST(RemoteConfigParser, CustomFieldOnSignedTargetsMustBeObject)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgICAgICJzaWduYXR1cmVzIjogWwogICAgICAgICAgICAgICAgewogICAgICA"
         "gICAgICAgICAgICAgICAgICAia2V5aWQiOiAiNWM0ZWNlNDEyNDFhMWJiNTEzZjZlM2U1"
         "ZGY3NGFiN2Q1MTgzZGZmZmJkNzFiZmQ0MzEyNzkyMGQ4ODA1NjlmZCIsCiAgICAgICAgI"
         "CAgICAgICAgICAgICAgICJzaWciOiAiNDliOTBmNWY0YmZjMjdjY2JkODBkOWM4NDU4ZD"
         "dkMjJiYTlmYTA4OTBmZDc3NWRkMTE2YzUyOGIzNmRkNjA1YjFkZjc2MWI4N2I2YzBlYjl"
         "iMDI2NDA1YTEzZWZlZjQ4Mjc5MzRkNmMyNWE3ZDZiODkyNWZkYTg5MjU4MDkwMGYiCiAg"
         "ICAgICAgICAgICAgICB9CiAgICAgICAgXSwKICAgICAgICAic2lnbmVkIjogewogICAgI"
         "CAgICAgICAgICAgIl90eXBlIjogInRhcmdldHMiLAogICAgICAgICAgICAgICAgImN1c3"
         "RvbSI6ICJpbnZhbGlkIiwKICAgICAgICAgICAgICAgICJleHBpcmVzIjogIjIwMjItMTE"
         "tMDRUMTM6MzE6NTlaIiwKICAgICAgICAgICAgICAgICJzcGVjX3ZlcnNpb24iOiAiMS4w"
         "LjAiLAogICAgICAgICAgICAgICAgInRhcmdldHMiOiB7CiAgICAgICAgICAgICAgICAgI"
         "CAgICAgICJkYXRhZG9nLzIvRkVBVFVSRVMvZHluYW1pY19yYXRlcy9jb25maWciOiB7Ci"
         "AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgICAgICA"
         "gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICJ2IjogMzY3NDAKICAgICAgICAg"
         "ICAgICAgICAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgICAgICAgICAgI"
         "CAgICAgICJoYXNoZXMiOiB7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIC"
         "AgICAgICAic2hhMjU2IjogIjA3NDY1Y2VjZTQ3ZTQ1NDJhYmMwZGEwNDBkOWViYjQyZWM"
         "5NzIyNDkyMGQ2ODcwNjUxZGMzMzE2NTI4NjA5ZDUiCiAgICAgICAgICAgICAgICAgICAg"
         "ICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAibGVuZ"
         "3RoIjogNjYzOTkKICAgICAgICAgICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgIC"
         "AgICAgICAgICAgImRhdGFkb2cvMi9GRUFUVVJFUy9sdWtlLnN0ZWVuc2VuL2NvbmZpZyI"
         "6IHsKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAiY3VzdG9tIjogewogICAg"
         "ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgInYiOiAzCiAgICAgICAgI"
         "CAgICAgICAgICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgICAgICAgICAgIC"
         "AgICAgICAiaGFzaGVzIjogewogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA"
         "gICAgICAgInNoYTI1NiI6ICJjNmE4Y2Q1MzUzMGI1OTJhNTA5N2EzMjMyY2U0OWNhMDgw"
         "NmZhMzI0NzM1NjRjM2FiMzg2YmViYWVhN2Q4NzQwIgogICAgICAgICAgICAgICAgICAgI"
         "CAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgImxlbm"
         "d0aCI6IDEzCiAgICAgICAgICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICA"
         "gICAgICAgICJlbXBsb3llZS9GRUFUVVJFUy8yLnRlc3QxLmNvbmZpZy9jb25maWciOiB7"
         "CiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgICAgI"
         "CAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICJ2IjogMQogICAgICAgICAgIC"
         "AgICAgICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAgICAgICAgICAgICA"
         "gICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg"
         "ICAgICJzaGEyNTYiOiAiNDdlZDgyNTY0N2FkMGVjNmE3ODk5MTg4OTA1NjVkNDRjMzlhN"
         "WU0YmFjZDM1YmIzNGY5ZGYzODMzODkxMmRhZSIKICAgICAgICAgICAgICAgICAgICAgIC"
         "AgICAgICAgICB9LAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICJsZW5ndGg"
         "iOiA0MQogICAgICAgICAgICAgICAgICAgICAgICB9CiAgICAgICAgICAgICAgICB9LAog"
         "ICAgICAgICAgICAgICAgInZlcnNpb24iOiAyNzQ4NzE1NgogICAgICAgIH0KfQ==\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            custom_signed_targets_field_invalid);
}

TEST(RemoteConfigParser,
    OpaqueBackendStateCustomFieldOnSignedTargetsMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgICAgICJzaWduYXR1cmVzIjogWwogICAgICAgICAgICAgICAgewogICAgICA"
         "gICAgICAgICAgICAgICAgICAia2V5aWQiOiAiNWM0ZWNlNDEyNDFhMWJiNTEzZjZlM2U1"
         "ZGY3NGFiN2Q1MTgzZGZmZmJkNzFiZmQ0MzEyNzkyMGQ4ODA1NjlmZCIsCiAgICAgICAgI"
         "CAgICAgICAgICAgICAgICJzaWciOiAiNDliOTBmNWY0YmZjMjdjY2JkODBkOWM4NDU4ZD"
         "dkMjJiYTlmYTA4OTBmZDc3NWRkMTE2YzUyOGIzNmRkNjA1YjFkZjc2MWI4N2I2YzBlYjl"
         "iMDI2NDA1YTEzZWZlZjQ4Mjc5MzRkNmMyNWE3ZDZiODkyNWZkYTg5MjU4MDkwMGYiCiAg"
         "ICAgICAgICAgICAgICB9CiAgICAgICAgXSwKICAgICAgICAic2lnbmVkIjogewogICAgI"
         "CAgICAgICAgICAgIl90eXBlIjogInRhcmdldHMiLAogICAgICAgICAgICAgICAgImN1c3"
         "RvbSI6IHt9LAogICAgICAgICAgICAgICAgImV4cGlyZXMiOiAiMjAyMi0xMS0wNFQxMzo"
         "zMTo1OVoiLAogICAgICAgICAgICAgICAgInNwZWNfdmVyc2lvbiI6ICIxLjAuMCIsCiAg"
         "ICAgICAgICAgICAgICAidGFyZ2V0cyI6IHsKICAgICAgICAgICAgICAgICAgICAgICAgI"
         "mRhdGFkb2cvMi9GRUFUVVJFUy9keW5hbWljX3JhdGVzL2NvbmZpZyI6IHsKICAgICAgIC"
         "AgICAgICAgICAgICAgICAgICAgICAgICAiY3VzdG9tIjogewogICAgICAgICAgICAgICA"
         "gICAgICAgICAgICAgICAgICAgICAgICAgInYiOiAzNjc0MAogICAgICAgICAgICAgICAg"
         "ICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI"
         "mhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIC"
         "JzaGEyNTYiOiAiMDc0NjVjZWNlNDdlNDU0MmFiYzBkYTA0MGQ5ZWJiNDJlYzk3MjI0OTI"
         "wZDY4NzA2NTFkYzMzMTY1Mjg2MDlkNSIKICAgICAgICAgICAgICAgICAgICAgICAgICAg"
         "ICAgICB9LAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICJsZW5ndGgiOiA2N"
         "jM5OQogICAgICAgICAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgICAgIC"
         "AgICAiZGF0YWRvZy8yL0ZFQVRVUkVTL2x1a2Uuc3RlZW5zZW4vY29uZmlnIjogewogICA"
         "gICAgICAgICAgICAgICAgICAgICAgICAgICAgICJjdXN0b20iOiB7CiAgICAgICAgICAg"
         "ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAidiI6IDMKICAgICAgICAgICAgICAgI"
         "CAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIC"
         "JoYXNoZXMiOiB7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA"
         "ic2hhMjU2IjogImM2YThjZDUzNTMwYjU5MmE1MDk3YTMyMzJjZTQ5Y2EwODA2ZmEzMjQ3"
         "MzU2NGMzYWIzODZiZWJhZWE3ZDg3NDAiCiAgICAgICAgICAgICAgICAgICAgICAgICAgI"
         "CAgICAgfSwKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAibGVuZ3RoIjogMT"
         "MKICAgICAgICAgICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgICAgICAgICA"
         "gImVtcGxveWVlL0ZFQVRVUkVTLzIudGVzdDEuY29uZmlnL2NvbmZpZyI6IHsKICAgICAg"
         "ICAgICAgICAgICAgICAgICAgICAgICAgICAiY3VzdG9tIjogewogICAgICAgICAgICAgI"
         "CAgICAgICAgICAgICAgICAgICAgICAgICAgInYiOiAxCiAgICAgICAgICAgICAgICAgIC"
         "AgICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAiaGF"
         "zaGVzIjogewogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgInNo"
         "YTI1NiI6ICI0N2VkODI1NjQ3YWQwZWM2YTc4OTkxODg5MDU2NWQ0NGMzOWE1ZTRiYWNkM"
         "zViYjM0ZjlkZjM4MzM4OTEyZGFlIgogICAgICAgICAgICAgICAgICAgICAgICAgICAgIC"
         "AgIH0sCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgImxlbmd0aCI6IDQxCiA"
         "gICAgICAgICAgICAgICAgICAgICAgIH0KICAgICAgICAgICAgICAgIH0sCiAgICAgICAg"
         "ICAgICAgICAidmVyc2lvbiI6IDI3NDg3MTU2CiAgICAgICAgfQp9\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            obs_custom_signed_targets_field_missing);
}

TEST(RemoteConfigParser,
    OpaqueBackendStateCustomFieldOnSignedTargetsMustBeString)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgICAgICJzaWduYXR1cmVzIjogWwogICAgICAgICAgICAgICAgewogICAgICA"
         "gICAgICAgICAgICAgICAgICAia2V5aWQiOiAiNWM0ZWNlNDEyNDFhMWJiNTEzZjZlM2U1"
         "ZGY3NGFiN2Q1MTgzZGZmZmJkNzFiZmQ0MzEyNzkyMGQ4ODA1NjlmZCIsCiAgICAgICAgI"
         "CAgICAgICAgICAgICAgICJzaWciOiAiNDliOTBmNWY0YmZjMjdjY2JkODBkOWM4NDU4ZD"
         "dkMjJiYTlmYTA4OTBmZDc3NWRkMTE2YzUyOGIzNmRkNjA1YjFkZjc2MWI4N2I2YzBlYjl"
         "iMDI2NDA1YTEzZWZlZjQ4Mjc5MzRkNmMyNWE3ZDZiODkyNWZkYTg5MjU4MDkwMGYiCiAg"
         "ICAgICAgICAgICAgICB9CiAgICAgICAgXSwKICAgICAgICAic2lnbmVkIjogewogICAgI"
         "CAgICAgICAgICAgIl90eXBlIjogInRhcmdldHMiLAogICAgICAgICAgICAgICAgImN1c3"
         "RvbSI6IHsKICAgICAgICAgICAgICAgICAgICAgICAgIm9wYXF1ZV9iYWNrZW5kX3N0YXR"
         "lIjoge30KICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAiZXhwaXJlcyI6"
         "ICIyMDIyLTExLTA0VDEzOjMxOjU5WiIsCiAgICAgICAgICAgICAgICAic3BlY192ZXJza"
         "W9uIjogIjEuMC4wIiwKICAgICAgICAgICAgICAgICJ0YXJnZXRzIjogewogICAgICAgIC"
         "AgICAgICAgICAgICAgICAiZGF0YWRvZy8yL0ZFQVRVUkVTL2R5bmFtaWNfcmF0ZXMvY29"
         "uZmlnIjogewogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICJjdXN0b20iOiB7"
         "CiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAidiI6IDM2NzQwC"
         "iAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgIC"
         "AgICAgICAgICAgICAgICAiaGFzaGVzIjogewogICAgICAgICAgICAgICAgICAgICAgICA"
         "gICAgICAgICAgICAgICAgInNoYTI1NiI6ICIwNzQ2NWNlY2U0N2U0NTQyYWJjMGRhMDQw"
         "ZDllYmI0MmVjOTcyMjQ5MjBkNjg3MDY1MWRjMzMxNjUyODYwOWQ1IgogICAgICAgICAgI"
         "CAgICAgICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAgICAgICAgICAgIC"
         "AgICAgImxlbmd0aCI6IDY2Mzk5CiAgICAgICAgICAgICAgICAgICAgICAgIH0sCiAgICA"
         "gICAgICAgICAgICAgICAgICAgICJkYXRhZG9nLzIvRkVBVFVSRVMvbHVrZS5zdGVlbnNl"
         "bi9jb25maWciOiB7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgImN1c3Rvb"
         "SI6IHsKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICJ2IjogMw"
         "ogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICA"
         "gICAgICAgICAgICAgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAgICAg"
         "ICAgICAgICAgICAgICAgICJzaGEyNTYiOiAiYzZhOGNkNTM1MzBiNTkyYTUwOTdhMzIzM"
         "mNlNDljYTA4MDZmYTMyNDczNTY0YzNhYjM4NmJlYmFlYTdkODc0MCIKICAgICAgICAgIC"
         "AgICAgICAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgICAgICAgICAgICA"
         "gICAgICJsZW5ndGgiOiAxMwogICAgICAgICAgICAgICAgICAgICAgICB9LAogICAgICAg"
         "ICAgICAgICAgICAgICAgICAiZW1wbG95ZWUvRkVBVFVSRVMvMi50ZXN0MS5jb25maWcvY"
         "29uZmlnIjogewogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICJjdXN0b20iOi"
         "B7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAidiI6IDEKICA"
         "gICAgICAgICAgICAgICAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgICAg"
         "ICAgICAgICAgICAgICJoYXNoZXMiOiB7CiAgICAgICAgICAgICAgICAgICAgICAgICAgI"
         "CAgICAgICAgICAgICAic2hhMjU2IjogIjQ3ZWQ4MjU2NDdhZDBlYzZhNzg5OTE4ODkwNT"
         "Y1ZDQ0YzM5YTVlNGJhY2QzNWJiMzRmOWRmMzgzMzg5MTJkYWUiCiAgICAgICAgICAgICA"
         "gICAgICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg"
         "ICAibGVuZ3RoIjogNDEKICAgICAgICAgICAgICAgICAgICAgICAgfQogICAgICAgICAgI"
         "CAgICAgfSwKICAgICAgICAgICAgICAgICJ2ZXJzaW9uIjogMjc0ODcxNTYKICAgICAgIC"
         "B9Cn0=\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            obs_custom_signed_targets_field_invalid);
}

TEST(RemoteConfigParser, TargetsFieldOnSignedTargetsMustBeObject)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbCiAgICBdLAogICAgInNpZ25lZCI6IHsKICAgICA"
         "gICAiY3VzdG9tIjogewogICAgICAgICAgICAib3BhcXVlX2JhY2tlbmRfc3RhdGUiOiBb"
         "XQogICAgICAgIH0sCiAgICAgICAgInRhcmdldHMiOiBbXSwKICAgICAgICAidmVyc2lvb"
         "iI6IDI3NDg3MTU2CiAgICB9Cn0=\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            targets_signed_targets_field_invalid);
}

TEST(RemoteConfigParser, TargetsFieldOnSignedTargetsMustExists)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbCiAgICBdLAogICAgInNpZ25lZCI6IHsKICAgICA"
         "gICAiY3VzdG9tIjogewogICAgICAgICAgICAib3BhcXVlX2JhY2tlbmRfc3RhdGUiOiBb"
         "XQogICAgICAgIH0sICAgICAgICAKICAgICAgICAidmVyc2lvbiI6IDI3NDg3MTU2CiAgI"
         "CB9Cn0=\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            targets_signed_targets_field_missing);
}

TEST(RemoteConfigParser, CustomOnPathMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgInR"
         "hcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFt"
         "aWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImhhc2hlcyI6IHsKICAgI"
         "CAgICAgICAgICAgICAgICAic2hhMjU2IjogIjA3NDY1Y2VjZTQ3ZTQ1NDJhYmMwZGEwND"
         "BkOWViYjQyZWM5NzIyNDkyMGQ2ODcwNjUxZGMzMzE2NTI4NjA5ZDUiCiAgICAgICAgICA"
         "gICAgICB9LAogICAgICAgICAgICAgICAgImxlbmd0aCI6IDY2Mzk5CiAgICAgICAgICAg"
         "IH0KICAgICAgICB9LAogICAgICAgICJ2ZXJzaW9uIjogMjc0ODcxNTYKICAgIH0KfQ=="
         "\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            custom_path_targets_field_missing);
}

TEST(RemoteConfigParser, CustomOnPathMustBeObject)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbCiAgICBdLAogICAgInNpZ25lZCI6IHsKICAgICA"
         "gICAiY3VzdG9tIjogewogICAgICAgICAgICAib3BhcXVlX2JhY2tlbmRfc3RhdGUiOiBb"
         "XQogICAgICAgIH0sCiAgICAgICAgInRhcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZ"
         "G9nLzIvQVBNX1NBTVBMSU5HL2R5bmFtaWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgIC"
         "AgICAgICAgImN1c3RvbSI6ICJpbnZhbGlkIiwKICAgICAgICAgICAgICAgICJoYXNoZXM"
         "iOiB7CiAgICAgICAgICAgICAgICAgICAgInNoYTI1NiI6ICIwNzQ2NWNlY2U0N2U0NTQy"
         "YWJjMGRhMDQwZDllYmI0MmVjOTcyMjQ5MjBkNjg3MDY1MWRjMzMxNjUyODYwOWQ1IgogI"
         "CAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgICJsZW5ndGgiOiA2NjM5OQogIC"
         "AgICAgICAgICB9CiAgICAgICAgfSwKICAgICAgICAidmVyc2lvbiI6IDI3NDg3MTU2CiA"
         "gICB9Cn0=\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            custom_path_targets_field_invalid);
}

TEST(RemoteConfigParser, VCustomOnPathMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgInR"
         "hcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFt"
         "aWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgI"
         "CAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAiaGFzaGVzIjogewogICAgICAgIC"
         "AgICAgICAgICAgICJzaGEyNTYiOiAiMDc0NjVjZWNlNDdlNDU0MmFiYzBkYTA0MGQ5ZWJ"
         "iNDJlYzk3MjI0OTIwZDY4NzA2NTFkYzMzMTY1Mjg2MDlkNSIKICAgICAgICAgICAgICAg"
         "IH0sCiAgICAgICAgICAgICAgICAibGVuZ3RoIjogNjYzOTkKICAgICAgICAgICAgfQogI"
         "CAgICAgIH0sCiAgICAgICAgInZlcnNpb24iOiAyNzQ4NzE1NgogICAgfQp9\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            v_path_targets_field_missing);
}

TEST(RemoteConfigParser, VCustomOnPathMustBeNumber)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgInR"
         "hcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFt"
         "aWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgI"
         "CAgICAgICAgICAgICAgICAidiI6ICJpbnZhbGlkIgogICAgICAgICAgICAgICAgfSwKIC"
         "AgICAgICAgICAgICAgICJoYXNoZXMiOiB7CiAgICAgICAgICAgICAgICAgICAgInNoYTI"
         "1NiI6ICIwNzQ2NWNlY2U0N2U0NTQyYWJjMGRhMDQwZDllYmI0MmVjOTcyMjQ5MjBkNjg3"
         "MDY1MWRjMzMxNjUyODYwOWQ1IgogICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgI"
         "CAgICJsZW5ndGgiOiA2NjM5OQogICAgICAgICAgICB9CiAgICAgICAgfSwKICAgICAgIC"
         "AidmVyc2lvbiI6IDI3NDg3MTU2CiAgICB9Cn0=\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            v_path_targets_field_invalid);
}

TEST(RemoteConfigParser, HashesOnPathMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgInR"
         "hcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFt"
         "aWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgI"
         "CAgICAgICAgICAgICAgICAidiI6IDM2NzQwCiAgICAgICAgICAgICAgICB9LAogICAgIC"
         "AgICAgICAgICAgImxlbmd0aCI6IDY2Mzk5CiAgICAgICAgICAgIH0KICAgICAgICB9LAo"
         "gICAgICAgICJ2ZXJzaW9uIjogMjc0ODcxNTYKICAgIH0KfQ==\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            hashes_path_targets_field_missing);
}

TEST(RemoteConfigParser, HashesOnPathMustBeObject)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgInR"
         "hcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFt"
         "aWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgI"
         "CAgICAgICAgICAgICAgICAidiI6IDM2NzQwCiAgICAgICAgICAgICAgICB9LAogICAgIC"
         "AgICAgICAgICAgImhhc2hlcyI6ICJpbnZhbGlkIiwKICAgICAgICAgICAgICAgICJsZW5"
         "ndGgiOiA2NjM5OQogICAgICAgICAgICB9CiAgICAgICAgfSwKICAgICAgICAidmVyc2lv"
         "biI6IDI3NDg3MTU2CiAgICB9Cn0=\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            hashes_path_targets_field_invalid);
}

TEST(RemoteConfigParser, AtLeastOneHashMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgInR"
         "hcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFt"
         "aWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgI"
         "CAgICAgICAgICAgICAgICAidiI6IDM2NzQwCiAgICAgICAgICAgICAgICB9LAogICAgIC"
         "AgICAgICAgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICA"
         "gICAgICAibGVuZ3RoIjogNjYzOTkKICAgICAgICAgICAgfQogICAgICAgIH0sCiAgICAg"
         "ICAgInZlcnNpb24iOiAyNzQ4NzE1NgogICAgfQp9\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            hashes_path_targets_field_empty);
}

TEST(RemoteConfigParser, HashesOnPathMustBeString)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgInR"
         "hcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFt"
         "aWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgI"
         "CAgICAgICAgICAgICAgICAidiI6IDM2NzQwCiAgICAgICAgICAgICAgICB9LAogICAgIC"
         "AgICAgICAgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAic2hhMjU2Ijo"
         "ge30KICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICAibGVuZ3RoIjogNjYz"
         "OTkKICAgICAgICAgICAgfQogICAgICAgIH0sCiAgICAgICAgInZlcnNpb24iOiAyNzQ4N"
         "zE1NgogICAgfQp9\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            hash_hashes_path_targets_field_invalid);
}

TEST(RemoteConfigParser, LengthOnPathMustBePresent)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgInR"
         "hcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFt"
         "aWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgI"
         "CAgICAgICAgICAgICAgICAidiI6IDM2NzQwCiAgICAgICAgICAgICAgICB9LAogICAgIC"
         "AgICAgICAgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAic2hhMjU2Ijo"
         "gIjA3NDY1Y2VjZTQ3ZTQ1NDJhYmMwZGEwNDBkOWViYjQyZWM5NzIyNDkyMGQ2ODcwNjUx"
         "ZGMzMzE2NTI4NjA5ZDUiCiAgICAgICAgICAgICAgICB9CiAgICAgICAgICAgIH0KICAgI"
         "CAgICB9LAogICAgICAgICJ2ZXJzaW9uIjogMjc0ODcxNTYKICAgIH0KfQ==\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            length_path_targets_field_missing);
}

TEST(RemoteConfigParser, LengthOnPathMustBeString)
{
    std::string invalid_response =
        ("{\"roots\": [], \"targets\": "
         "\"ewogICAgInNpZ25hdHVyZXMiOiBbXSwKICAgICJzaWduZWQiOiB7CiAgICAgICAgInR"
         "hcmdldHMiOiB7CiAgICAgICAgICAgICJkYXRhZG9nLzIvQVBNX1NBTVBMSU5HL2R5bmFt"
         "aWNfcmF0ZXMvY29uZmlnIjogewogICAgICAgICAgICAgICAgImN1c3RvbSI6IHsKICAgI"
         "CAgICAgICAgICAgICAgICAidiI6IDM2NzQwCiAgICAgICAgICAgICAgICB9LAogICAgIC"
         "AgICAgICAgICAgImhhc2hlcyI6IHsKICAgICAgICAgICAgICAgICAgICAic2hhMjU2Ijo"
         "gIjA3NDY1Y2VjZTQ3ZTQ1NDJhYmMwZGEwNDBkOWViYjQyZWM5NzIyNDkyMGQ2ODcwNjUx"
         "ZGMzMzE2NTI4NjA5ZDUiCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgI"
         "mxlbmd0aCI6ICJpbnZhbGlkIgogICAgICAgICAgICB9CiAgICAgICAgfSwKICAgICAgIC"
         "AidmVyc2lvbiI6IDI3NDg3MTU2CiAgICB9Cn0=\", "
         "\"target_files\": "
         "[{\"path\": "
         "\"employee/DEBUG_DD/2.test1.config/config\", \"raw\": "
         "\"UmVtb3RlIGNvbmZpZ3VyYXRpb24gaXMgc3VwZXIgc3VwZXIgY29vbAo=\"}, "
         "{\"path\": \"datadog/2/DEBUG/luke.steensen/config\", \"raw\": "
         "\"aGVsbG8gdmVjdG9yIQ==\"} ], \"client_configs\": "
         "[\"datadog/2/DEBUG/luke.steensen/config\", "
         "\"employee/DEBUG_DD/2.test1.config/config\"] }");
    assert_parser_error(invalid_response,
        remote_config::protocol::remote_config_parser_result::
            length_path_targets_field_invalid);
}

TEST(RemoteConfigParser, TargetsAreParsed)
{
    std::string response = get_example_response();

    auto gcr = remote_config::protocol::parse(response);

    remote_config::protocol::targets _targets = gcr.targets;

    EXPECT_EQ(27487156, _targets.version);

    std::unordered_map<std::string, remote_config::protocol::path> paths = _targets.paths;

    EXPECT_EQ(3, paths.size());

    auto path_itr = paths.find("datadog/2/APM_SAMPLING/dynamic_rates/config");
    auto temp_path = path_itr->second;
    EXPECT_EQ(36740, temp_path.custom_v);
    EXPECT_EQ(2, temp_path.hashes.size());
    EXPECT_EQ(
        "07465cece47e4542abc0da040d9ebb42ec97224920d6870651dc3316528609d5",
        temp_path.hashes["sha256"]);
    EXPECT_EQ("sha512hashhere01", temp_path.hashes["sha512"]);
    EXPECT_EQ(66399, temp_path.length);

    path_itr = paths.find("datadog/2/DEBUG/luke.steensen/config");
    temp_path = path_itr->second;
    EXPECT_EQ(3, temp_path.custom_v);
    EXPECT_EQ(2, temp_path.hashes.size());
    EXPECT_EQ(
        "c6a8cd53530b592a5097a3232ce49ca0806fa32473564c3ab386bebaea7d8740",
        temp_path.hashes["sha256"]);
    EXPECT_EQ("sha512hashhere02", temp_path.hashes["sha512"]);
    EXPECT_EQ(13, temp_path.length);

    path_itr = paths.find("employee/DEBUG_DD/2.test1.config/config");
    temp_path = path_itr->second;
    EXPECT_EQ(1, temp_path.custom_v);
    EXPECT_EQ(2, temp_path.hashes.size());
    EXPECT_EQ(
        "47ed825647ad0ec6a789918890565d44c39a5e4bacd35bb34f9df38338912dae",
        temp_path.hashes["sha256"]);
    EXPECT_EQ("sha512hashhere03", temp_path.hashes["sha512"]);
    EXPECT_EQ(41, temp_path.length);
}

TEST(RemoteConfigParser, RemoteConfigParserResultCanBeCastToString)
{
    EXPECT_EQ("success",
        remote_config::protocol::remote_config_parser_result_to_str(
            remote_config::protocol::remote_config_parser_result::success));
    EXPECT_EQ("target_files_path_field_invalid_type",
        remote_config::protocol::remote_config_parser_result_to_str(
            remote_config::protocol::remote_config_parser_result::
                target_files_path_field_invalid_type));
    EXPECT_EQ("length_path_targets_field_missing",
        remote_config::protocol::remote_config_parser_result_to_str(
            remote_config::protocol::remote_config_parser_result::
                length_path_targets_field_missing));
    EXPECT_EQ("", remote_config::protocol::remote_config_parser_result_to_str(
                      remote_config::protocol::remote_config_parser_result::
                          num_of_values));
}

} // namespace dds
