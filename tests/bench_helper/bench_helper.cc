#include "mpack-common.h"
#include "mpack-reader.h"
#include "mpack-writer.h"
#include "spdlog/common.h"
#include <algorithm>
#include <array>
#include <boost/asio/buffer.hpp>
#include <boost/asio/connect.hpp>
#include <boost/asio/deadline_timer.hpp>
#include <boost/asio/io_context.hpp>
#include <boost/asio/local/stream_protocol.hpp>
#include <boost/asio/read.hpp>
#include <boost/asio/spawn.hpp>
#include <boost/asio/steady_timer.hpp>
#include <boost/asio/write.hpp>
#include <boost/beast/core/basic_stream.hpp>
#include <boost/program_options.hpp>
#include <boost/program_options/value_semantic.hpp>
#include <boost/program_options/variables_map.hpp>
#include <boost/system/detail/errc.hpp>
#include <boost/system/detail/error_code.hpp>
#include <chrono>
#include <fstream>
#include <iostream>
#include <iterator>
#include <mpack.h>
#include <random>
#include <regex>
#include <spdlog/sinks/stdout_sinks.h>
#include <spdlog/spdlog.h>
#include <stdexcept>
#include <string>
#include <utility>

namespace po = boost::program_options;
namespace asio = boost::asio;
using error_code = boost::system::error_code;

template <typename R = std::ratio<1, 1>> struct CmdlineDuration {
    constexpr explicit CmdlineDuration(int64_t value_i)
        : value_{std::chrono::duration<int64_t, R>{value_i}}
    {}
    template <typename R2>
    constexpr explicit CmdlineDuration(std::chrono::duration<int64_t, R2> value)
        : value_{std::chrono::duration_cast<std::chrono::duration<int64_t, R>>(
              value)}
    {}
    constexpr CmdlineDuration() = default;
    std::chrono::duration<int64_t, R> value_{};

    template <typename R2> // NOLINTNEXTLINE
    operator std::chrono::duration<int64_t, R2>() const
    {
        return std::chrono::duration_cast<std::chrono::duration<int64_t, R2>>(
            value_);
    }

    friend std::ostream &operator<<(
        std::ostream &os, const CmdlineDuration<R> &duration)
    {
        static constexpr int64_t TEN_E3{1000};
        static constexpr int64_t TEN_E6{1000000};
        int64_t us_int = std::chrono::duration_cast<std::chrono::microseconds>(
            duration.value_)
                             .count();
        if (us_int >= TEN_E6) {
            os << static_cast<double>(us_int) / TEN_E6 << " s";
        } else if (us_int >= TEN_E3) {
            os << static_cast<double>(us_int) / TEN_E3 << " ms";
        } else {
            os << us_int << " us";
        }
        return os;
    }
};

template <class charT, typename R>
void validate(boost::any &v, const std::vector<std::basic_string<charT>> &xs,
    CmdlineDuration<R> *, long) // NOLINT
{
    po::validators::check_first_occurrence(v);
    std::string s(po::validators::get_single_string(xs));

    try {
        std::regex re{R"((\d+)\s?(s|ms|us|ns))"};
        std::smatch match;
        if (std::regex_match(s, match, re)) {
            auto dur_int = boost::lexical_cast<int64_t>(match[1]);
            if (match[2] == "ns") {
                v = ::CmdlineDuration<R>{std::chrono::nanoseconds{dur_int}};
            } else if (match[2] == "us") {
                v = ::CmdlineDuration<R>{std::chrono::microseconds{dur_int}};
            } else if (match[2] == "ms") {
                v = ::CmdlineDuration<R>{std::chrono::milliseconds{dur_int}};
            } else {
                v = ::CmdlineDuration<R>{std::chrono::seconds{dur_int}};
            }
        } else {
            throw po::invalid_option_value(s);
        }
    } catch (std::regex_error &) {
        throw po::invalid_option_value(s);
    }
}

