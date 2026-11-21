#include "spdlog/common.h"
#include "spdlog/sinks/stdout_sinks.h"
#include <boost/asio/buffer.hpp>
#include <boost/asio/io_context.hpp>
#include <boost/asio/ip/tcp.hpp>
#include <boost/asio/local/stream_protocol.hpp>
#include <boost/asio/posix/stream_descriptor.hpp>
#include <boost/asio/post.hpp>
#include <boost/asio/read.hpp>
#include <boost/asio/signal_set.hpp>
#include <boost/asio/spawn.hpp>
#include <boost/asio/steady_timer.hpp>
#include <boost/asio/write.hpp>
#include <boost/program_options.hpp>
#include <boost/stacktrace.hpp>
#include <chrono>
#include <system_error>
#define RAPIDJSON_NO_SIZETYPEDEFINE
namespace rapidjson {
using SizeType = std::uint32_t;
} // namespace rapidjson
#include <rapidjson/document.h>
#include <rapidjson/error/en.h>
#include <rapidjson/rapidjson.h>
#include <rapidjson/writer.h>

#include <spdlog/spdlog.h>

#include <algorithm>
#include <ctime>
#include <fcntl.h>
#include <ios>
#include <iostream>
#include <list>
#include <memory>
#include <optional>
#include <stdexcept>
#include <string>
#include <sys/file.h>
#include <sys/stat.h>
#include <sys/un.h>
#include <unistd.h>
#include <vector>

#include "mock_dd_agent.hpp"
#include "mock_helper_main.hpp"
#include <cstring>

namespace po = boost::program_options;
namespace asio = boost::asio;
namespace local = asio::local;
namespace posix = asio::posix;
using boost::system::error_code;

// NOLINTNEXTLINE(cert-err58-cpp,fuchsia-statically-constructed-objects)
asio::io_context iocontext;
bool continuous_mode;
// When set, exit as soon as the scripted responses are exhausted instead of
// waiting for the extension to close its side.
bool no_wait_shutdown;

MsgpackToJson::MsgpackToJson(const char *buffer, size_t size)
    : pos_{buffer}, end_{buffer + size}
{}

void MsgpackToJson::convert()
{
    write(0);
    if (pos_ != end_) {
        throw std::runtime_error{"Trailing data after msgpack object"};
    }
}

const char *MsgpackToJson::take(std::size_t n)
{
    if (static_cast<std::size_t>(end_ - pos_) < n) {
        throw std::runtime_error{"Truncated msgpack object"};
    }
    const char *p = pos_;
    pos_ += n; // NOLINT(cppcoreguidelines-pro-bounds-pointer-arithmetic)
    return p;
}

std::uint64_t MsgpackToJson::read_be(std::size_t n)
{
    const char *p = take(n);
    std::uint64_t v = 0;
    for (std::size_t i = 0; i < n; i++) {
        // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-pointer-arithmetic)
        v = (v << 8U) | static_cast<std::uint8_t>(p[i]);
    }
    return v;
}

// NOLINTNEXTLINE(misc-no-recursion)
void MsgpackToJson::write_map(std::uint32_t len, unsigned depth)
{
    writer_.StartObject();
    for (std::uint32_t i = 0; i < len; i++) {
        auto tag = static_cast<std::uint8_t>(*take(1));
        std::size_t key_len = 0;
        if ((tag & 0xe0U) == 0xa0U) { // NOLINT(readability-magic-numbers)
            key_len = tag & 0x1fU;    // NOLINT(readability-magic-numbers)
        } else if (tag == 0xd9) {     // NOLINT(readability-magic-numbers)
            key_len = read_be(1);
        } else if (tag == 0xda) { // NOLINT(readability-magic-numbers)
            key_len = read_be(2);
        } else if (tag == 0xdb) { // NOLINT(readability-magic-numbers)
            key_len = read_be(4);
        } else {
            throw std::runtime_error{
                "saw nonstring map key in msgpack message"};
        }
        const char *key = take(key_len);
        writer_.Key(key, static_cast<rapidjson::SizeType>(key_len));
        write(depth + 1);
    }
    writer_.EndObject();
}

