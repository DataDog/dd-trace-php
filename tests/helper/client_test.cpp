// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <client.hpp>
#include <network/broker.hpp>
#include <rapidjson/document.h>
#include <tags.hpp>

namespace dds {

namespace mock {
class broker : public dds::network::base_broker {
public:
    MOCK_CONST_METHOD1(
        recv, network::request(std::chrono::milliseconds initial_timeout));
    MOCK_CONST_METHOD1(
        send, bool(const std::vector<std::shared_ptr<network::base_response>>
                      &messages));
    MOCK_CONST_METHOD1(
        send, bool(const std::shared_ptr<network::base_response> &message));
};

class service_manager : public dds::service_manager {
public:
    MOCK_METHOD(std::shared_ptr<dds::service>, create_service,
        (dds::service_identifier && id, const dds::engine_settings &settings,
            const dds::remote_config::settings &rc_settings,
            (std::map<std::string_view, std::string> & meta),
            (std::map<std::string_view, double> & metrics),
            bool dynamic_enablement),
        (override));
};
} // namespace mock

void set_extension_configuration_to(
    mock::broker *broker, client &c, std::optional<bool> status)
{
    // Client Init
    auto fn = create_sample_rules_ok();
    network::client_init::request msg;
    msg.pid = 1729;
    msg.enabled_configuration = status;
    msg.runtime_version = "1.0";
    msg.client_version = "2.0";
    msg.engine_settings.rules_file = fn;
    msg.engine_settings.waf_timeout_us = 1000000;

    network::request req(std::move(msg));

    std::shared_ptr<network::base_response> res;
    EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
    EXPECT_CALL(*broker,
        send(testing::An<const std::shared_ptr<network::base_response> &>()))
        .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

    c.run_client_init();
}

void request_init(mock::broker *broker, client &c)
{
    network::request_init::request msg;
    msg.data = parameter::map();
    msg.data.add(
        "server.request.headers.no_cookies", parameter::string("Arachni"sv));

    network::request req(std::move(msg));

    std::shared_ptr<network::base_response> res;
    EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
    EXPECT_CALL(*broker,
        send(testing::An<const std::shared_ptr<network::base_response> &>()))
        .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

    EXPECT_TRUE(c.run_request());
    auto msg_res = dynamic_cast<network::request_init::response *>(res.get());
    EXPECT_STREQ(msg_res->verdict.c_str(), "ok");
    EXPECT_EQ(msg_res->triggers.size(), 0);
}

constexpr auto EXTENSION_CONFIGURATION_NOT_SET = std::nullopt;
constexpr bool EXTENSION_CONFIGURATION_ENABLED = true;
constexpr bool EXTENSION_CONFIGURATION_DISABLED = false;

TEST(ClientTest, ClientInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_ok();

    network::client_init::request msg;
    msg.pid = 1729;
    msg.runtime_version = "1.0";
    msg.client_version = "2.0";
    msg.engine_settings.rules_file = fn;

    network::request req(std::move(msg));

    std::shared_ptr<network::base_response> res;
    EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
    EXPECT_CALL(*broker,
        send(testing::An<const std::shared_ptr<network::base_response> &>()))
        .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

    EXPECT_TRUE(c.run_client_init());

    auto msg_res = dynamic_cast<network::client_init::response *>(res.get());

    EXPECT_STREQ(msg_res->status.c_str(), "ok");
    EXPECT_EQ(msg_res->meta.size(), 2);
    EXPECT_STREQ(msg_res->meta[tag::waf_version].c_str(), "1.9.0");
    EXPECT_STREQ(msg_res->meta[tag::event_rules_errors].c_str(), "{}");

    EXPECT_EQ(msg_res->metrics.size(), 2);
    // For small enough integers this comparison should work, otherwise replace
    // with EXPECT_NEAR.
    EXPECT_EQ(msg_res->metrics[tag::event_rules_loaded], 3.0);
    EXPECT_EQ(msg_res->metrics[tag::event_rules_failed], 0.0);
}

TEST(ClientTest, ClientInitInvalidRules)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_invalid();

    network::client_init::request msg;
    msg.pid = 1729;
    msg.runtime_version = "1.0";
    msg.client_version = "2.0";
    msg.engine_settings.rules_file = fn;

    network::request req(std::move(msg));

