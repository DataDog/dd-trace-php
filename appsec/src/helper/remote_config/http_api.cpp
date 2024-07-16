// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "http_api.hpp"
#include <boost/asio/awaitable.hpp>
#include <boost/asio/co_spawn.hpp>
#include <boost/asio/connect.hpp>
#include <boost/asio/ip/tcp.hpp>
#include <boost/asio/this_coro.hpp>
#include <boost/asio/use_awaitable.hpp>
#include <boost/beast/core.hpp>
#include <boost/beast/core/stream_traits.hpp>
#include <boost/beast/http.hpp>
#include <boost/beast/version.hpp>
#include <exception>
#include <future>
#include <optional>
#include <spdlog/spdlog.h>
#include <string>

namespace beast = boost::beast; // from <boost/beast.hpp>
namespace http = beast::http;   // from <boost/beast/http.hpp>
namespace net = boost::asio;    // from <boost/asio.hpp>
using tcp = net::ip::tcp;       // from <boost/asio/ip/tcp.hpp>

namespace {
constexpr auto timeout =
    std::chrono::duration_cast<net::steady_timer::duration>(
        std::chrono::seconds{60});
const int version = 11;

// NOLINTNEXTLINE(cppcoreguidelines-avoid-reference-coroutine-parameters)
net::awaitable<std::string> execute_request(const std::string &host,
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-reference-coroutine-parameters)
    const std::string &port, const http::request<http::string_body> &request)
{
    std::string result;

    try {
        auto exec = co_await net::this_coro::executor;

        // These objects perform our I/O
        tcp::resolver resolver(exec);
        beast::tcp_stream stream(exec);

        // Look up the domain name
        auto const results =
            co_await resolver.async_resolve(host, port, net::use_awaitable);

        // Make the connection on the IP address we get from a lookup
        beast::get_lowest_layer(stream).expires_after(timeout);
        co_await stream.async_connect(
            results.begin(), results.end(), net::use_awaitable);

        // Send the HTTP request to the remote host
        co_await http::async_write(stream, request, net::use_awaitable);

        // This buffer is used for reading and must be persisted
        beast::flat_buffer buffer;

        // Declare a container to hold the response
        http::response<http::dynamic_body> res;

        // Receive the HTTP response
        co_await http::async_read(stream, buffer, res, net::use_awaitable);

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

    co_return result;
}

std::string execute_request_sync(const std::string &host,
    const std::string &port, const http::request<http::string_body> &req)
{

    net::io_context ioc;
    net::awaitable<std::string> client_coroutine =
        execute_request(host, port, req);

    std::promise<std::string> promise;
    auto fut = promise.get_future();

    net::co_spawn(ioc, std::move(client_coroutine),
        [&](const std::exception_ptr &eptr, std::string body) {
            if (eptr) {
                promise.set_exception(eptr);
            } else {
                promise.set_value(std::move(body));
            }
        });

    ioc.run();
    return fut.get();
}
} // namespace

std::string dds::remote_config::http_api::get_info() const
{
    http::request<http::string_body> req{http::verb::get, "/info", version};
    req.set(http::field::host, host_);
    req.set(http::field::user_agent, BOOST_BEAST_VERSION_STRING);

    return execute_request_sync(host_, port_, req);
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

    return execute_request_sync(host_, port_, req);
};