// NOLINTNEXTLINE(misc-no-recursion)
void MsgpackToJson::write_array(std::uint32_t len, unsigned depth)
{
    writer_.StartArray();
    for (std::uint32_t i = 0; i < len; i++) { write(depth + 1); }
    writer_.EndArray();
}

// NOLINTNEXTLINE(misc-no-recursion,readability-function-cognitive-complexity)
void MsgpackToJson::write(unsigned depth)
{
    static constexpr unsigned max_depth = 64;
    if (depth > max_depth) {
        throw std::runtime_error{"msgpack object nested too deeply"};
    }
    auto tag = static_cast<std::uint8_t>(*take(1));
    // NOLINTBEGIN(readability-magic-numbers,cppcoreguidelines-avoid-magic-numbers)
    if (tag <= 0x7f) {
        writer_.Uint64(tag);
    } else if (tag >= 0xe0) {
        writer_.Int64(static_cast<std::int8_t>(tag));
    } else if ((tag & 0xf0U) == 0x80U) {
        write_map(tag & 0x0fU, depth);
    } else if ((tag & 0xf0U) == 0x90U) {
        write_array(tag & 0x0fU, depth);
    } else if ((tag & 0xe0U) == 0xa0U) {
        std::size_t len = tag & 0x1fU;
        writer_.String(take(len), static_cast<rapidjson::SizeType>(len));
    } else {
        switch (tag) {
        case 0xc0:
            writer_.Null();
            break;
        case 0xc2:
            writer_.Bool(false);
            break;
        case 0xc3:
            writer_.Bool(true);
            break;
        case 0xc4:
        case 0xc5:
        case 0xc6:
        case 0xd9:
        case 0xda:
        case 0xdb: {
            std::size_t n = (tag == 0xc4 || tag == 0xd9)   ? 1
                            : (tag == 0xc5 || tag == 0xda) ? 2
                                                           : 4;
            std::size_t len = read_be(n);
            writer_.String(take(len), static_cast<rapidjson::SizeType>(len));
            break;
        }
        case 0xca: {
            auto bits = static_cast<std::uint32_t>(read_be(4));
            float f;
            std::memcpy(&f, &bits, sizeof(f));
            writer_.Double(f);
            break;
        }
        case 0xcb: {
            std::uint64_t bits = read_be(8);
            double d;
            std::memcpy(&d, &bits, sizeof(d));
            writer_.Double(d);
            break;
        }
        case 0xcc:
            writer_.Uint64(read_be(1));
            break;
        case 0xcd:
            writer_.Uint64(read_be(2));
            break;
        case 0xce:
            writer_.Uint64(read_be(4));
            break;
        case 0xcf:
            writer_.Uint64(read_be(8));
            break;
        case 0xd0:
            writer_.Int64(static_cast<std::int8_t>(read_be(1)));
            break;
        case 0xd1:
            writer_.Int64(static_cast<std::int16_t>(read_be(2)));
            break;
        case 0xd2:
            writer_.Int64(static_cast<std::int32_t>(read_be(4)));
            break;
        case 0xd3:
            writer_.Int64(static_cast<std::int64_t>(read_be(8)));
            break;
        case 0xdc:
            write_array(static_cast<std::uint32_t>(read_be(2)), depth);
            break;
        case 0xdd:
            write_array(static_cast<std::uint32_t>(read_be(4)), depth);
            break;
        case 0xde:
            write_map(static_cast<std::uint32_t>(read_be(2)), depth);
            break;
        case 0xdf:
            write_map(static_cast<std::uint32_t>(read_be(4)), depth);
            break;
        default:
            throw std::runtime_error{
                "saw unsupported msgpack object with tag " +
                std::to_string(tag)};
        }
    }
    // NOLINTEND(readability-magic-numbers,cppcoreguidelines-avoid-magic-numbers)
}

