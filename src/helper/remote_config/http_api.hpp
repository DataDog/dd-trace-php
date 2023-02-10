// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <boost/asio/connect.hpp>
#include <boost/asio/ip/tcp.hpp>
#include <boost/beast/core.hpp>
#include <boost/beast/http.hpp>
#include <boost/beast/version.hpp>
#include <cstdlib>
#include <iostream>
#include <optional>
#include <spdlog/spdlog.h>
#include <string>

namespace beast = boost::beast; // from <boost/beast.hpp>
namespace http = beast::http;   // from <boost/beast/http.hpp>
namespace net = boost::asio;    // from <boost/asio.hpp>
using tcp = net::ip::tcp;       // from <boost/asio/ip/tcp.hpp>

namespace dds::remote_config {

static const int version = 11;

class http_api {
public:
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    http_api(std::string host, std::string port)
        : host_(std::move(host)), port_(std::move(port)){};

    http_api(const http_api &) = delete;
    http_api(http_api &&) = delete;

    http_api &operator=(const http_api &) = delete;
    http_api &operator=(http_api &&) = delete;

    virtual ~http_api() = default;

    virtual std::optional<std::string> get_configs(std::string &&request) const
    {
        std::optional<std::string> result;
        try {
            const char *target = "/v0.7/config";

            // The io_context is required for all I/O
            net::io_context ioc;

            // These objects perform our I/O
            tcp::resolver resolver(ioc);
            beast::tcp_stream stream(ioc);

            // Look up the domain name
            auto const results = resolver.resolve(host_, port_);

            // Make the connection on the IP address we get from a lookup
            stream.connect(results);

            // Set up an HTTP POST request message
            http::request<http::string_body> req;
            req.method(http::verb::post);
            req.target(target);
            req.version(version);
            req.set(http::field::host, host_);
            req.set(http::field::user_agent, BOOST_BEAST_VERSION_STRING);
            req.set(
                http::field::content_length, std::to_string(request.size()));
            req.set(http::field::accept, "*/*");
            req.set(
                http::field::content_type, "application/x-www-form-urlencoded");
            req.body() = request;
            req.keep_alive(true);

            // Send the HTTP request to the remote host
            http::write(stream, req);

            // This buffer is used for reading and must be persisted
            beast::flat_buffer buffer;

            // Declare a container to hold the response
            http::response<http::dynamic_body> res;
            // Receive the HTTP response
            http::read(stream, buffer, res);

            // Write the message to output string
            result = boost::beast::buffers_to_string(res.body().data());

            // Gracefully close the socket
            beast::error_code ec;
            stream.socket().shutdown(tcp::socket::shutdown_both, ec);

            // not_connected happens sometimes
            // so don't bother reporting it.
            //
            if (ec && ec != beast::errc::not_connected) {
                throw beast::system_error{ec};
            }
            // If we get here then the connection is closed gracefully
        } catch (std::exception const &e) {
            SPDLOG_ERROR("Connection error - {}", e.what());
            return std::nullopt;
        }
        return result;
    };

protected:
    std::string host_;
    std::string port_;
};

} // namespace dds::remote_config
