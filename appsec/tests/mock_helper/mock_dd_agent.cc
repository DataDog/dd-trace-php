#include "mock_dd_agent.hpp"
#include "mock_helper_main.hpp"

#include <boost/algorithm/string.hpp>
#include <boost/asio/buffer.hpp>
#include <boost/asio/ip/basic_endpoint.hpp>
#include <boost/asio/ip/tcp.hpp>
#include <boost/asio/read.hpp>
#include <boost/asio/read_until.hpp>
#include <boost/asio/spawn.hpp>
#include <boost/asio/write.hpp>
#include <boost/lexical_cast.hpp>

#include <regex>
#include <stdexcept>
#include <unistd.h>
#include <utility>

namespace ip = asio::ip;
using boost::asio::yield_context;
using boost::system::error_code;

class HttpClient {
  public:
      HttpClient(EchoPipe &echo_pipe, ip::tcp::socket &&sock)
          : echo_pipe_{echo_pipe}, sock_{std::move(sock)}
      {}
      HttpClient(const HttpClient &) = delete;
      HttpClient(HttpClient &&) = delete;
      HttpClient &operator=(const HttpClient &) = delete;
      HttpClient &operator=(HttpClient &&) = delete;
      ~HttpClient() { SPDLOG_INFO("Closing HTTP connection"); }

      void do_request(const yield_context &yield)
      {
          try {
              _do_request(yield);
          } catch (const std::exception &e) {
              SPDLOG_WARN("Error handling HTTP request: {}", e.what());
          } catch (...) {
              SPDLOG_WARN("Error handling HTTP request");
          }
          SPDLOG_DEBUG("Finished request handling");
    }

  private:
    void _do_request(const yield_context& yield)
    {
        SPDLOG_DEBUG("do_request_line");
        do_request_line(yield);
        SPDLOG_DEBUG("do_headers");
        do_headers(yield);

        if (method_ == "PUT" && uri_ == "/v0.4/traces") {
            SPDLOG_DEBUG("do_traces");
            do_traces(yield);
        } else if (method_ == "POST" &&
                   uri_ == "/telemetry/proxy/api/v2/apmtelemetry") {
            SPDLOG_INFO("Ignoring telemetry data");
        } else {
            SPDLOG_WARN("Don't know how to  handle {} {}", method_, uri_);
        }

        SPDLOG_DEBUG("do_response");
        do_response(yield);
    }

    void do_request_line(yield_context yield)
    {
        size_t const size = asio::async_read_until(
            sock_, asio::dynamic_buffer(buf_), '\r', yield);
        SPDLOG_DEBUG("Read request line with size {}", size - 1);

        std::sregex_iterator it{
            buf_.begin(), buf_.begin() + static_cast<ssize_t>(size), word};
        if (it != itend) {
            method_ = it->str();
            SPDLOG_DEBUG("Method: {}", method_);
        } else {
            throw std::runtime_error{"no method in request line"};
        }

        it++;
        if (it != itend) {
            uri_ = it->str();
            SPDLOG_DEBUG("URI: {}", uri_);
        } else {
            throw std::runtime_error{"no uri in request line"};
        }

        it++;
        if (it != itend) {
            auto http_version = it->str();
            if (http_version != "HTTP/1.1") {
                throw std::runtime_error{
                    "protocol is not HTTP/1.1; got" + http_version};
            }
        } else {
            throw std::runtime_error{"no protocol in request line"};
        }

        consume(size);
        consume_chars(yield, '\n');
    }

    void do_headers(const yield_context& yield)
    {
        while (do_single_header(yield)) {}
    }

    bool do_single_header(yield_context yield)
    {
        size_t const read = asio::async_read_until(
            sock_, asio::dynamic_buffer(buf_), '\r', yield);

        if (read == 1) {
            consume_chars(yield, '\r', '\n');
            return false;
        }
        std::smatch match;
        std::string header;
        if (std::regex_search(buf_.cbegin(),
                buf_.cbegin() + static_cast<ssize_t>(read), match,
                header_name)) {
            header = match.str();
        } else {
            throw std::runtime_error{"No header name found"};
        }

        auto v{match.suffix().first};
        v++; // go over :
        while (*v == ' ') { v++; }
        std::string value{v, match.suffix().second - 1};

        SPDLOG_DEBUG("Header {}: {}", header, value);

        boost::algorithm::to_lower(header);
        headers_.emplace(std::move(header), std::move(value));

        consume(read);
        consume_chars(yield, '\n');
        return true;
    }

    void do_traces(const yield_context& yield)
    {
        auto count_header = headers_.find(trace_count_header);
        if (count_header == headers_.end()) {
            throw std::runtime_error(std::string{"header "} +
                                     std::string{trace_count_header} +
                                     " not found");
        }
        int count = boost::lexical_cast<int>(count_header->second);
        SPDLOG_INFO("Got {} traces", count);
        for (int i = 0; i < count; i++) { do_single_trace(yield); }
        consume_chars(yield, '0', '\r', '\n');
    }