static constexpr int default_concurrent_clients = 20;
static constexpr int default_req_per_client = 50;
static constexpr ::CmdlineDuration<std::milli> default_delay{200};   // NOLINT
static constexpr ::CmdlineDuration<> default_duration{60};           // NOLINT
static const std::string default_socket{"/tmp/ddappsec.sock"};       // NOLINT
static const std::string default_output{"bench_timings.bin"};        // NOLINT
static const std::string default_payload{"payload.msgpack"};         // NOLINT
static const std::string default_payload_sh{"req_shutdown.msgpack"}; // NOLINT

static constexpr std::uint64_t waf_timeout_ms = 10;
static constexpr int max_simultaneous_connects = 5;

namespace mpack {
std::string read_string(mpack_reader_t *r, mpack_tag_t tag)
{
    if (tag.type != mpack_type_str) {
        r->error = mpack_error_data;
        return {};
    }

    std::string res_str(static_cast<size_t>(tag.v.l), '\0');
    mpack_read_utf8(r, &res_str[0], tag.v.l);
    if (mpack_reader_error(r) != mpack_ok) {
        return {};
    }
    return res_str;
}
} // namespace mpack

// avoid blowing off the backlog of the socket, esp at startup
class ConnectionLimiter {
public:
    explicit ConnectionLimiter(asio::io_context &context, uint32_t limit)
        : limit_{limit}, timer_{context}
    {
        timer_.expires_at(boost::posix_time::pos_infin);
    }

    error_code connect(asio::local::stream_protocol::socket &sock,
        const asio::local::stream_protocol::endpoint &endpoint,
        const asio::yield_context &yield);

private:
    const uint32_t limit_;
    uint32_t connecting_{};
    asio::deadline_timer timer_;
};

class Benchmark {
public:
    explicit Benchmark(const po::variables_map &opt_vm);

    int run();

private:
    using yc = asio::yield_context;
    void wait_for_finish();
    void spawn_run(const yc &yield);
    void client_notify(std::chrono::microseconds req_duration);

    const std::string rules_file_;
    const int concurrent_clients_;
    const int req_per_client_;
    const std::chrono::milliseconds delay_;
    const std::chrono::seconds duration_;
    const asio::local::stream_protocol::endpoint sock_endpoint_;
    std::string payload_;
    std::string payload_shutdown_;
    std::ofstream os_;

    asio::io_context iocontext_;
    ConnectionLimiter limiter_;

    std::chrono::time_point<std::chrono::steady_clock> start;

    uint64_t total_requests_{};
};

class Client {
public:
    template <typename C>
    Client(C &&notify, std::string rules_file, int num_requests,
        std::chrono::milliseconds delay, const std::string &payload, // NOLINT
        const std::string &payload_shutdown,
        asio::local::stream_protocol::socket &&sock,
        asio::io_context &iocontext, asio::yield_context yc)
        : id_{++next_client_id}, notify_{std::forward<C>(notify)},
          rules_file_{std::move(rules_file)}, requests_left_{num_requests},
          delay_{delay}, payload_{payload}, payload_shutdown_{payload_shutdown},
          sock_{std::move(sock)}, iocontext_{iocontext}, yc_{std::move(yc)}
    {}

    void run();

private:
    struct Header {
        Header() = default;
        explicit Header(uint32_t size) : size{size} {}
        std::array<char, 4> marker{'d', 'd', 's', '\0'};
        uint32_t size{};
    } __attribute__((packed));

    static inline uint64_t next_client_id{0};

    void do_client_init();
    void do_request_init();
    void do_request_shutdown();

    template <const char *MessageType>
    void send_helper_payload(
        asio::const_buffer msg_begin, const std::string &payload);

    template <typename Function> std::string read_helper_response(Function &&f);

    uint64_t id_;
    std::function<void(std::chrono::microseconds)> notify_;
    const std::string rules_file_;
    int requests_left_;
    const std::chrono::milliseconds delay_;
    const std::string &payload_;
    const std::string &payload_shutdown_;

    boost::beast::basic_stream<asio::local::stream_protocol> sock_;

    asio::io_context &iocontext_;
    asio::yield_context yc_;
};

