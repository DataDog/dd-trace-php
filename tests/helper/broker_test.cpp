// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include "version.hpp"
#include <exception.hpp>
#include <msgpack.hpp>
#include <network/broker.hpp>
#include <network/socket.hpp>
#include <parameter_view.hpp>

namespace dds {

namespace mock {

class socket : public network::base_socket {
public:
    ~socket() override = default;
    MOCK_METHOD2(recv, std::size_t(char *, std::size_t));
    MOCK_METHOD2(send, std::size_t(const char *, std::size_t));

    void set_send_timeout(std::chrono::milliseconds timeout) override {}
    void set_recv_timeout(std::chrono::milliseconds timeout) override {}
};

} // namespace mock

namespace {
void pack_str(msgpack::packer<std::stringstream> &p, const char *str)
{
    size_t l = strlen(str);
    p.pack_str(l);
    p.pack_str_body(str, l);
}
void pack_str(msgpack::packer<std::stringstream> &p, const std::string str)
{
    p.pack_str(str.size());
    p.pack_str_body(&str[0], str.size());
}
void pack_str(msgpack::packer<std::stringstream> &p, const std::string_view str)
{
    p.pack_str(str.size());
    p.pack_str_body(str.data(), str.size());
}
} // namespace

ACTION_P(SaveHeader, param)
{
    memcpy(reinterpret_cast<void *>(param), arg0, arg1);
}

ACTION_P(SaveString, param)
{
    std::string &str = *reinterpret_cast<std::string *>(param);
    str = std::string(arg0, arg1);
}

ACTION_P(CopyHeader, param)
{
    memcpy(arg0, reinterpret_cast<void *>(param), sizeof(network::header_t));
}

ACTION_P(CopyString, param)
{
    const std::string &str = *reinterpret_cast<const std::string *>(param);
    memcpy(arg0, str.c_str(), str.size());
}

TEST(BrokerTest, SendClientInit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(5);
    pack_str(packer, "ok");
    pack_str(packer, dds::php_ddappsec_version);
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    packer.pack_map(0);
    packer.pack_map(0);
    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    network::client_init::response response;
    response.status = "ok";
    response.errors = {"one", "two"};
    EXPECT_TRUE(broker.send(response));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_STREQ(expected_data.c_str(), buffer.c_str());
}

TEST(BrokerTest, SendRequestInit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(3);
    pack_str(packer, "record");
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    packer.pack_array(1);
    pack_str(packer, "block");
    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    network::request_init::response response;
    response.verdict = "record";
    response.triggers = {"one", "two"};
    response.actions = {"block"};
    EXPECT_TRUE(broker.send(response));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_STREQ(expected_data.c_str(), buffer.c_str());
}

TEST(BrokerTest, SendRequestShutdown)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(5);
    pack_str(packer, "record");
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    packer.pack_array(1);
    pack_str(packer, "block");
    packer.pack_map(0);
    packer.pack_map(0);
    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    network::request_shutdown::response response;
    response.verdict = "record";
    response.triggers = {"one", "two"};
    response.actions = {"block"};
    EXPECT_TRUE(broker.send(response));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_STREQ(expected_data.c_str(), buffer.c_str());
}

TEST(BrokerTest, RecvClientInit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(2);
    pack_str(packer, "client_init");
    packer.pack_array(5);
    packer.pack_unsigned_int(20);
    pack_str(packer, "one");
    pack_str(packer, "two");
    packer.pack_map(2);
    pack_str(packer, "service");
    pack_str(packer, "api");
    pack_str(packer, "env");
    pack_str(packer, "prod");
    packer.pack_map(5);
    pack_str(packer, "rules_file");
    pack_str(packer, "three");
    pack_str(packer, "waf_timeout_us");
    packer.pack_uint64(42ul);
    pack_str(packer, "trace_rate_limit");
    packer.pack_uint32(1729u);
    pack_str(packer, "obfuscator_key_regex");
    pack_str(packer, "key_regex");
    pack_str(packer, "obfuscator_value_regex");
    pack_str(packer, "value_regex");

    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request = broker.recv(std::chrono::milliseconds(100));
    EXPECT_EQ(request.id, network::client_init::request::id);
    EXPECT_STREQ(request.method.c_str(), "client_init");

    auto command = std::move(request.as<network::client_init>());
    EXPECT_EQ(command.pid, 20);
    EXPECT_STREQ(command.client_version.c_str(), "one");
    EXPECT_STREQ(command.runtime_version.c_str(), "two");
    EXPECT_STREQ(command.service.service.c_str(), "api");
    EXPECT_STREQ(command.service.env.c_str(), "prod");
    EXPECT_EQ(command.engine_settings.rules_file, std::string{"three"});
    EXPECT_EQ(command.engine_settings.waf_timeout_us, 42ul);
    EXPECT_EQ(command.engine_settings.trace_rate_limit, 1729u);
    EXPECT_STREQ(
        command.engine_settings.obfuscator_key_regex.c_str(), "key_regex");
    EXPECT_STREQ(
        command.engine_settings.obfuscator_value_regex.c_str(), "value_regex");
}

