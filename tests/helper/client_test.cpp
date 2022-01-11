// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <client.hpp>
#include <engine_pool.hpp>
#include <network/broker.hpp>

namespace dds {

namespace mock {
class broker : public dds::network::base_broker {
public:
    MOCK_CONST_METHOD1(
        recv, network::request(std::chrono::milliseconds initial_timeout));
    MOCK_CONST_METHOD1(send, bool(const network::base_response &msg));
};

} // namespace mock

ACTION_TEMPLATE(SaveResponse, HAS_1_TEMPLATE_PARAMS(typename, T),
    AND_1_VALUE_PARAMS(output))
{
    *output = *static_cast<const T *>(&arg0);
}

TEST(ClientTest, ClientInit)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_ok();

    network::client_init::request msg;
    msg.pid = 1729;
    msg.runtime_version = "1.0";
    msg.client_version = "2.0";
    msg.settings.rules_file = fn;

    network::request req(std::move(msg));

    network::client_init::response res;
    EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
    EXPECT_CALL(*broker, send(_))
        .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

    EXPECT_TRUE(c.run_client_init());
    EXPECT_STREQ(res.status.c_str(), "ok");
}

TEST(ClientTest, ClientInitFail)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    auto fn = "/missing/file";

    network::client_init::request msg;
    msg.pid = 1729;
    msg.runtime_version = "1.0";
    msg.client_version = "2.0";
    msg.settings.rules_file = fn;

    network::request req(std::move(msg));

    network::client_init::response res;
    EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
    EXPECT_CALL(*broker, send(_))
        .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

    EXPECT_FALSE(c.run_client_init());
}

TEST(ClientTest, ClientInitBrokerThrows)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    auto fn = create_sample_rules_ok();

    {
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.settings.rules_file = fn;

        network::request req(std::move(msg));

        network::client_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Throw(std::exception()));
        EXPECT_CALL(*broker, send(_)).Times(0);

        EXPECT_FALSE(c.run_client_init());
    }

    {
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.settings.rules_file = fn;

        network::request req(std::move(msg));

        network::client_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_)).WillOnce(Throw(std::exception()));

        EXPECT_FALSE(c.run_client_init());
    }
}

TEST(ClientTest, RequestInit)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
        auto fn = create_sample_rules_ok();
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.settings.rules_file = fn;

        network::request req(std::move(msg));

        network::client_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        EXPECT_STREQ(res.status.c_str(), "ok");
    }

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter("acunetix-product"sv));

        network::request req(std::move(msg));

        network::request_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_STREQ(res.verdict.c_str(), "record");
        EXPECT_EQ(res.triggers.size(), 1);
    }
}

TEST(ClientTest, RequestInitNoClientInit)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter("acunetix-product"sv));

        network::request req(std::move(msg));

        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_)).Times(0);

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestInitInvalidData)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
        auto fn = create_sample_rules_ok();
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.settings.rules_file = fn;

        network::request req(std::move(msg));

        network::client_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        EXPECT_STREQ(res.status.c_str(), "ok");
    }

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::array();

        network::request req(std::move(msg));

        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_)).Times(0);

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestInitBrokerThrows)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
        auto fn = create_sample_rules_ok();
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.settings.rules_file = fn;

        network::request req(std::move(msg));

        network::client_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        EXPECT_STREQ(res.status.c_str(), "ok");
    }

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter("acunetix-product"sv));

        network::request req(std::move(msg));

        network::request_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Throw(std::exception()));
        EXPECT_CALL(*broker, send(_)).Times(0);

        EXPECT_FALSE(c.run_request());
    }

    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add("server.request.headers.no_cookies",
            parameter("acunetix-product"sv));

        network::request req(std::move(msg));

        network::request_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_)).WillOnce(Throw(std::exception()));

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestShutdown)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
        auto fn = create_sample_rules_ok();
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.settings.rules_file = fn;

        network::request req(std::move(msg));

        network::client_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        EXPECT_STREQ(res.status.c_str(), "ok");
    }

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add(
            "server.request.headers.no_cookies", parameter("Arachni"sv));

        network::request req(std::move(msg));

        network::request_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_STREQ(res.verdict.c_str(), "ok");
        EXPECT_EQ(res.triggers.size(), 0);
    }

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();
        msg.data.add("server.response.code", parameter("1991"sv));

        network::request req(std::move(msg));

        network::request_shutdown::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_STREQ(res.verdict.c_str(), "record");
        EXPECT_EQ(res.triggers.size(), 1);
    }
}

TEST(ClientTest, RequestShutdownNoClientInit)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_)).Times(0);

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestShutdownNoRequestInit)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
        auto fn = create_sample_rules_ok();
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.settings.rules_file = fn;

        network::request req(std::move(msg));

        network::client_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        EXPECT_STREQ(res.status.c_str(), "ok");
    }

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();

        network::request req(std::move(msg));

        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_)).Times(0);

        EXPECT_FALSE(c.run_request());
    }
}

TEST(ClientTest, RequestShutdownBrokerThrows)
{
    auto epool = std::make_shared<engine_pool>();
    auto broker = new mock::broker();

    client c(epool, std::unique_ptr<mock::broker>(broker));

    // Client Init
    {
        auto fn = create_sample_rules_ok();
        network::client_init::request msg;
        msg.pid = 1729;
        msg.runtime_version = "1.0";
        msg.client_version = "2.0";
        msg.settings.rules_file = fn;

        network::request req(std::move(msg));

        network::client_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_client_init());
        EXPECT_STREQ(res.status.c_str(), "ok");
    }

    // Request Init
    {
        network::request_init::request msg;
        msg.data = parameter::map();
        msg.data.add(
            "server.request.headers.no_cookies", parameter("Arachni"sv));

        network::request req(std::move(msg));

        network::request_init::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_))
            .WillOnce(DoAll(SaveResponse<decltype(res)>(&res), Return(true)));

        EXPECT_TRUE(c.run_request());
        EXPECT_STREQ(res.verdict.c_str(), "ok");
        EXPECT_EQ(res.triggers.size(), 0);
    }

    // Request Shutdown
    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();
        msg.data.add("server.response.code", parameter("1991"sv));

        network::request req(std::move(msg));

        network::request_shutdown::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Throw(std::exception()));
        EXPECT_CALL(*broker, send(_)).Times(0);

        EXPECT_FALSE(c.run_request());
    }

    {
        network::request_shutdown::request msg;
        msg.data = parameter::map();
        msg.data.add("server.response.code", parameter("1991"sv));

        network::request req(std::move(msg));

        network::request_shutdown::response res;
        EXPECT_CALL(*broker, recv(_)).WillOnce(Return(req));
        EXPECT_CALL(*broker, send(_)).WillOnce(Throw(std::exception()));

        EXPECT_FALSE(c.run_request());
    }
}

} // namespace dds