EchoPipe::EchoPipe() : stream_{try_fds()}
{
    if (!stream_.is_open()) {
        SPDLOG_CRITICAL("Expected pipe stream to be open");
        throw std::runtime_error{"No open pipe stream found"};
    }
}
void EchoPipe::write(asio::const_buffer buff, asio::yield_context yield)
{
    std::array<asio::const_buffer, 2> buffers{buff, {"", 1}};
    SPDLOG_INFO("Writing to echo pipe {} + 1 bytes", buffers[0].size());
    SPDLOG_DEBUG("Content: {}",
        std::string_view{
            static_cast<const char *>(buffers[0].data()), buffers[0].size()});

    async_write(stream_, buffers, yield);

    SPDLOG_INFO("Finished write to echo pipe");
}
posix::stream_descriptor EchoPipe::try_fds()
{
    // if running with valgrind, the fd for the valgrind log can be before
    // or after ours so the pipe can be 4 or 5
    auto res = try_single_fd(5); // NOLINT
    if (!res) {
        res = try_single_fd(4); // NOLINT
    }
    if (!res) {
        res = try_single_fd(STDOUT_FILENO);
    }
    if (!res) {
        SPDLOG_CRITICAL("No available fd for echo pipe");
        throw std::runtime_error{"No fd available for echo pipe"};
    }
    return std::move(res.value());
}
std::optional<posix::stream_descriptor> EchoPipe::try_single_fd(int fd)
{
    struct ::stat statbuf = {0};
    if (fstat(fd, &statbuf) == -1) {
        error_code const ec = {errno, boost::system::system_category()};
        SPDLOG_INFO("fstat() failed for fd {}: {}", fd, ec.message());
        return std::nullopt;
    }
    if ((statbuf.st_mode & (S_IFIFO | S_IFCHR)) == 0) {
        SPDLOG_INFO(
            "File descriptor {0}  is not a FIFO or character device: {1:o}", fd,
            statbuf.st_mode & S_IFMT);
        return std::nullopt;
    }

    const int new_fd = ::dup(fd); // NOLINT(android-cloexec-dup)
    if (new_fd == -1) {
        error_code const ec = {errno, boost::system::system_category()};
        SPDLOG_INFO("Call to dup of fd {} failed: {}", fd, ec.message());
        return std::nullopt;
    }
    SPDLOG_INFO("Using dup of file descriptor {} as the echo pipe", fd);

    return {{iocontext, new_fd}};
}

// A scripted response: either the list of responses, or an object with that
// list as "msg" and a "delay" in seconds before sending it.
class ScriptedResponse {
public:
    explicit ScriptedResponse(rapidjson::Document &doc)
    {
        const rapidjson::Value *msg = &doc;
        if (doc.IsObject()) {
            if (doc.HasMember("delay")) {
                auto &delay = doc["delay"];
                if (!delay.IsInt()) {
                    throw std::runtime_error{"Delay is not an integer"};
                }
                delay_ = static_cast<decltype(delay_)>(delay.GetInt());
            }
            if (!doc.HasMember("msg")) {
                throw std::runtime_error("JSON document has no 'msg' member");
            }
            msg = &doc["msg"];
            if (!msg->IsArray()) {
                throw std::runtime_error("Value of 'msg' member is no array");
            }
        } else if (!doc.IsArray()) {
            throw std::runtime_error{
                "JSON document is not an object or array, got " +
                std::to_string(doc.GetType())};
        }
        rapidjson::Writer<rapidjson::StringBuffer> writer{json_};
        msg->Accept(writer);
    }

    [[nodiscard]] std::uint16_t delay() const noexcept { return delay_; }
    [[nodiscard]] asio::const_buffer buffer() const
    {
        return {json_.GetString(), json_.GetLength()};
    }

private:
    std::uint16_t delay_ = 0;
    rapidjson::StringBuffer json_;
};

class Client {
private:
    struct Header {
        char marker[4]; // NOLINT
        uint32_t size;
    } __attribute__((packed));

public:
    Client(local::stream_protocol::socket &&sock, EchoPipe &echo_pipe,
        std::vector<rapidjson::Document> responses)
        : sock_{std::move(sock)}, echo_pipe_{echo_pipe},
          responses_{std::move(responses)}, next_response_{responses_.begin()}
    {}
    ~Client() = default;
    Client(Client &&) = delete;
    Client &operator=(Client &&) = delete;
    Client(const Client &) = delete;
    const Client &operator=(const Client &) = delete;