int main(int argc, char **argv)
{
    po::options_description opt_desc{"Allowed options"};
    // clang-format off
    opt_desc.add_options()
        ("help,h",               "Show this help")
        ("rules,u",              po::value<std::string>()->default_value(""),
                                 "The path to the rules file sent on client_init")
        ("socket,s",             po::value<std::string>()->default_value(default_socket),
                                 "The path to the UNIX socket")
        ("payload,p",            po::value<std::string>()->default_value(default_payload),
                                 "The path to the msgpack payload for request_init")
        ("payload-sh,t",         po::value<std::string>()->default_value(default_payload_sh),
                                 "The path to the msgpack payload for request_shutdown")
        ("output,o",             po::value<std::string>()->default_value(default_output),
                                 "Where to write the timings for each request")
        ("concurrent-clients,c", po::value<int>()->default_value(default_concurrent_clients),
                                 "The number of concurrent clients")
        ("req-per-client,r",     po::value<int>()->default_value(default_req_per_client),
                                 "The number of requests each client simulates")
        ("wait,w",               po::value<::CmdlineDuration<std::milli>>()->default_value(default_delay),
                                 "How much to wait between each client's request")
        ("duration,d",           po::value<::CmdlineDuration<>>()->default_value(default_duration),
                                 "How long to run the benchmark")
        ("verbose,v",            po::bool_switch()->default_value(false),
                                 "Enable verbose logging");
    // clang-format on

    po::variables_map opt_vm;
    try {
        auto parsed_options =
            po::command_line_parser(argc, argv).options(opt_desc).run();
        po::store(parsed_options, opt_vm);
    } catch (const std::exception &ex) {
        std::cerr << ex.what() << "\n";
        return 1;
    }
    po::notify(opt_vm);

    if (opt_vm.count("help") > 0) {
        std::cerr << opt_desc << "\n";
        return 0;
    }

    auto console = spdlog::stderr_logger_mt("console");
    spdlog::set_default_logger(console);
    spdlog::set_pattern("[%Y-%m-%d %H:%M:%S.%e][%l] %v at %s:%!");
    if (opt_vm["verbose"].as<bool>()) {
        spdlog::set_level(spdlog::level::debug);
    }

    Benchmark bench{opt_vm};
    return bench.run();
}

Benchmark::Benchmark(const po::variables_map &opt_vm)
    : rules_file_{opt_vm["rules"].as<std::string>()},
      concurrent_clients_{opt_vm["concurrent-clients"].as<int>()},
      req_per_client_{opt_vm["req-per-client"].as<int>()},
      delay_{opt_vm["wait"].as<CmdlineDuration<std::milli>>()},
      duration_{opt_vm["duration"].as<CmdlineDuration<>>()},
      sock_endpoint_{opt_vm["socket"].as<std::string>()},
      os_{opt_vm["output"].as<std::string>(),
          std::ios_base::out | std::ios_base::binary | std::ios_base::trunc},
      limiter_{iocontext_, max_simultaneous_connects}
{
    if (!os_.is_open()) {
        throw std::runtime_error{"Could not open output file"};
    }

    auto read_payload_file = [&](const std::string &opt) -> std::string {
        auto payload_f = opt_vm[opt].as<std::string>();
        SPDLOG_INFO("Using {} for payload", payload_f); // NOLINT
        std::ifstream payload_stream{payload_f};

        if (!payload_stream.is_open()) {
            throw std::runtime_error{"Could not open input file " + payload_f};
        }
        return {std::istreambuf_iterator<char>{payload_stream},
            std::istreambuf_iterator<char>{}};
    };
    payload_ = read_payload_file("payload");
    payload_shutdown_ = read_payload_file("payload-sh");
}

int Benchmark::run()
{
    for (int i = 0; i < concurrent_clients_; i++) {
        boost::asio::spawn(
            iocontext_, [this](const yc &yield) { spawn_run(yield); });
    }

    start = std::chrono::steady_clock::now();

    try {
        iocontext_.run_for(duration_);
    } catch (const std::exception &e) {
        std::cerr << "Error: " << e.what() << "\n";
        return 1;
    }

    auto duration = std::chrono::steady_clock::now() - start;
    double duration_secs{
        std::chrono::duration_cast<std::chrono::duration<double>>(duration)
            .count()};

    std::cout << "Did " << total_requests_ << " requests in " << duration_secs
              << " seconds\n";
    std::cout << "Average of "
              << (static_cast<double>(total_requests_) / duration_secs)
              << " req/s\n";

    return 0;
}

