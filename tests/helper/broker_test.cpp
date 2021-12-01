// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include <msgpack.hpp>
#include <network/broker.hpp>
#include <network/socket.hpp>
#include "common.hpp"

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

}

namespace {
    void pack_str(msgpack::packer<std::stringstream> &p, const char *str) {
        size_t l = strlen(str);
        p.pack_str(l);
        p.pack_str_body(str, l);
    }

    void pack_str(msgpack::packer<std::stringstream> &p, const std::string str) {
        p.pack_str(str.size());
        p.pack_str_body(str.c_str(), str.size());
    }
}

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

TEST(BrokerTest, BrokerSendClientInit) {
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(2);
    pack_str(packer, "ok");
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_,_))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    network::client_init::response response;
    response.status = "ok";
    response.errors = {"one", "two"};
    EXPECT_TRUE(broker.send(response));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_STREQ(expected_data.c_str(), buffer.c_str());
}

TEST(BrokerTest, BrokerSendRequestInit) {
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(2);
    pack_str(packer, "record");
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_,_))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    network::request_init::response response;
    response.verdict = "record";
    response.triggers = {"one", "two"};
    EXPECT_TRUE(broker.send(response));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_STREQ(expected_data.c_str(), buffer.c_str());
}

TEST(BrokerTest, BrokerSendRequestShutdown) {
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(2);
    pack_str(packer, "block");
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_,_))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    network::request_shutdown::response response;
    response.verdict = "block";
    response.triggers = {"one", "two"};
    EXPECT_TRUE(broker.send(response));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_STREQ(expected_data.c_str(), buffer.c_str());
}

TEST(BrokerTest, BrokerRecvClientInit) {
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(2);
    pack_str(packer, "client_init");
    packer.pack_array(4);
    packer.pack_unsigned_int(20);
    pack_str(packer, "one");
    pack_str(packer, "two");
    pack_str(packer, "three");
    const std::string &expected_data = ss.str();

    network::header_t h{"dds", (uint32_t)expected_data.size()};
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request = broker.recv(std::chrono::milliseconds(100));
    EXPECT_EQ(request.id, network::client_init::request::id);
    EXPECT_STREQ(request.method.c_str(), "client_init");
    
    auto command = std::move(request.as<network::client_init>());
    EXPECT_EQ(command.pid, 20);
    EXPECT_STREQ(command.client_version.c_str(), "one");
    EXPECT_STREQ(command.runtime_version.c_str(), "two");
    EXPECT_STREQ(command.rules_file.c_str(), "three");
}

TEST(BrokerTest, BrokerRecvRequestInit) {
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
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request = broker.recv(std::chrono::milliseconds(100));
    EXPECT_EQ(request.id, network::request_init::request::id);
    EXPECT_STREQ(request.method.c_str(), "request_init");

    auto &command = request.as<network::request_init>();
    EXPECT_TRUE(command.data.is_map());
    EXPECT_EQ(command.data.size(), 2);
    EXPECT_STREQ(command.data[0].key().data(), "server.request.query");
    EXPECT_STREQ(std::string_view(command.data[0]).data(), "Arachni");
    EXPECT_STREQ(command.data[1].key().data(), "server.request.uri");
    EXPECT_STREQ(std::string_view(command.data[1]).data(), "arachni.com");
    command.data.free();
}

TEST(BrokerTest, BrokerRecvRequestShutdown) {
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
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request = broker.recv(std::chrono::milliseconds(100));
    EXPECT_EQ(request.id, network::request_shutdown::request::id);
    EXPECT_STREQ(request.method.c_str(), "request_shutdown");

    auto command = std::move(request.as<network::request_shutdown>());
    EXPECT_TRUE(command.data.is_map());
    EXPECT_EQ(command.data.size(), 1);
    EXPECT_STREQ(command.data[0].key().data(), "server.response.code");
    EXPECT_STREQ(std::string_view(command.data[0]).data(), "1729");
    command.data.free();
}

TEST(BrokerTest, BrokerParsingStringLimit) {
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
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)), std::bad_cast);
}

TEST(BrokerTest, BrokerParsingMapLimit) {
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
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)), std::bad_cast);
}

TEST(BrokerTest, BrokerParsingArrayLimit) {
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
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)), std::bad_cast);
}

TEST(BrokerTest, BrokerParsingDepthLimit) {
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
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)), std::bad_cast);
}

TEST(BrokerTest, BrokerParsingBinLimit) {
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
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)), std::bad_cast);
}

TEST(BrokerTest, BrokerParsingExtLimit) {
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
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(CopyString(&expected_data), Return(expected_data.size())));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)), std::bad_cast);
}

TEST(BrokerTest, BrokerParsingBodyLimit) {
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
    EXPECT_CALL(*socket, recv(_,_))
        .WillOnce(DoAll(CopyHeader(&h), Return(sizeof(network::header_t))));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)), std::length_error);
}
}