    void run_loop(const asio::yield_context &yield)
    {
        unsigned count = 0;
        bool exited = false;
        while (!exited && next_response_ != responses_.end()) {
            SPDLOG_INFO("Will read message #{}", ++count);
            exited = run_loop_body(yield, /*scripted=*/true);
            if (!exited) {
                next_response_++;
            }
        }
        if (continuous_mode) {
            if (next_response_ != responses_.begin()) {
                next_response_--;
            }
            while (!exited) {
                SPDLOG_INFO("Will read message #{} (continuous mode)", ++count);
                exited = run_loop_body(yield, /*scripted=*/true);
            }
        } else if (!no_wait_shutdown) {
            // All scripted responses were consumed: wait until the extension
            // closes its side (at its exit), so that it does not see a
            // premature EOF.
            while (!exited) {
                SPDLOG_INFO(
                    "Waiting for the extension to exit (message #{})", ++count);
                exited = run_loop_body(yield, /*scripted=*/false);
            }
        }
        SPDLOG_INFO("All responses given; exiting");
    }

private:
    // When scripted is true a queued response (*next_response_) is sent for the
    // message read; when false (after the scripted responses are exhausted) we
    // only expect EOF and exit afterwards.
    bool run_loop_body(const asio::yield_context &yield, bool scripted)
    {
        SPDLOG_INFO("Waiting for client message...");
        // NOLINTNEXTLINE(cppcoreguidelines-pro-type-member-init,hicpp-member-init)
        Header header;
        auto buffer = asio::mutable_buffer(
            // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
            reinterpret_cast<char *>(&header), sizeof(header));

        error_code ec;
        auto num_read = async_read(sock_, buffer, yield[ec]);
        if (ec.value() == boost::asio::error::eof) {
            SPDLOG_INFO("The client exited");
            return true;
        }
        if (ec.failed()) {
            throw std::runtime_error{
                "Failing reading client message header: " + ec.message()};
        }
        if (num_read != sizeof(header)) {
            throw std::runtime_error{"Read " + std::to_string(num_read) +
                                     " bytes, less than the header size"};
        }

        // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
        if (std::memcmp(header.marker, "dds", sizeof(header.marker)) != 0) {
            throw std::runtime_error{"Invalid header on client message"};
        }
        SPDLOG_INFO("Reading client message with size {}",
            decltype(header.size){header.size});

        std::vector<std::byte> data{header.size};
        num_read = async_read(sock_, asio::buffer(data), yield[ec]);
        if (ec.failed()) {
            throw std::runtime_error{
                "Failed reading client message body: " + ec.message()};
        }
        if (num_read != header.size) {
            throw std::runtime_error{"Read " + std::to_string(num_read) +
                                     " bytes, less than the expected " +
                                     std::to_string(header.size)};
        }

        SPDLOG_INFO("Handling gotten message of size {}", data.size());

        if (!scripted) {
            SPDLOG_INFO("Unexpected non-shutdown message after all scripted "
                        "responses were consumed; exiting");
            return true;
        }

        // Requests are JSON already.
        echo_pipe_.write(asio::buffer(data), yield);
        handle_client_data(*next_response_, yield);
        return false;
    }

    void handle_client_data(rapidjson::Document &doc, asio::yield_context yield)
    {
        ScriptedResponse const response{doc};
        if (response.delay() != 0U) {
            SPDLOG_INFO("Will wait {} seconds before sending response",
                response.delay());
            asio::steady_timer timer{iocontext};
            timer.expires_after(std::chrono::seconds{response.delay()});
            timer.async_wait(yield);
        }

        Header h{};
        memcpy(&h.marker, "dds", 4);
        h.size = static_cast<std::uint32_t>(response.buffer().size());
        SPDLOG_INFO("Writing response; size {} (header) + {} (body)", sizeof(h),
            response.buffer().size());

        std::array const buffers = {
            // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
            asio::const_buffer(reinterpret_cast<char *>(&h), sizeof(h)),
            response.buffer(),
        };
        async_write(sock_, buffers, yield);

        SPDLOG_INFO("Response succcesfully written");
    }

    local::stream_protocol::socket sock_;
    EchoPipe &echo_pipe_; // NOLINT
    std::vector<rapidjson::Document> responses_;
    decltype(responses_.begin()) next_response_;
};

