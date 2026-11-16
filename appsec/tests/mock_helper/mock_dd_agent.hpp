// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include "mock_helper_main.hpp"
#include <boost/asio/ip/basic_endpoint.hpp>
#include <boost/asio/ip/tcp.hpp>
#include <boost/asio/spawn.hpp>

class HttpServerDispatcher {
public:
    static constexpr int backlog = 1;

    HttpServerDispatcher(EchoPipe &echo_pipe, asio::ip::port_type port);
    HttpServerDispatcher(const HttpServerDispatcher&) = delete;
    HttpServerDispatcher& operator=(const HttpServerDispatcher&) = delete;
    HttpServerDispatcher(HttpServerDispatcher&&) = delete;
    HttpServerDispatcher& operator=(HttpServerDispatcher&&) = delete;
    ~HttpServerDispatcher();

    void start();

    void run_loop(const boost::asio::yield_context& yield);

  private:
    EchoPipe &echo_pipe_;
    asio::ip::tcp::acceptor acceptor_;
};