    std::shared_ptr<network::base_response> res;
    EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
    EXPECT_CALL(*broker,
        send(testing::An<const std::shared_ptr<network::base_response> &>()))
        .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

    EXPECT_TRUE(c.run_client_init());

    auto msg_res = dynamic_cast<network::client_init::response *>(res.get());

    EXPECT_STREQ(msg_res->status.c_str(), "ok");
    EXPECT_EQ(msg_res->meta.size(), 2);
    EXPECT_STREQ(msg_res->meta[tag::waf_version].c_str(), "1.9.0");

    rapidjson::Document doc;
    doc.Parse(msg_res->meta[tag::event_rules_errors]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());
    EXPECT_TRUE(doc.HasMember("missing key 'type'"));
    EXPECT_TRUE(doc.HasMember("unknown processor: squash"));
    EXPECT_TRUE(doc.HasMember("missing key 'inputs'"));

    EXPECT_EQ(msg_res->metrics.size(), 2);
    // For small enough integers this comparison should work, otherwise replace
    // with EXPECT_NEAR.
    EXPECT_EQ(msg_res->metrics[tag::event_rules_loaded], 1.0);
    EXPECT_EQ(msg_res->metrics[tag::event_rules_failed], 4.0);
}

TEST(ClientTest, ClientInitResponseFail)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_ok();

    network::client_init::request msg;
    msg.pid = 1729;
    msg.runtime_version = "1.0";
    msg.client_version = "2.0";
    msg.engine_settings.rules_file = fn;

    network::request req(std::move(msg));
    EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
    EXPECT_CALL(*broker,
        send(testing::An<const std::shared_ptr<network::base_response> &>()))
        .WillOnce(Return(false));

    EXPECT_FALSE(c.run_client_init());
}

TEST(ClientTest, ClientInitMissingRuleFile)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    auto fn = "/missing/file";

    network::client_init::request msg;
    msg.pid = 1729;
    msg.runtime_version = "1.0";
    msg.client_version = "2.0";
    msg.engine_settings.rules_file = fn;

    network::request req(std::move(msg));

    std::shared_ptr<network::base_response> res;
    EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
    EXPECT_CALL(*broker,
        send(testing::An<const std::shared_ptr<network::base_response> &>()))
        .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

    EXPECT_FALSE(c.run_client_init());
    auto msg_res = dynamic_cast<network::client_init::response *>(res.get());
    EXPECT_STREQ(msg_res->status.c_str(), "fail");
}

TEST(ClientTest, ClientInitInvalidRuleFileFormat)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    char tmpl[] = "/tmp/test_ddappsec_XXXXXX";
    int fd = mkstemp(tmpl);
    std::FILE *tmpf = fdopen(fd, "wb+");
    std::string data = "this is an invalid rule file";
    std::fwrite(data.c_str(), data.size(), 1, tmpf);
    std::fclose(tmpf);

    network::client_init::request msg;
    msg.pid = 1729;
    msg.runtime_version = "1.0";
    msg.client_version = "2.0";
    msg.engine_settings.rules_file = tmpl;

    network::request req(std::move(msg));

    std::shared_ptr<network::base_response> res;
    EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
    EXPECT_CALL(*broker,
        send(testing::An<const std::shared_ptr<network::base_response> &>()))
        .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

    EXPECT_FALSE(c.run_client_init());
    auto msg_res = dynamic_cast<network::client_init::response *>(res.get());
    EXPECT_STREQ(msg_res->status.c_str(), "fail");
}

TEST(ClientTest, ClientInitAfterClientInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_ok();

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    {
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.engine_settings.rules_file = fn;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ(std::string("error"), res->get_type());
    }
}

TEST(ClientTest, ClientInitBrokerThrows)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_ok();

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    {
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.engine_settings.rules_file = fn;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Throw(std::exception()));

        EXPECT_FALSE(c.run_client_init());
    }

    {
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.engine_settings.rules_file = fn;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Throw(24));

        EXPECT_FALSE(c.run_client_init());
    }
}

TEST(ClientTest, RequestInitOnClientInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_ok();

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter::string("acunetix-product"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_FALSE(c.run_client_init());
        EXPECT_EQ(std::string("error"), res->get_type());
    }
}

