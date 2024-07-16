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
#include <stdexcept>

namespace dds {

namespace mock {

class socket : public network::base_socket {
public:
    ~socket() override = default;
    MOCK_METHOD2(recv, std::size_t(char *, std::size_t));
    MOCK_METHOD2(send, std::size_t(const char *, std::size_t));
    MOCK_METHOD1(discard, std::size_t(std::size_t));

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
    packer.pack_array(1);            // Array of messages
    packer.pack_array(2);            // First message
    pack_str(packer, "client_init"); // Type
    packer.pack_array(6);
    pack_str(packer, "ok");
    pack_str(packer, dds::php_ddappsec_version);
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    packer.pack_map(0);
    packer.pack_map(0);
    packer.pack_map(0);
    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    auto response = std::make_shared<network::client_init::response>();
    response->status = "ok";
    response->errors = {"one", "two"};

    std::vector<std::shared_ptr<network::base_response>> messages;
    messages.push_back(response);

    EXPECT_TRUE(broker.send(messages));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_STREQ(expected_data.c_str(), buffer.c_str());
}

TEST(BrokerTest, SendRequestInit)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);             // Array of messages
    packer.pack_array(2);             // First message
    pack_str(packer, "request_init"); // Type
    packer.pack_array(3);
    packer.pack_array(1); // Array of actions
    packer.pack_array(2); // First action
    pack_str(packer, "block");
    packer.pack_map(2);
    pack_str(packer, "type");
    pack_str(packer, "auto");
    pack_str(packer, "status_code");
    pack_str(packer, "403");
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    packer.pack_true(); // Force_keep

    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    auto response = std::make_shared<network::request_init::response>();
    response->actions.push_back(
        {"block", {{"status_code", "403"}, {"type", "auto"}}});
    response->triggers = {"one", "two"};
    response->force_keep = true;

    std::vector<std::shared_ptr<network::base_response>> messages;
    messages.push_back(response);

    EXPECT_TRUE(broker.send(messages));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_STREQ(expected_data.c_str(), buffer.c_str());
}

TEST(BrokerTest, SendRequestShutdown)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);                 // Array of messages
    packer.pack_array(2);                 // First message
    pack_str(packer, "request_shutdown"); // Type
    packer.pack_array(6);
    packer.pack_array(1);
    packer.pack_array(2);
    pack_str(packer, "block");
    packer.pack_map(2);
    pack_str(packer, "type");
    pack_str(packer, "auto");
    pack_str(packer, "status_code");
    pack_str(packer, "403");
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    packer.pack_true(); // Force keep
    packer.pack_map(0);
    packer.pack_map(0);
    packer.pack_map(0);
    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    auto response = std::make_shared<network::request_shutdown::response>();
    response->actions.push_back(
        {"block", {{"status_code", "403"}, {"type", "auto"}}});
    response->triggers = {"one", "two"};
    response->force_keep = true;

    std::vector<std::shared_ptr<network::base_response>> messages;
    messages.push_back(response);

    EXPECT_TRUE(broker.send(messages));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_STREQ(expected_data.c_str(), buffer.c_str());
}