void Benchmark::spawn_run(const yc &yield)
{
    for (;;) {
        asio::local::stream_protocol::socket sock_{iocontext_};
        sock_.open();
        SPDLOG_DEBUG("Connecting to socket {}", sock_endpoint_.path());
        error_code ec = limiter_.connect(sock_, sock_endpoint_, yield);
        if (ec.failed()) {
            throw std::runtime_error{
                "Connection to endpoint failed: " + ec.message()};
        }

        Client c{[this](auto arg) { client_notify(arg); }, rules_file_,
            req_per_client_, delay_, payload_, payload_shutdown_,
            std::move(sock_), iocontext_, yield};
        c.run();
    }
}

void Benchmark::client_notify(std::chrono::microseconds req_duration)
{
    // NOLINTNEXTLINE
    os_.write(reinterpret_cast<char *>(&req_duration), sizeof(req_duration));
    total_requests_ += 1;
}

void Client::run()
{
    SPDLOG_DEBUG("C#{} Doing client_init", id_);
    try {
        do_client_init();
    } catch (const std::exception &e) {
        SPDLOG_ERROR("C#{} Error during client_init: {}", id_, e.what());
        throw;
    }
    SPDLOG_DEBUG("C#{} client_init done", id_);
    while (requests_left_-- > 0) {
        auto start{std::chrono::steady_clock::now()};
        do_request_init();
        do_request_shutdown();
        auto duration = std::chrono::steady_clock::now() - start;
        notify_(
            std::chrono::duration_cast<std::chrono::microseconds>(duration));

        asio::steady_timer timer{iocontext_};
        timer.expires_after(delay_);
        timer.async_wait(yc_);
    }
    sock_.socket().shutdown(
        boost::asio::local::stream_protocol::socket::shutdown_both);
    sock_.release_socket().close();
    SPDLOG_DEBUG("C#{} finished requests", id_);
}
void Client::do_client_init()
{
    // write
    {
        mpack_writer_t w;
        char *data;
        size_t size;
        mpack_writer_init_growable(&w, &data, &size);
        mpack_start_array(&w, 2);
        mpack_write(&w, "client_init");

        mpack_start_array(&w, 4);
        mpack_write(&w, static_cast<uint32_t>(getpid()));
        mpack_write(&w, "1.0.0");
        mpack_write(&w, "7.0.0");
        mpack_start_map(&w, 2);
        mpack_write(&w, "rules_file");
        mpack_write_str(
            &w, &rules_file_[0], static_cast<uint32_t>(rules_file_.size()));
        mpack_write(&w, "waf_timeout_ms");
        mpack_write_u64(&w, waf_timeout_ms);
        mpack_finish_map(&w);
        mpack_finish_array(&w);

        mpack_finish_array(&w);
        mpack_error_t err = mpack_writer_destroy(&w);
        if (err != mpack_ok) {
            throw std::runtime_error{
                std::string{"Error serializing client_init: "} +
                mpack_error_to_string(err)};
        }

        Header header{static_cast<uint32_t>(size)};
        std::array<asio::const_buffer, 2> buffers{
            asio::const_buffer{// NOLINTNEXTLINE
                reinterpret_cast<char *>(&header), sizeof(header)},
            {data, size},
        };
        SPDLOG_DEBUG("C#{} Writing client_init message", id_);
        sock_.expires_after(std::chrono::seconds{5}); // NOLINT
        error_code ec;
        asio::async_write(sock_, buffers, yc_[ec]);
        if (ec.failed()) {
            throw std::runtime_error{
                "Failed writing client_init message: " + ec.message()};
        }
        MPACK_FREE(data); // NOLINT
    }

    // read
    {
        SPDLOG_DEBUG("C#{} Reading client_init message response", id_);
        // we read the error array and version that should be after the status
        auto handle_resp = [this](mpack_reader_t *r, const std::string &status,
                               uint32_t num_elements) {
            mpack_tag_t tag;

            if (num_elements < 3) {
                throw std::runtime_error{
                    "Expected response to have at least 3 elements; has " +
                    std::to_string(num_elements)};
            }

            tag = mpack_read_tag(r);
            std::string version_str{mpack::read_string(r, tag)};
            if (mpack_reader_error(r) != mpack_ok) {
                return;
            }
            spdlog::default_logger_raw()->log(
                spdlog::source_loc{__FILE__, __LINE__, "do_client_init"},
                spdlog::level::debug, "C#{} Reported helper version is {}", id_,
                version_str);

            // if ok, we should have no error messages
            if (status == "ok") {
                return;
            }
            tag = mpack_read_tag(r);
            if (tag.type != mpack_type_array) {
                r->error = mpack_error_data;
                return;
            }
            if (tag.v.n < 1) {
                return;
            }
            tag = mpack_read_tag(r);
            std::string err_str{mpack::read_string(r, tag)};
            if (mpack_reader_error(r) != mpack_ok) {
                return;
            }
            spdlog::default_logger_raw()->log(
                spdlog::source_loc{__FILE__, __LINE__, "do_client_init"},
                spdlog::level::err, "C#{} Error message for client_init is: {}",
                id_, err_str);
        };

        std::string resp{read_helper_response(std::move(handle_resp))};
        SPDLOG_DEBUG("C#{} client_init response: {}", id_, resp);
        if (resp != "ok") {
            throw std::runtime_error{"client_init response was not ok"};
        }
    }
}