TEST(BrokerTest, RecvRequestInit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(2);
    pack_str(packer, "request_init");
    packer.pack_array(1);
    packer.pack_map(2);
    pack_str(packer, "server.request.query");
    pack_str(packer, "Arachni");
    pack_str(packer, "server.request.uri");
    pack_str(packer, "arachni.com");
    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request = broker.recv(std::chrono::milliseconds(100));
    EXPECT_EQ(request.id, network::request_init::request::id);
    EXPECT_STREQ(request.method.c_str(), "request_init");

    auto &command = request.as<network::request_init>();
    parameter_view pv(command.data);
    EXPECT_TRUE(pv.is_map());
    EXPECT_EQ(pv.size(), 2);
    EXPECT_STREQ(pv[0].key().data(), "server.request.query");
    EXPECT_STREQ(std::string_view(pv[0]).data(), "Arachni");
    EXPECT_STREQ(pv[1].key().data(), "server.request.uri");
    EXPECT_STREQ(std::string_view(pv[1]).data(), "arachni.com");
}

TEST(BrokerTest, RecvRequestInitOverLimits)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(2);
    pack_str(packer, "request_init");
    packer.pack_array(1);

    auto map_size = network::broker::max_map_size + 1;
    packer.pack_map(map_size);
    for (unsigned i = 0; i < map_size; i++) {
        pack_str(packer, std::to_string(i));
        pack_str(packer, std::to_string(i));
    }
    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    EXPECT_THROW(
        network::request request = broker.recv(std::chrono::milliseconds(100)),
        msgpack::unpack_error);
}

TEST(BrokerTest, RecvRequestShutdown)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(2);
    pack_str(packer, "request_shutdown");
    packer.pack_array(1);
    packer.pack_map(1);
    pack_str(packer, "server.response.code");
    pack_str(packer, "1729");
    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request = broker.recv(std::chrono::milliseconds(100));
    EXPECT_EQ(request.id, network::request_shutdown::request::id);
    EXPECT_STREQ(request.method.c_str(), "request_shutdown");

    auto command = std::move(request.as<network::request_shutdown>());
    parameter_view pv(command.data);
    EXPECT_TRUE(pv.is_map());
    EXPECT_EQ(pv.size(), 1);
    EXPECT_STREQ(pv[0].key().data(), "server.response.code");
    EXPECT_STREQ(std::string_view(pv[0]).data(), "1729");
}

TEST(BrokerTest, NoBytesForHeader)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    EXPECT_CALL(*socket, recv(_, _)).WillOnce(Return(2));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        std::length_error);
}

TEST(BrokerTest, NoBytesForBody)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    network::header_t h{"dds", (uint32_t)64};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(Return(4));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        std::length_error);
}

TEST(BrokerTest, InvalidMsgpack)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::string expected_data("notamsgpack");

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(
        request = broker.recv(std::chrono::milliseconds(100)), std::bad_cast);
}

TEST(BrokerTest, InvalidRequest)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_map(1);
    pack_str(packer, "request_init");
    pack_str(packer, "request_shutdown");
    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        msgpack::type_error);
}

TEST(BrokerTest, ParsingStringLimit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);
    pack_str(packer, "request_shutdown");
    packer.pack_array(1);
    packer.pack_map(1);
    pack_str(packer, "server.response.code");
    pack_str(packer, std::string(network::broker::max_string_length + 1, 'a'));

    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        msgpack::type_error);
}

TEST(BrokerTest, ParsingMapLimit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);
    pack_str(packer, "request_shutdown");
    packer.pack_array(1);
    packer.pack_map(network::broker::max_map_size + 1);
    for (std::size_t i = 0; i < network::broker::max_map_size + 1; i++) {
        pack_str(packer, "server.response.code");
        pack_str(packer, "1729");
    }

    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        msgpack::type_error);
}

TEST(BrokerTest, ParsingArrayLimit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);
    pack_str(packer, "request_shutdown");
    packer.pack_array(1);
    packer.pack_map(1);
    pack_str(packer, "server.response.code");
    packer.pack_array(network::broker::max_array_size + 1);
    for (std::size_t i = 0; i < network::broker::max_array_size + 1; i++) {
        pack_str(packer, "1729");
    }

    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        msgpack::type_error);
}

TEST(BrokerTest, ParsingDepthLimit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);
    pack_str(packer, "request_shutdown");
    packer.pack_array(1);
    packer.pack_map(1);
    pack_str(packer, "server.response.code");
    for (std::size_t i = 0; i < network::broker::max_depth; i++) {
        packer.pack_array(1);
    }
    pack_str(packer, "1729");

    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        msgpack::type_error);
}

TEST(BrokerTest, ParsingBinLimit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);
    pack_str(packer, "request_shutdown");
    packer.pack_array(1);
    packer.pack_map(1);
    pack_str(packer, "server.response.code");
    packer.pack_bin(4);
    packer.pack_bin_body("1729", 4);

    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        msgpack::type_error);
}

TEST(BrokerTest, ParsingExtLimit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);
    pack_str(packer, "request_shutdown");
    packer.pack_array(1);
    packer.pack_map(1);
    pack_str(packer, "server.response.code");
    packer.pack_ext(4, 4);
    packer.pack_ext_body("1729", 4);

    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(
            DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        msgpack::type_error);
}

TEST(BrokerTest, ParsingBodyLimit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);
    pack_str(packer, "request_shutdown");
    packer.pack_array(1);
    packer.pack_map(16);
    for (char c = 'a'; c < 'q'; c++) {
        pack_str(packer, std::string(4, c));
        pack_str(packer, std::string(4096, c));
    }

    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_, _))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        std::length_error);
}

TEST(BrokerTest, SendErrorResponse)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    network::header_t h;
    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(Return(1));

    network::error::response response;
    EXPECT_TRUE(broker.send(response));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_EQ(h.size, 1);
}

TEST(BrokerTest, InvalidResponseSize)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    EXPECT_CALL(*socket, send(_, _)).WillOnce(Return(0));

    network::client_init::response response;
    EXPECT_FALSE(broker.send(response));
}

} // namespace dds