auto parse_responses(const std::vector<std::string> &responses_str)
{
    std::vector<rapidjson::Document> responses{responses_str.size()};
    auto out_iter = responses.begin();
    for (const auto &str : responses_str) {
        out_iter->Parse(str.c_str());
        if (out_iter->HasParseError()) {
            throw std::runtime_error{
                std::string{"Parse error for '"} + str + "' at position " +
                std::to_string(out_iter->GetErrorOffset()) + ": " +
                rapidjson::GetParseError_En(out_iter->GetParseError())};
        }
        out_iter++;
    }
    return responses;
}

class SignallingLock {
public:
    // NOLINTNEXTLINE(cppcoreguidelines-pro-type-vararg, hicpp-vararg)
    explicit SignallingLock(const std::string &str)
        : fd_(open(str.c_str(), O_CREAT | O_CLOEXEC, S_IRUSR | S_IWUSR))
    {

        if (fd_ == -1) {
            error_code const ec = {errno, boost::system::system_category()};
            throw std::runtime_error{
                "Could not open " + str + ": " + ec.message()};
        }
    }
    SignallingLock(SignallingLock &&) = delete;
    SignallingLock(const SignallingLock &) = delete;
    ~SignallingLock() { close(fd_); }

    SignallingLock operator=(const SignallingLock &) = delete;
    SignallingLock operator=(SignallingLock &&) = delete;

    void lock(asio::yield_context yield) const
    {
        for (int tries = 5; tries > 0; tries--) { // NOLINT
            int const res = flock(fd_, LOCK_EX | LOCK_NB);
            if (res == 0) {
                SPDLOG_INFO("Acquired signalling lock");
                return;
            }
            if (errno == EWOULDBLOCK) {
                asio::steady_timer timer{iocontext};
                timer.expires_after(std::chrono::seconds{1});
                timer.async_wait(yield);
            } else {
                error_code const ec = {errno, boost::system::system_category()};
                throw std::runtime_error{"flock() failed: " + ec.message()};
            }
        }
        throw std::runtime_error{"Failed acquiring lock"};
    }

private:
    int fd_;
};

void _fatal_signal_handler(int signum)
{
    ::signal(signum, SIG_DFL); // NOLINT(cert-err33-c)
    // NOLINTNEXTLINE(concurrency-mt-unsafe)
    std::cerr << "Got signal " << ::strsignal(signum) << "\n";
    std::cerr << boost::stacktrace::stacktrace();
    // NOLINTNEXTLINE(cert-err33-c)
    ::raise(SIGABRT);
}