TEST(ClientTest, RequestInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter::string("acunetix-product"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto msg_res =
            dynamic_cast<network::request_init::response *>(res.get());
        EXPECT_STREQ(msg_res->verdict.c_str(), "record");
        EXPECT_EQ(msg_res->triggers.size(), 1);
    }
}

TEST(ClientTest, RequestInitBlock)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("http.client_ip", parameter::string("192.168.1.1"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto msg_res =
            dynamic_cast<network::request_init::response *>(res.get());
        EXPECT_STREQ(msg_res->verdict.c_str(), "block");
        EXPECT_STREQ(msg_res->parameters["type"].c_str(), "auto");
        EXPECT_STREQ(msg_res->parameters["status_code"].c_str(), "403");
        EXPECT_EQ(msg_res->triggers.size(), 1);
    }
}

TEST(ClientTest, RequestInitUnpackError)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    // Request Init
    {
        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_))
            .WillOnce(Throw(msgpack::unpack_error("map size overflow")));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
    }
}

TEST(ClientTest, RequestInitNoClientInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter::string("acunetix-product"sv));

        network::request req(std::move(msg));

        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Return(true));

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestInitInvalidData)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::array();

        network::request req(std::move(msg));

        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Return(true));

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestInitBrokerThrows)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter::string("acunetix-product"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Throw(std::exception()));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .Times(0);

        EXPECT_FALSE(c.run_request());
    }

    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter::string("acunetix-product"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Throw(std::exception()));

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestShutdown)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);
    request_init(broker, c);

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();
        msg.data.add("server.response.code", parameter::string("1991"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto msg_res =
            dynamic_cast<network::request_shutdown::response *>(res.get());
        EXPECT_STREQ(msg_res->verdict.c_str(), "record");
        EXPECT_EQ(msg_res->triggers.size(), 1);

        EXPECT_EQ(msg_res->metrics.size(), 1);
        EXPECT_GT(msg_res->metrics[tag::waf_duration], 0.0);
        EXPECT_EQ(msg_res->meta.size(), 1);
        EXPECT_STREQ(msg_res->meta[tag::event_rules_version].c_str(), "1.2.3");
    }
}

TEST(ClientTest, RequestShutdownBlock)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    request_init(broker, c);

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();
        msg.data.add("http.client_ip", parameter::string("192.168.1.1"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto msg_res =
            dynamic_cast<network::request_shutdown::response *>(res.get());
        EXPECT_STREQ(msg_res->verdict.c_str(), "block");
        EXPECT_STREQ(msg_res->parameters["type"].c_str(), "auto");
        EXPECT_STREQ(msg_res->parameters["status_code"].c_str(), "403");
        EXPECT_EQ(msg_res->triggers.size(), 1);

        EXPECT_EQ(msg_res->metrics.size(), 1);
        EXPECT_GT(msg_res->metrics[tag::waf_duration], 0.0);
        EXPECT_EQ(msg_res->meta.size(), 1);
        EXPECT_STREQ(msg_res->meta[tag::event_rules_version].c_str(), "1.2.3");
    }
}

TEST(ClientTest, RequestShutdownInvalidData)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    request_init(broker, c);

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::array();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Return(true));

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestShutdownNoClientInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ(std::string("error"), res->get_type());
    }
}

TEST(ClientTest, RequestShutdownNoRequestInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ(std::string("error"), res->get_type());
    }
}

TEST(ClientTest, RequestShutdownBrokerThrows)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    request_init(broker, c);

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();
        msg.data.add("server.response.code", parameter::string("1991"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Throw(std::exception()));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .Times(0);

        EXPECT_FALSE(c.run_request());
    }

    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();
        msg.data.add("server.response.code", parameter::string("1991"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Throw(std::exception()));

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestShutdownDisabledClient)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_DISABLED);

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ(std::string("error"), res->get_type());
    }
}

TEST(ClientTest, ConfigSync)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto msg_res =
            dynamic_cast<network::config_sync::response *>(res.get());
        EXPECT_EQ(network::config_sync::response::id, msg_res->id);
    }
}

TEST(ClientTest, ConfigSyncNoClientInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Config Sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Return(true));

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest,
    ConfigSyncReturnsConfigFeaturesWhenExtensionNotConfiguredAndAsmEnabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    // Lets enable asm
    c.get_service()->get_service_config()->enable_asm();

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_features", res->get_type());

        auto msg_res =
            dynamic_cast<network::config_features::response *>(res.get());
        EXPECT_EQ(msg_res->enabled, true);
    }
}

