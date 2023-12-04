// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "http_api.hpp"
#include <boost/asio/connect.hpp>
#include <boost/asio/ip/tcp.hpp>
#include <boost/beast/core.hpp>
#include <boost/beast/http.hpp>
#include <boost/beast/version.hpp>
#include <optional>
#include <spdlog/spdlog.h>
#include <string>

namespace beast = boost::beast; // from <boost/beast.hpp>
namespace http = beast::http;   // from <boost/beast/http.hpp>
namespace net = boost::asio;    // from <boost/asio.hpp>
using tcp = net::ip::tcp;       // from <boost/asio/ip/tcp.hpp>

static const int version = 11;

std::string execute_request(const std::string &host, const std::string &port,
    const http::request<http::string_body> &request)
{
    std::string result;

    try {
        // The io_context is required for all I/O
        net::io_context ioc;

        // These objects perform our I/O
        tcp::resolver resolver(ioc);
        beast::tcp_stream stream(ioc);

        // Look up the domain name
        auto const results = resolver.resolve(host, port);

        // Make the connection on the IP address we get from a lookup
        stream.connect(results);

        // Send the HTTP request to the remote host
        http::write(stream, request);

        // This buffer is used for reading and must be persisted
        beast::flat_buffer buffer;

        // Declare a container to hold the response
        http::response<http::dynamic_body> res;

        // Receive the HTTP response
        http::read(stream, buffer, res);

        // Write the message to standard out
        result = boost::beast::buffers_to_string(res.body().data());

        // Gracefully close the socket
        beast::error_code ec;
        // NOLINTNEXTLINE(bugprone-unused-return-value,cert-err33-c)
        stream.socket().shutdown(tcp::socket::shutdown_both, ec);

        // not_connected happens sometimes
        // so don't bother reporting it.
        //
        if (ec && ec != beast::errc::not_connected) {
            throw beast::system_error{ec};
        }

        // If we get here then the connection is closed gracefully
    } catch (std::exception const &e) {
        auto sv = request.target();
        const std::string err{sv.data(), sv.size()};
        SPDLOG_ERROR("Connection error - {} - {}", err, e.what());
        throw dds::remote_config::network_exception(
            "Connection error - " + err + " - " + e.what());
    }

    return result;
}

std::string dds::remote_config::http_api::get_info() const
{
    http::request<http::string_body> req{http::verb::get, "/info", version};
    req.set(http::field::host, host_);
    req.set(http::field::user_agent, BOOST_BEAST_VERSION_STRING);

    return execute_request(host_, port_, req);
}

std::string dds::remote_config::http_api::get_configs(
    std::string &&request) const
{
    // Set up an HTTP POST request message
    http::request<http::string_body> req;
    req.method(http::verb::post);
    req.target("/v0.7/config");
    req.version(version);
    req.set(http::field::host, host_);
    req.set(http::field::user_agent, BOOST_BEAST_VERSION_STRING);
    req.set(http::field::content_length, std::to_string(request.size()));
    req.set(http::field::accept, "*/*");
    req.set(http::field::content_type, "application/x-www-form-urlencoded");
    req.body() = std::move(request);
    req.keep_alive(true);

    return execute_request(host_, port_, req);
};