TEST(BrokerTest, SendRequestExec)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);             // Array of messages
    packer.pack_array(2);             // First message
    pack_str(packer, "request_exec"); // Type
    packer.pack_array(3);
    packer.pack_array(1);
    packer.pack_array(2);
    pack_str(packer, "block");
    packer.pack_map(2);
    pack_str(packer, "type");
    pack_str(packer, "auto");
    pack_str(packer, "status_code");
    pack_str(packer, "403");
    packer.pack_array(2);
    pack_str(packer, "one");
    pack_str(packer, "two");
    packer.pack_true(); // Force keep
    const auto &expected_data = ss.str();

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(DoAll(SaveString(&buffer), Return(expected_data.size())));

    auto response = std::make_shared<network::request_exec::response>();
    response->actions.push_back(
        {"block", {{"status_code", "403"}, {"type", "auto"}}});
    response->triggers = {"one", "two"};
    response->force_keep = true;

    std::vector<std::shared_ptr<network::base_response>> messages;
    messages.push_back(response);

    EXPECT_TRUE(broker.send(messages));

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
    // Message name
    pack_str(packer, "client_init");

    // Message contents
    packer.pack_array(7);
    packer.pack_unsigned_int(20); // 1. PID
    pack_str(packer, "one");      // 2. client_version
    pack_str(packer, "two");      // 3. runtime_version
    packer.pack_nil();            // 4. enabled_configuration

    packer.pack_map(6); // 5. service_identifier
    pack_str(packer, "service");
    pack_str(packer, "api");

    pack_str(packer, "extra_services");
    packer.pack_array(0);

    pack_str(packer, "env");
    pack_str(packer, "prod");

    pack_str(packer, "tracer_version");
    pack_str(packer, "9.99.9");

    pack_str(packer, "app_version");
    pack_str(packer, "1.23.4");

    pack_str(packer, "runtime_id");
    pack_str(packer,
        "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855");

    packer.pack_map(6); // 6. engine_settings
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

    pack_str(packer, "schema_extraction");
    packer.pack_map(2);
    pack_str(packer, "enabled");
    packer.pack_true();
    pack_str(packer, "sample_rate");
    packer.pack_double(0.5);

    packer.pack_map(4); // 7. rc_settings
    pack_str(packer, "enabled");
    packer.pack_true();
    pack_str(packer, "host");
    pack_str(packer, "datadog.host");
    pack_str(packer, "port");
    packer.pack_uint32(1025);
    pack_str(packer, "poll_interval");
    packer.pack_uint32(2222);

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
    EXPECT_FALSE(command.enabled_configuration.has_value());

    // Service Identifier
    EXPECT_STREQ(command.service.service.c_str(), "api");
    EXPECT_EQ(command.service.extra_services.size(), 0);
    EXPECT_STREQ(command.service.env.c_str(), "prod");
    EXPECT_STREQ(command.service.tracer_version.c_str(), "9.99.9");
    EXPECT_STREQ(command.service.app_version.c_str(), "1.23.4");
    EXPECT_STREQ(command.service.runtime_id.c_str(),
        "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855");

    // Engine settings
    EXPECT_EQ(command.engine_settings.rules_file, std::string{"three"});
    EXPECT_EQ(command.engine_settings.waf_timeout_us, 42ul);
    EXPECT_EQ(command.engine_settings.trace_rate_limit, 1729u);
    EXPECT_STREQ(
        command.engine_settings.obfuscator_key_regex.c_str(), "key_regex");
    EXPECT_STREQ(
        command.engine_settings.obfuscator_value_regex.c_str(), "value_regex");
    EXPECT_EQ(command.engine_settings.schema_extraction.enabled, true);
    EXPECT_EQ(command.engine_settings.schema_extraction.sample_rate, 0.5);

    // RC settings
    EXPECT_EQ(command.rc_settings.enabled, true);
    EXPECT_STREQ(command.rc_settings.host.c_str(), "datadog.host");
    EXPECT_EQ(command.rc_settings.port, 1025);
    EXPECT_EQ(command.rc_settings.poll_interval, 2222);
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
    packer.pack_map(3);
    pack_str(packer, "server.request.query");
    pack_str(packer, "Arachni");
    pack_str(packer, "server.request.uri");
    pack_str(packer, "arachni.com");
    pack_str(packer, "server.request.headers.no_cookies");
    packer.pack_map(6);
    pack_str(packer, "float_key");
    packer.pack_double(123.456);
    pack_str(packer, "true_key");
    packer.pack_true();
    pack_str(packer, "false_key");
    packer.pack_false();
    pack_str(packer, "negative_integer_key");
    packer.pack_int(-123);
    pack_str(packer, "positive_integer_key");
    packer.pack_int(456);
    pack_str(packer, "nil_key");
    packer.pack_nil();
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
    EXPECT_EQ(pv.size(), 3);
    EXPECT_STREQ(pv[0].key().data(), "server.request.query");
    EXPECT_STREQ(std::string_view(pv[0]).data(), "Arachni");
    EXPECT_STREQ(pv[1].key().data(), "server.request.uri");
    EXPECT_STREQ(std::string_view(pv[1]).data(), "arachni.com");
    EXPECT_STREQ(pv[2].key().data(), "server.request.headers.no_cookies");
    EXPECT_FLOAT_EQ(ddwaf_object_get_float(pv[2][0]), 123.456);
    EXPECT_TRUE(ddwaf_object_get_bool(pv[2][1]));
    EXPECT_FALSE(ddwaf_object_get_bool(pv[2][2]));
    EXPECT_FLOAT_EQ(ddwaf_object_get_signed(pv[2][3]), -123);
    EXPECT_FLOAT_EQ(ddwaf_object_get_unsigned(pv[2][4]), 456);
    EXPECT_EQ(ddwaf_object_type(pv[2][5]), DDWAF_OBJ_NULL);
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
    EXPECT_CALL(*socket, discard(h.size)).WillOnce(Return(h.size));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        std::out_of_range);
}

TEST(BrokerTest, ParsingBodyLimitFailFlush)
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
    EXPECT_CALL(*socket, discard(_)).WillOnce(Return(h.size - 1));

    network::request request;
    EXPECT_THROW(request = broker.recv(std::chrono::milliseconds(100)),
        std::length_error);
}

TEST(BrokerTest, SendErrorResponse)
{
    size_t error_message_size = 9; // Yes this is a bit harcoded
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    std::stringstream ss;
    msgpack::packer<std::stringstream> packer(ss);
    packer.pack_array(1);      // fArray of messages
    packer.pack_array(2);      // First message
    pack_str(packer, "error"); // Type
    packer.pack_array(0);
    const auto &expected_data = ss.str();

    network::header_t h;
    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(DoAll(SaveHeader(&h), Return(sizeof(network::header_t))))
        .WillOnce(Return(expected_data.size()));

    std::vector<std::shared_ptr<network::base_response>> messages;
    messages.push_back(std::make_shared<network::error::response>());
    EXPECT_TRUE(broker.send(messages));

    EXPECT_STREQ(h.code, "dds");
    EXPECT_EQ(h.size, expected_data.size());
}