void Client::do_request_init()
{
    static constexpr char msg_beg[] = "\x92\xacrequest_init\x91"; // NOLINT
    static constexpr char request_init_id[] = "request_init";     // NOLINT
    send_helper_payload<request_init_id>(
        {&msg_beg, sizeof(msg_beg) - 1}, payload_);

    std::string resp{read_helper_response([](auto...) {})};
    if (resp != "ok" && resp != "record" && resp != "block") {
        throw std::runtime_error{
            "response to payload was neither ok nor block, nor monitor"};
    }
}
void Client::do_request_shutdown()
{
    static constexpr char msg_beg[] = "\x92\xb0request_shutdown\x91"; // NOLINT
    static constexpr char request_shutdown_id[] = "request_shutdown"; // NOLINT
    send_helper_payload<request_shutdown_id>(
        {&msg_beg, sizeof(msg_beg) - 1}, payload_shutdown_);

    std::string resp{read_helper_response([](auto...) {})};
    if (resp != "ok" && resp != "record" && resp != "block") {
        throw std::runtime_error{
            "response to payload was neither ok nor block, nor monitor"};
    }
}

template <const char *MessageType>
void Client::send_helper_payload(
    asio::const_buffer msg_begin, const std::string &payload)
{
    Header header{static_cast<uint32_t>(payload.size() + msg_begin.size())};
    std::array<asio::const_buffer, 3> buffers{
        asio::const_buffer{// NOLINTNEXTLINE
            reinterpret_cast<char *>(&header), sizeof(header)},
        msg_begin,
        {payload.data(), payload.size()},
    };
    sock_.expires_after(std::chrono::seconds{5}); // NOLINT
    error_code ec;
    asio::async_write(sock_, buffers, yc_[ec]);
    if (ec.failed()) {
        // NOLINTNEXTLINE
        throw std::runtime_error{std::string{"Failed writing "} + MessageType +
                                 " message: " + ec.message()};
    }
}