TEST(ClientTest,
    ConfigSyncReturnsConfigSyncWhenExtensionNotConfiguredAndAsmDisabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    // Lets enable asm
    c.get_service()->get_service_config()->disable_asm();

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_sync", res->get_type());
    }
}

TEST(ClientTest,
    ConfigSyncReturnsConfigSyncWhenExtensionNotConfiguredAndAsmNotSet)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    // Lets enable asm
    c.get_service()->get_service_config()->unset_asm();

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_sync", res->get_type());
    }
}

TEST(ClientTest,
    ConfigSyncReturnsConfigFeaturesWhenExtensionEnabledAndAsmEnabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->enable_asm();

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_features", res->get_type());

        auto msg_res =
            dynamic_cast<network::config_features::response *>(res.get());
        EXPECT_EQ(msg_res->enabled, true);
    }
}

TEST(ClientTest,
    ConfigSyncReturnsConfigFeaturesWhenExtensionEnabledAndAsmDisabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->disable_asm();

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_features", res->get_type());

        auto msg_res =
            dynamic_cast<network::config_features::response *>(res.get());
        EXPECT_EQ(msg_res->enabled, true);
    }
}

TEST(
    ClientTest, ConfigSyncReturnsConfigFeaturesWhenExtensionEnabledAndAsmNotSet)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->unset_asm();

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_features", res->get_type());

        auto msg_res =
            dynamic_cast<network::config_features::response *>(res.get());
        EXPECT_EQ(msg_res->enabled, true);
    }
}

TEST(ClientTest, ConfigSyncReturnsConfigSyncWhenExtensionDisabledAndAsmEnabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_DISABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->enable_asm();

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_sync", res->get_type());
    }
}

TEST(ClientTest, ConfigSyncReturnsConfigSyncWhenExtensionDisabledAndAsmDisabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_DISABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->disable_asm();

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_sync", res->get_type());
    }
}

TEST(ClientTest, ConfigSyncReturnsConfigSyncWhenExtensionDisabledAndAsmNotSet)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_DISABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->unset_asm();

    // Config sync
    {
        network::config_sync::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_sync", res->get_type());
    }
}

TEST(ClientTest,
    RequestInitReturnsRequestInitWhenExtensionNotConfiguredAndAsmEnabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    // Lets enable asm
    c.get_service()->get_service_config()->enable_asm();

    // Request init
    {
        network::request_init::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("request_init", res->get_type());
    }
}

TEST(ClientTest,
    RequestInitReturnsConfigFeaturesWhenExtensionNotConfiguredAndAsmDisabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    // Lets enable asm
    c.get_service()->get_service_config()->disable_asm();

    // Request init
    {
        network::request_init::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_features", res->get_type());
    }
}

TEST(ClientTest,
    RequestInitReturnsConfigFeaturesWhenExtensionNotConfiguredAndAsmNotSet)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    // Lets enable asm
    c.get_service()->get_service_config()->unset_asm();

    // Request init
    {
        network::request_init::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_features", res->get_type());
    }
}

TEST(ClientTest, RequestInitReturnsRequestInitWhenExtensionEnabledAndAsmEnabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->enable_asm();

    // Request init
    {
        network::request_init::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("request_init", res->get_type());
    }
}

TEST(
    ClientTest, RequestInitReturnsRequestInitWhenExtensionEnabledAndAsmDisabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->disable_asm();

    // Request init
    {
        network::request_init::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("request_init", res->get_type());
    }
}

TEST(ClientTest, RequestInitReturnsRequestInitWhenExtensionEnabledAndAsmNotSet)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->unset_asm();

    // Request init
    {
        network::request_init::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("request_init", res->get_type());
    }
}

TEST(ClientTest,
    RequestInitReturnsConfigFeaturesWhenExtensionDisabledAndAsmEnabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_DISABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->enable_asm();

    // Request init
    {
        network::request_init::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_features", res->get_type());

        auto request_init_res =
            dynamic_cast<network::config_features::response *>(res.get());
        EXPECT_FALSE(request_init_res->enabled);
    }
}