TEST(BrokerTest, InvalidResponseSize)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    EXPECT_CALL(*socket, send(_, _)).WillOnce(Return(0));

    std::vector<std::shared_ptr<network::base_response>> messages;
    messages.push_back(std::make_shared<network::client_init::response>());
    EXPECT_FALSE(broker.send(messages));
}

void assert_type_equal_to(
    std::string buffer, int message_index, std::string expected_type)
{
    msgpack::object_handle oh = msgpack::unpack(buffer.data(), buffer.size());

    msgpack::object deserialized = oh.get();

    std::vector<
        msgpack::type::tuple<std::string, std::shared_ptr<msgpack::object>>>
        sent;
    deserialized.convert(sent);

    auto type_sent = sent[message_index].get<0>().c_str();
    EXPECT_STREQ(type_sent, expected_type.c_str());
}

TEST(BrokerTest, ClientInitTypeIsAddedToMessage)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(Return(sizeof(network::header_t)))
        .WillOnce(DoAll(SaveString(&buffer), Return(123)));

    auto response = std::make_shared<network::client_init::response>();
    std::vector<std::shared_ptr<network::base_response>> responses;
    responses.push_back(response);

    EXPECT_FALSE(broker.send(responses));

    assert_type_equal_to(buffer, 0, "client_init");
}

TEST(BrokerTest, RequestInitTypeIsAddedToMessage)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(Return(sizeof(network::header_t)))
        .WillOnce(DoAll(SaveString(&buffer), Return(123)));

    auto response = std::make_shared<network::request_init::response>();
    std::vector<std::shared_ptr<network::base_response>> responses;
    responses.push_back(response);

    EXPECT_FALSE(broker.send(responses));

    assert_type_equal_to(buffer, 0, "request_init");
}

TEST(BrokerTest, ErrorTypeIsAddedToMessage)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(Return(sizeof(network::header_t)))
        .WillOnce(DoAll(SaveString(&buffer), Return(123)));

    auto response = std::make_shared<network::error::response>();
    std::vector<std::shared_ptr<network::base_response>> responses;
    responses.push_back(response);

    EXPECT_FALSE(broker.send(responses));

    assert_type_equal_to(buffer, 0, "error");
}

TEST(BrokerTest, ConfigFeaturesTypeIsAddedToMessage)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(Return(sizeof(network::header_t)))
        .WillOnce(DoAll(SaveString(&buffer), Return(123)));

    auto response = std::make_shared<network::config_features::response>();
    std::vector<std::shared_ptr<network::base_response>> responses;
    responses.push_back(response);

    EXPECT_FALSE(broker.send(responses));

    assert_type_equal_to(buffer, 0, "config_features");
}

TEST(BrokerTest, ConfigSyncTypeIsAddedToMessage)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(Return(sizeof(network::header_t)))
        .WillOnce(DoAll(SaveString(&buffer), Return(123)));

    auto response = std::make_shared<network::config_sync::response>();
    std::vector<std::shared_ptr<network::base_response>> responses;
    responses.push_back(response);

    EXPECT_FALSE(broker.send(responses));

    assert_type_equal_to(buffer, 0, "config_sync");
}

TEST(BrokerTest, RequestExecutionTypeIsAddedToMessage)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(Return(sizeof(network::header_t)))
        .WillOnce(DoAll(SaveString(&buffer), Return(123)));

    auto response = std::make_shared<network::request_exec::response>();
    std::vector<std::shared_ptr<network::base_response>> responses;
    responses.push_back(response);

    EXPECT_FALSE(broker.send(responses));

    assert_type_equal_to(buffer, 0, "request_exec");
}

TEST(BrokerTest, RequestShutdownTypeIsAddedToMessage)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    network::header_t h;
    std::string buffer;

    EXPECT_CALL(*socket, send(_, _))
        .WillOnce(Return(sizeof(network::header_t)))
        .WillOnce(DoAll(SaveString(&buffer), Return(123)));

    auto response = std::make_shared<network::request_shutdown::response>();
    std::vector<std::shared_ptr<network::base_response>> responses;
    responses.push_back(response);

    EXPECT_FALSE(broker.send(responses));

    assert_type_equal_to(buffer, 0, "request_shutdown");
}

TEST(BrokerTest, ItReturnsFalseWhenNoMessagesProvided)
{
    mock::socket *socket = new mock::socket();
    network::broker broker{std::unique_ptr<mock::socket>(socket)};

    EXPECT_CALL(*socket, send(_, _)).Times(0);

    std::vector<std::shared_ptr<network::base_response>> responses;

    EXPECT_FALSE(broker.send(responses));
}

} // namespace dds