template <typename Function>
std::string Client::read_helper_response(Function &&f)
{
    Header header;
    sock_.expires_after(std::chrono::seconds{10}); // NOLINT
    error_code ec;
    size_t read{asio::async_read(
        sock_, asio::mutable_buffer{&header, sizeof(header)}, yc_[ec])};
    if (ec.failed()) {
        throw std::runtime_error{
            "Read of header of response failed: " + ec.message()};
    }
    if (read != sizeof(header)) {
        throw std::runtime_error{"Header of response to client_init too small"};
    }
    SPDLOG_DEBUG("C#{} response has {} bytes", id_,
        static_cast<decltype(header.size)>(header.size));
    mpack_reader_t r;
    std::array<char, 128> buffer{}; // NOLINT
    mpack_reader_init(&r, buffer.data(), buffer.size(), 0);
    std::tuple ctx{this, static_cast<decltype(header.size)>(header.size)};
    r.context = &ctx;
    mpack_reader_set_fill(
        &r, [](mpack_reader_t *r, char *buffer, size_t count) -> size_t {
            auto *context = static_cast<decltype(ctx) *>(r->context);
            auto *thiz = std::get<Client *>(*context);
            auto &left = std::get<1>(*context);
            size_t reading_now = std::min(count, static_cast<size_t>(left));
            error_code ec;
            auto read = asio::async_read(thiz->sock_,
                asio::mutable_buffer{buffer, reading_now}, thiz->yc_[ec]);
            left -= read;
            spdlog::default_logger_raw()->log(
                spdlog::source_loc{__FILE__, __LINE__, "read_helper_response"},
                spdlog::level::debug,
                "C#{} Read {} bytes, {} left in helper response", thiz->id_,
                read, left);

            if (ec.failed()) {
                throw std::runtime_error{"Error requesting " +
                                         std::to_string(count) +
                                         " more bytes: " + ec.message()};
            }

            return read;
        });

    uint32_t num_elements;
    {
        mpack_tag_t root = mpack_read_tag(&r);
        if (root.type != mpack_type_array) {
            throw std::runtime_error{"expected array at root"};
        }
        num_elements = root.v.n;
    }

    {
        mpack_tag_t result = mpack_read_tag(&r);
        if (result.type != mpack_type_str) {
            throw std::runtime_error{"expected string at root[0]"};
        }
        std::string res_str(static_cast<size_t>(result.v.l), '\0');
        mpack_read_utf8(&r, &res_str[0], result.v.l);
        if (mpack_reader_error(&r) != mpack_ok) {
            throw std::runtime_error{"error reading string at root[0]"};
        }

        f(&r, res_str, num_elements);
        if (mpack_reader_error(&r) != mpack_ok) {
            throw std::runtime_error{
                "error reading response (per-type handling)"};
        }

        // we need to fully read the response
        auto left_to_read{std::get<1>(ctx)};
        if (left_to_read) {
            SPDLOG_DEBUG("C#{} {} bytes remain to be read", id_, left_to_read);
        }
        while (left_to_read > 0) {
            static constexpr uint32_t discard_buffer_size{1024};
            std::array<char, discard_buffer_size> discard{};
            error_code ec;
            auto read = asio::async_read(sock_,
                asio::buffer(
                    &discard[0], std::min(left_to_read, discard_buffer_size)),
                yc_[ec]);
            if (ec.failed()) {
                throw std::runtime_error{
                    "Failure reading remaining of helper message: " +
                    ec.message()};
            }
            left_to_read -= read;
        }

        return res_str;
    }
}

error_code ConnectionLimiter::connect(
    asio::local::stream_protocol::socket &sock,
    const asio::local::stream_protocol::endpoint &endpoint,
    const asio::yield_context &yield)
{
    while (connecting_ >= limit_) {
        error_code ec;
        timer_.async_wait(yield[ec]);
        if (ec != boost::system::errc::operation_canceled) {
            throw std::runtime_error{"async_wait failed: " + ec.message()};
        }
    }

    connecting_ += 1;

    error_code ec;
    int tries = 0;
    while (true) {
        sock.async_connect(endpoint, yield[ec]);
        tries += 1;

        if (!ec.failed()) {
            break;
        }
        if (ec == boost::system::errc::no_buffer_space) {
            static std::random_device rd;
            static std::mt19937 gen{rd()};
            static constexpr int max_wait_us = 5000; // 5 ms
            static std::uniform_int_distribution<> distrib(1, max_wait_us);

            asio::steady_timer wait{timer_.get_executor()};
            wait.expires_after(std::chrono::microseconds{distrib(gen)});
            wait.async_wait(yield);
        } else {
            throw std::runtime_error{"connect() failed: " + ec.message()};
        }
    }

    SPDLOG_DEBUG(
        "Connected for sock {} after {} tries", sock.native_handle(), tries);
    connecting_ -= 1;
    timer_.cancel_one();
    return ec;
}