// NOLINTNEXTLINE(bugprone-exception-escape)
int main(int argc, char *argv[])
{
    try {
        auto console = spdlog::stderr_logger_mt("console");
        spdlog::set_default_logger(console);
        spdlog::set_pattern("[%Y-%m-%d %H:%M:%S.%e][%l] %v at %s:%!");
    } catch (const std::exception &ex) {
        std::cerr << "Logger initialization failed: " << ex.what() << "\n";
        return 1;
    }

    auto prev = ::signal(SIGSEGV, _fatal_signal_handler);
    if (prev == SIG_ERR) { // NOLINT(cppcoreguidelines-pro-type-cstyle-cast)
        SPDLOG_CRITICAL("Could not set signal handler for SIGSEGV");
    }
    prev = ::signal(SIGABRT, _fatal_signal_handler);
    if (prev == SIG_ERR) { // NOLINT(cppcoreguidelines-pro-type-cstyle-cast)
        SPDLOG_CRITICAL("Could not set signal handler for SIGABRT");
    }

    po::options_description opt_desc{"Allowed options"};
    // clang-format off
    opt_desc.add_options()
        ("help",       "Show this help")
        ("response",   po::value<std::vector<std::string>>(),
                       "The responses to send")
        ("continuous", po::bool_switch(&continuous_mode)->default_value(false),
                       "Keep answering with the last payload")
        ("no-wait-shutdown",
                       po::bool_switch(&no_wait_shutdown)->default_value(false),
                       "Exit after the last scripted response instead of "
                       "waiting for the extension to close its side")
        ("lock",       po::value<std::string>(), "Location of the lock file");
    // clang-format on

    po::positional_options_description opt_pos;
    opt_pos.add("response", -1);
    po::variables_map opt_vm;
    try {
        auto parsed_options = po::command_line_parser(argc, argv)
                                  .options(opt_desc)
                                  .positional(opt_pos)
                                  .run();
        po::store(parsed_options, opt_vm);
    } catch (const std::exception &ex) {
        std::cerr << ex.what() << "\n";
        return 1;
    }
    po::notify(opt_vm);

    if (opt_vm.count("help") != 0U) {
        std::cerr << opt_desc << "\n";
        return 1;
    }
    if (opt_vm.count("response") == 0U) {
        std::cerr << "At least one response is required\n";
        return 1;
    }
    std::vector<rapidjson::Document> responses;
    try {
        responses =
            parse_responses(opt_vm["response"].as<std::vector<std::string>>());
    } catch (const std::exception &ex) {
        std::cerr << ex.what() << "\n";
        return 1;
    }

    EchoPipe echo_pipe;
    echo_pipe.add_close_cb([]() { iocontext.stop(); });

    spawn(
        iocontext,
        [&](auto yield) {
            // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers)
            for (int fd : {3, 4}) {
                struct ::stat statbuf = {};
                if (fstat(fd, &statbuf) == -1) {
                    continue;
                }
                if ((statbuf.st_mode & S_IFSOCK) == 0) {
                    continue;
                }
                // A connected socketpair endpoint has a peer; a listening
                // socket does not.
                sockaddr_un peer{};
                socklen_t plen = sizeof(peer);
                // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
                if (::getpeername(
                        fd, reinterpret_cast<sockaddr *>(&peer), &plen) == -1) {
                    continue;
                }
                const int new_fd = ::dup(fd); // NOLINT(android-cloexec-dup)
                if (new_fd == -1) {
                    continue;
                }
                SPDLOG_INFO("Using dup of fd {} as communication socket", fd);
                local::stream_protocol::socket client_socket{iocontext};
                client_socket.assign(local::stream_protocol(), new_fd);
                auto client = std::make_unique<Client>(
                    std::move(client_socket), echo_pipe, std::move(responses));
                spawn(
                    iocontext,
                    [client = std::move(client)](auto yield) {
                        client->run_loop(yield);
                        post(iocontext, [] { iocontext.stop(); });
                    },
                    [](const std::exception_ptr &e) {
                        if (e) {
                            std::rethrow_exception(e);
                        }
                    });
                return;
            }
            throw std::runtime_error{"No connected socket found on fd 3 or 4"};
        },
        [](const std::exception_ptr &e) {
            if (e) {
                std::rethrow_exception(e);
            }
        });

    spawn(
        iocontext,
        [&](auto yield) {
            HttpServerDispatcher dispatcher{
                echo_pipe, 18126 /* port */}; // NOLINT
            dispatcher.start();
            dispatcher.run_loop(yield);
        },
        [](const std::exception_ptr &e) {
            if (e) {
                std::rethrow_exception(e);
            }
        });

    std::optional<SignallingLock> signal_lock;
    if (opt_vm.count("lock") != 0U) {
        signal_lock.emplace(opt_vm["lock"].as<std::string>());
        spawn(
            iocontext, [&signal_lock](auto yield) { signal_lock->lock(yield); },
            [](const std::exception_ptr &e) {
                if (e) {
                    std::rethrow_exception(e);
                }
            });
    }

    asio::signal_set signals{iocontext, SIGINT, SIGTERM};
    signals.async_wait([](const error_code & /*err*/, int signal) {
        SPDLOG_INFO("Got signal {}; exiting", signal); // NOLINT
        iocontext.stop();
    });

    try {
        iocontext.run();
    } catch (std::runtime_error &err) {
        SPDLOG_CRITICAL("Fatal error: {}. Exiting", err.what());
        _Exit(1);
    }

    SPDLOG_INFO("Exiting normally");

    // there are some rare crashes where fiber cleanup tries to resume a fiber
    // without a fctx_ anymore... use _Exit() to avoid a segfault in those
    // cases since finding a proper fix is not worth the effort here
    _Exit(0);
}