    void do_single_trace(yield_context yield)
    {
        auto dyn_buf = asio::dynamic_buffer(buf_);
        size_t const read = asio::async_read_until(sock_, dyn_buf, '\r', yield);

        // read the message length
        std::string_view const size_str{buf_.data(), read - 1};
        SPDLOG_DEBUG("Trace size as string: {}", size_str);

        size_t msg_size;
        std::stringstream ss;
        ss << std::hex << size_str;
        ss >> msg_size;

        SPDLOG_DEBUG("Next trace has size {}", msg_size);
        consume(read);
        consume_chars(yield, '\n');

        if (buf_.size() < msg_size + 2 /* for \r\n */) {
            buf_.reserve(msg_size + 2);
            size_t const missing = msg_size + 2 - buf_.size();
            asio::async_read(sock_, dyn_buf.prepare(missing), yield);
        }

        MsgpackToJson tojson{buf_.data(), msg_size};
        auto &writer = tojson.writer();
        writer.StartObject();
        writer.Key("method");
        writer.String(method_);
        writer.Key("uri");
        writer.String(uri_);
        writer.Key("payload");
        writer.Flush();

        tojson.convert();

        writer.EndObject(3);

        consume(msg_size);
        consume_chars(yield, '\r', '\n');

        auto buffer = tojson.asio_buffer();
        echo_pipe_.write(buffer, yield);
    }

    template <typename... T>
    void consume_chars(yield_context yield, char c, T... rest_chars)
    {
        constexpr auto num_chars{sizeof...(rest_chars) + 1};
        if (buf_.size() < num_chars) {
            auto needed{num_chars - buf_.size()};
            auto dyn_buf = asio::dynamic_buffer(buf_);
            asio::async_read(sock_, dyn_buf.prepare(needed), yield);
        }

        std::tuple chars{c, rest_chars...};
        check_n_chars(chars, std::make_index_sequence<num_chars>{});

        consume(num_chars);
    }

    template <typename Tuple, size_t... Is>
    void check_n_chars(Tuple &&tuple, std::index_sequence<Is...> /*seq*/)
    {
        auto check_char = [](char read, char exp) {
            if (read != exp) {
                throw std::runtime_error{
                    std::string{"Expected read char to be "} +
                    std::to_string(static_cast<unsigned int>(exp)) +
                    " but it was " +
                    std::to_string(static_cast<unsigned int>(read))};
            }
        };
        (check_char(buf_[Is], std::get<Is>(tuple)), ...);
    }

    void do_response(yield_context yield)
    {
        static const std::array resp{
            "HTTP/1.1 200 OK\r\n"
            "Content-Type: application/json\r\n"
            "Content-Length: 40\r\n"
            "\r\n"
            R"({"rate_by_service":{"service:,env:":1}}")"
            "\n"};
        asio::async_write(
            sock_, asio::const_buffer(resp.data(), sizeof(resp) - 1), yield);
    }

    void consume(size_t bytes)
    {
        if (buf_.size() < bytes) {
            throw std::runtime_error{
                "Tried to consume more bytes than available on buffer: " +
                std::to_string(bytes) + " > " + std::to_string(buf_.size())};
        }
        buf_.erase(0, bytes);
    }

    // NOLINTNEXTLINE
    static inline const std::regex word{R"(\S+)"};
    // NOLINTNEXTLINE
    static inline const std::regex header_name{R"(^[^:]+(?=:))"};
    // NOLINTNEXTLINE
    static inline const std::sregex_iterator itend;
    static inline constexpr std::string_view trace_count_header{
        "x-datadog-trace-count"};

    EchoPipe &echo_pipe_;
    ip::tcp::socket sock_;

    std::string buf_;

    std::string method_;
    std::string uri_;
    std::map<std::string, std::string, std::less<>> headers_;
};

HttpServerDispatcher::HttpServerDispatcher(
    EchoPipe &echo_pipe, ip::port_type port)
    : echo_pipe_{echo_pipe}, acceptor_{iocontext}
{
    ip::tcp::endpoint endpoint{ip::tcp::v4(), port};
    acceptor_.open(endpoint.protocol());
    acceptor_.set_option(ip::tcp::acceptor::reuse_address{true});
    acceptor_.bind(endpoint);
}
HttpServerDispatcher::~HttpServerDispatcher()
{
    if (!iocontext.stopped()) {
        SPDLOG_INFO("Closing listening HTTP socket");
    }
}
void HttpServerDispatcher::start()
{
    acceptor_.listen(backlog); // synchronous; may throw
    SPDLOG_INFO("Listening for TCP connections (mock datadog agent)");
}
void HttpServerDispatcher::run_loop(const yield_context& yield)
{
    SPDLOG_INFO("Started HttpServerDisp:atcher loop");
    while (!iocontext.stopped()) {
        ip::tcp::socket client_sock{iocontext};
        error_code ec;
        acceptor_.async_accept(client_sock, yield[ec]);
        if (ec) {
            SPDLOG_WARN("Failed accepting socket: {}", ec.message());
            break;
        }
        SPDLOG_INFO("Accepted connection");
        spawn(iocontext,
            [&pipe = this->echo_pipe_, sock = std::move(client_sock)](
                auto yield) mutable {
                HttpClient client{pipe, std::move(sock)};
                client.do_request(yield);
            }, [](std::exception_ptr e) {
                    if (e) std::rethrow_exception(e);
                });
    }
    SPDLOG_INFO("Finished HttpServerDispatcher loop");
}
