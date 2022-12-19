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

} // namespace mock

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

    auto client_init_res =
        dynamic_cast<network::client_init::response *>(res.get());

    EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    EXPECT_EQ(client_init_res->meta.size(), 2);
    EXPECT_STREQ(client_init_res->meta[tag::waf_version].c_str(), "1.6.0");
    EXPECT_STREQ(client_init_res->meta[tag::event_rules_errors].c_str(), "{}");

    EXPECT_EQ(client_init_res->metrics.size(), 2);
    // For small enough integers this comparison should work, otherwise replace
    // with EXPECT_NEAR.
    EXPECT_EQ(client_init_res->metrics[tag::event_rules_loaded], 2.0);
    EXPECT_EQ(client_init_res->metrics[tag::event_rules_failed], 0.0);
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

    auto client_init_res =
        dynamic_cast<network::client_init::response *>(res.get());

    EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    EXPECT_EQ(client_init_res->meta.size(), 2);
    EXPECT_STREQ(client_init_res->meta[tag::waf_version].c_str(), "1.6.0");

    rapidjson::Document doc;
    doc.Parse(client_init_res->meta[tag::event_rules_errors]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());
    EXPECT_TRUE(doc.HasMember("missing key 'type'"));
    EXPECT_TRUE(doc.HasMember("unknown processor: squash"));
    EXPECT_TRUE(doc.HasMember("missing key 'inputs'"));

    EXPECT_EQ(client_init_res->metrics.size(), 2);
    // For small enough integers this comparison should work, otherwise replace
    // with EXPECT_NEAR.
    EXPECT_EQ(client_init_res->metrics[tag::event_rules_loaded], 1.0);
    EXPECT_EQ(client_init_res->metrics[tag::event_rules_failed], 4.0);
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
    auto client_init_res =
        dynamic_cast<network::client_init::response *>(res.get());
    EXPECT_STREQ(client_init_res->status.c_str(), "fail");
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
    auto client_init_res =
        dynamic_cast<network::client_init::response *>(res.get());
    EXPECT_STREQ(client_init_res->status.c_str(), "fail");
}

TEST(ClientTest, ClientInitAfterClientInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_ok();

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

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
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
        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, ClientInitBrokerThrows)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_ok();

    {
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.engine_settings.rules_file = fn;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Throw(std::exception()));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .Times(0);

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

        EXPECT_FALSE(c.run_client_init());
    }
}

TEST(ClientTest, RequestInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    }

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
        auto client_init_res =
            dynamic_cast<network::request_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->verdict.c_str(), "record");
        EXPECT_EQ(client_init_res->triggers.size(), 1);
    }
}

TEST(ClientTest, RequestInitUnpackError)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    }

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

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    }

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

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    }

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

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    }

    // Request Init
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
        auto client_init_res =
            dynamic_cast<network::request_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->verdict.c_str(), "ok");
        EXPECT_EQ(client_init_res->triggers.size(), 0);
    }

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
        auto client_init_res =
            dynamic_cast<network::request_shutdown::response *>(res.get());
        EXPECT_STREQ(client_init_res->verdict.c_str(), "record");
        EXPECT_EQ(client_init_res->triggers.size(), 1);

        EXPECT_EQ(client_init_res->metrics.size(), 1);
        EXPECT_GT(client_init_res->metrics[tag::waf_duration], 0.0);
        EXPECT_EQ(client_init_res->meta.size(), 1);
        EXPECT_STREQ(
            client_init_res->meta[tag::event_rules_version].c_str(), "1.2.3");
    }
}

TEST(ClientTest, RequestShutdownInvalidData)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    }

    // Request Init
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
        auto client_init_res =
            dynamic_cast<network::request_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->verdict.c_str(), "ok");
        EXPECT_EQ(client_init_res->triggers.size(), 0);
    }

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

        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Return(true));

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestShutdownNoRequestInit)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
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
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto client_init_res =
            dynamic_cast<network::request_shutdown::response *>(res.get());
        EXPECT_STREQ(client_init_res->verdict.c_str(), "ok");
        EXPECT_EQ(client_init_res->triggers.size(), 0);
    }
}

TEST(ClientTest, RequestShutdownBrokerThrows)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    }

    // Request Init
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
        auto client_init_res =
            dynamic_cast<network::request_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->verdict.c_str(), "ok");
        EXPECT_EQ(client_init_res->triggers.size(), 0);
    }

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

TEST(ClientTest, ConfigSync)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    }

    // Config sync
    {
        network::config_sync::request msg;
        msg.appsec_enabled_env = 0;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto client_init_res =
            dynamic_cast<network::config_sync::response *>(res.get());
        EXPECT_EQ(network::config_sync::response::id, client_init_res->id);
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
        msg.appsec_enabled_env = 0;

        network::request req(std::move(msg));

        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(Return(true));

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, ConfigSyncReturnsConfigFeaturesWhenAsmEnabled)
{
    auto smanager = std::make_shared<service_manager>();
    auto broker = new mock::broker();

    client c(smanager, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
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
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        auto client_init_res =
            dynamic_cast<network::client_init::response *>(res.get());
        EXPECT_STREQ(client_init_res->status.c_str(), "ok");
    }

    // Lets enable asm
    c.get_service()->get_service_config()->enable_asm();

    // Config sync
    {
        network::config_sync::request msg;
        msg.appsec_enabled_env = 0;

        network::request req(std::move(msg));

        std::shared_ptr<network::base_response> res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker,
            send(
                testing::An<const std::shared_ptr<network::base_response> &>()))
            .WillOnce(DoAll(testing::SaveArg<0>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        auto client_init_res =
            dynamic_cast<network::config_features::response *>(res.get());
        EXPECT_EQ(client_init_res->enabled, true);
    }
}

} // namespace dds