TEST(ClientTest,
    RequestInitReturnsConfigFeaturesWhenExtensionDisabledAndAsmDisabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_DISABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->disable_asm();

    // Request init
    {
        network::request_init::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_features", res->get_type());

        auto request_init_res =
            dynamic_cast<network::config_features::response *>(res.get());
        EXPECT_FALSE(request_init_res->enabled);
    }
}

TEST(ClientTest,
    RequestInitReturnsConfigFeaturesWhenExtensionDisabledAndAsmNotSet)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_DISABLED);

    // Lets enable asm
    c.get_service()->get_service_config()->unset_asm();

    // Request init
    {
        network::request_init::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ("config_features", res->get_type());

        auto request_init_res =
            dynamic_cast<network::config_features::response *>(res.get());
        EXPECT_FALSE(request_init_res->enabled);
    }
}

TEST(ClientTest, RequestExecAfterRequestInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    request_init(broker, c);

    // Request Execution
    {
        network::request_exec::request msg;
        msg.data = parameter::map();
        msg.data.add("http.client_ip", parameter::string("192.168.1.1"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto msg_res =
            dynamic_cast<network::request_exec::response *>(res.get());
        EXPECT_STREQ(msg_res->verdict.c_str(), "block");
        EXPECT_STREQ(msg_res->parameters["type"].c_str(), "auto");
        EXPECT_STREQ(msg_res->parameters["status_code"].c_str(), "403");
        EXPECT_EQ(msg_res->triggers.size(), 1);
    }
}

TEST(ClientTest, RequestExecWithoutAttack)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    request_init(broker, c);

    // Request Execution
    {
        network::request_exec::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto msg_res =
            dynamic_cast<network::request_exec::response *>(res.get());
        EXPECT_STREQ(msg_res->verdict.c_str(), "ok");
        EXPECT_EQ(msg_res->parameters.size(), 0);
        EXPECT_EQ(msg_res->triggers.size(), 0);
    }
}

TEST(ClientTest, RequestExecWithAttack)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_ENABLED);

    request_init(broker, c);

    // Request Execution
    {
        network::request_exec::request msg;
        msg.data = parameter::map();
        msg.data.add("http.client_ip", parameter::string("192.168.1.1"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto msg_res =
            dynamic_cast<network::request_exec::response *>(res.get());
        EXPECT_STREQ(msg_res->verdict.c_str(), "block");
        EXPECT_STREQ(msg_res->parameters["type"].c_str(), "auto");
        EXPECT_STREQ(msg_res->parameters["status_code"].c_str(), "403");
        EXPECT_EQ(msg_res->triggers.size(), 1);
    }
}

TEST(ClientTest, RequestExecWithoutClientInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Request Execution
    {
        network::request_exec::request msg;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ(std::string("error"), res->get_type());
    }
}

TEST(ClientTest, RequestExecDisabledClient)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_DISABLED);

    // Request Execution
    {
        network::request_exec::request msg;
        msg.data = parameter::map();
        msg.data.add("http.client_ip", parameter::string("192.168.1.1"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ(std::string("error"), res->get_type());
    }
}

TEST(ClientTest, ServiceIsCreatedDependingOnEnabledConfigurationValue)
{
    std::shared_ptr<service> service;
    std::shared_ptr<network::base_response> res;

    { // Not configured
        auto smanager = std::make_shared<mock::service_manager>();
        auto broker = new mock::broker();
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillRepeatedly(Return(true));

        EXPECT_CALL(*smanager, create_service(_, _, _, _, _, true))
            .Times(1)
            .WillOnce(Return(service));
        client c(smanager, std::unique_ptr<mock::broker>(broker));
        network::client_init::request msg;
        msg.enabled_configuration = std::nullopt;
        c.handle_command(msg);
    }

    { // Enabled by conf
        auto smanager = std::make_shared<mock::service_manager>();
        auto broker = new mock::broker();
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillRepeatedly(Return(true));
        EXPECT_CALL(*smanager, create_service(_, _, _, _, _, false))
            .Times(1)
            .WillOnce(Return(service));
        client c(smanager, std::unique_ptr<mock::broker>(broker));
        network::client_init::request msg;
        msg.enabled_configuration = true;
        c.handle_command(msg);
    }

    { // Disabled by conf
        auto smanager = std::make_shared<mock::service_manager>();
        auto broker = new mock::broker();
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillRepeatedly(Return(true));
        EXPECT_CALL(*smanager, create_service(_, _, _, _, _, false))
            .Times(1)
            .WillOnce(Return(service));
        client c(smanager, std::unique_ptr<mock::broker>(broker));
        network::client_init::request msg;
        msg.enabled_configuration = false;
        c.handle_command(msg);
    }
}

TEST(ClientTest, RequestCallsWorkIfRequestInitWasEnabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    c.get_service()->get_service_config()->enable_asm();
    request_init(broker, c);
    c.get_service()->get_service_config()->disable_asm();

    // Request Execution
    {
        network::request_exec::request msg;
        msg.data = parameter::map();
        msg.data.add("http.client_ip", parameter::string("192.168.1.1"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto msg_res =
            dynamic_cast<network::request_exec::response *>(res.get());
        EXPECT_STREQ(msg_res->verdict.c_str(), "block");
        EXPECT_STREQ(msg_res->parameters["type"].c_str(), "auto");
        EXPECT_STREQ(msg_res->parameters["status_code"].c_str(), "403");
        EXPECT_EQ(msg_res->triggers.size(), 1);
    }

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();
        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Return(true));

        EXPECT_TRUE(c.run_request());
    }
}

TEST(ClientTest, RequestCallsWontWorkIfRequestInitWasDisabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    {
        c.get_service()->get_service_config()->disable_asm();
        {
            network::request_init::request msg;
            msg.data = parameter::map();
            msg.data.add("server.request.headers.no_cookies",
                parameter::string("Arachni"sv));

            network::request req(std::move(msg));

            std::shared_ptr<network::base_response> res;
            EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
            EXPECT_CALL(*broker,
                send(testing::An<
                    const std::shared_ptr<network::base_response> &>()))
                .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

            EXPECT_TRUE(c.run_request());
            EXPECT_EQ(std::string("config_features"), res->get_type());
        }
        c.get_service()->get_service_config()->enable_asm();

        // Request Execution
        {
            network::request_exec::request msg;
            msg.data = parameter::map();
            msg.data.add("http.client_ip", parameter::string("192.168.1.1"sv));

            network::request req(std::move(msg));

            std::shared_ptr<network::base_response> res;
            EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
            EXPECT_CALL(*broker,
                send(testing::An<
                    const std::shared_ptr<network::base_response> &>()))
                .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

            EXPECT_TRUE(c.run_request());
            EXPECT_EQ(std::string("error"), res->get_type());
        }
    }

    {
        c.get_service()->get_service_config()->disable_asm();
        {
            network::request_init::request msg;
            msg.data = parameter::map();
            msg.data.add("server.request.headers.no_cookies",
                parameter::string("Arachni"sv));

            network::request req(std::move(msg));

            std::shared_ptr<network::base_response> res;
            EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
            EXPECT_CALL(*broker,
                send(testing::An<
                    const std::shared_ptr<network::base_response> &>()))
                .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

            EXPECT_TRUE(c.run_request());
            EXPECT_EQ(std::string("config_features"), res->get_type());
        }
        c.get_service()->get_service_config()->enable_asm();

        // Request Shutdown
        {
            network::request_shutdown::request msg;
            msg.data = parameter::map();
            network::request req(std::move(msg));

            std::shared_ptr<network::base_response> res;
            EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
            EXPECT_CALL(*broker,
                send(testing::An<
                    const std::shared_ptr<network::base_response> &>()))
                .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

            EXPECT_TRUE(c.run_request());
            EXPECT_EQ(std::string("error"), res->get_type());
        }
    }
}

TEST(ClientTest, StatusGetsComputedOnError)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    set_extension_configuration_to(broker, c, EXTENSION_CONFIGURATION_NOT_SET);

    c.get_service()->get_service_config()->disable_asm();
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter::string("Arachni"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ(std::string("config_features"), res->get_type());
    }
    c.get_service()->get_service_config()->enable_asm();

    // Request Execution fails but computes new state
    {
        network::request_exec::request msg;
        msg.data = parameter::map();
        msg.data.add("http.client_ip", parameter::string("192.168.1.1"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ(std::string("error"), res->get_type());
    }

    // Request Execution
    {
        network::request_exec::request msg;
        msg.data = parameter::map();
        msg.data.add("http.client_ip", parameter::string("192.168.1.1"sv));

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_EQ(std::string("request_exec"), res->get_type());
    }
}

} // namespace dds
