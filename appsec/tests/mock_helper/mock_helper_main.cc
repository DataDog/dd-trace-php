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
#include <unistd.h>
#include <vector>

#include "mock_dd_agent.hpp"
#include "mock_helper_main.hpp"
#include <mpack.h>

namespace po = boost::program_options;
namespace asio = boost::asio;
namespace local = asio::local;
namespace posix = asio::posix;
using boost::system::error_code;

// NOLINTNEXTLINE(cert-err58-cpp,fuchsia-statically-constructed-objects)
asio::io_context iocontext;
bool continuous_mode;

MsgpackToJson::MsgpackToJson(const char *buffer, size_t size)
{
    mpack_tree_init_data(&tree_, buffer, size);
}
void MsgpackToJson::convert()
{
    mpack_tree_parse(&tree_);
    const mpack_error_t err = mpack_tree_error(&tree_);
    if (err != mpack_ok) {
        throw std::runtime_error{std::string{"Error parsing msgpack: "} +
                                 mpack_error_to_string(err)};
    }

    mpack_node_t root = mpack_tree_root(&tree_);
    write(root);
}

// NOLINTNEXTLINE(misc-no-recursion)
void MsgpackToJson::write(mpack_node_t &node)
{
    const mpack_type_t type = mpack_node_type(node);
    switch (type) {
    case mpack_type_nil:
        writer_.Null();
        break;
    case mpack_type_bool:
        writer_.Bool(mpack_node_bool(node));
        break;
    case mpack_type_int:
        writer_.Int64(mpack_node_i64(node));
        break;
    case mpack_type_uint:
        writer_.Uint64(mpack_node_u64(node));
        break;
    case mpack_type_float:
        writer_.Double(static_cast<double>(mpack_node_float_strict(node)));
        break;
    case mpack_type_double:
        writer_.Double(mpack_node_double_strict(node));
        break;
    case mpack_type_str:
        writer_.String(mpack_node_str(node), mpack_node_strlen(node));
        break;
    case mpack_type_array: {
        writer_.StartArray();
        auto len = mpack_node_array_length(node);
        for (decltype(len) i = 0; i < len; i++) {
            mpack_node_t child = mpack_node_array_at(node, i);
            write(child);
        }
        writer_.EndArray();
        break;
    }
    case mpack_type_map: {
        writer_.StartObject();
        auto len = mpack_node_map_count(node);
        for (decltype(len) i = 0; i < len; i++) {
            mpack_node_t const key = mpack_node_map_key_at(node, i);
            mpack_node_t value = mpack_node_map_value_at(node, i);

            mpack_type_t const key_type = mpack_node_type(key);
            if (key_type != mpack_type_str) {
                throw std::runtime_error{
                    "saw nonstring map key in msgpack message"};
            }
            writer_.Key(mpack_node_str(key), mpack_node_strlen(key));
            write(value);
        }
        writer_.EndObject();
        break;
    }
    case mpack_type_missing:
    case mpack_type_bin:
        throw std::runtime_error{
            std::string{"saw unsupported msgpack object: "} +
            mpack_type_to_string(type)};
    }
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

struct OwningBuffer {
    const char *buf_;
    std::size_t len_;
    OwningBuffer() : buf_{nullptr}, len_{0UL} {}
    OwningBuffer(const char *buf, std::size_t len) : buf_{buf}, len_{len} {}
    OwningBuffer(const OwningBuffer &) = delete;
    const OwningBuffer &operator=(const OwningBuffer &) = delete;
    OwningBuffer(OwningBuffer &&oth) noexcept : OwningBuffer{}
    {
        *this = std::move(oth);
    }
    OwningBuffer &operator=(OwningBuffer &&oth) noexcept
    {
        std::free(const_cast<char *>(buf_)); // NOLINT
        buf_ = oth.buf_;
        len_ = oth.len_;
        oth.buf_ = nullptr;
        oth.len_ = 0UL;
        return *this;
    }
    ~OwningBuffer() noexcept { std::free(const_cast<char *>(buf_)); } // NOLINT
};

class MpackWriter {
public:
    MpackWriter() noexcept
    {
        mpack_writer_init_growable(&writer_, &data_, &size_);
    }

    MpackWriter(MpackWriter &&) = delete;
    MpackWriter &operator=(MpackWriter &&) = delete;
    MpackWriter(const MpackWriter &) = delete;
    MpackWriter &operator=(const MpackWriter &) = delete;
    ~MpackWriter()
    {
        mpack_writer_destroy(&writer_);
        std::free(data_); // NOLINT
    }

    // NOLINTNEXTLINE(misc-no-recursion)
    MpackWriter &operator<<(const rapidjson::Value &val)
    {
        if (val.IsInt() || val.IsInt64()) {
            mpack_write_int(&writer_, val.GetInt());
        } else if (val.IsDouble()) {
            mpack_write_double(&writer_, val.GetDouble());
        } else if (val.IsFloat()) {
            mpack_write_double(&writer_, val.GetFloat());
        } else if (val.IsString()) {
            mpack_write_str(&writer_, val.GetString(), val.GetStringLength());
        } else if (val.IsNull()) {
            mpack_write_nil(&writer_);
        } else if (val.IsBool()) {
            if (val.GetBool()) {
                mpack_write_true(&writer_);
            } else {
                mpack_write_false(&writer_);
            }
        } else if (val.IsArray()) {
            auto arr = val.GetArray();
            mpack_start_array(&writer_, arr.Size());
            for (const auto &val : arr) { *this << val; }
            mpack_finish_array(&writer_);
        } else if (val.IsObject()) {
            auto obj = val.GetObject();
            mpack_start_map(&writer_, obj.MemberCount());
            for (auto it = val.MemberBegin(); it != val.MemberEnd(); it++) {
                const auto &name = it->name;
                mpack_write_str(
                    &writer_, name.GetString(), name.GetStringLength());
                *this << it->value;
            }
            mpack_finish_map(&writer_);
        } else {
            throw std::runtime_error{"Unexpected json type"};
        }
        return *this;
    }

    OwningBuffer move_data()
    {
        mpack_writer_destroy(&writer_);
        OwningBuffer ret{data_, size_};
        data_ = nullptr;
        size_ = 0UL;
        return ret;
    }

private:
    char *data_{};
    std::size_t size_{};
    mpack_writer_t writer_{};
};

class JsonToMsgpack {
public:
    explicit JsonToMsgpack(rapidjson::Document &doc) : doc_{doc} {}

    void convert()
    {
        if (!doc_.IsObject() && !doc_.IsArray()) {
            throw std::runtime_error{
                "JSON document is not an object or array, got " +
                std::to_string(doc_.GetType())};
        }

        MpackWriter writer{};
        if (doc_.IsObject()) {
            if (doc_.HasMember("delay")) {
                auto &delay = doc_["delay"];
                if (!delay.IsInt()) {
                    throw std::runtime_error{"Delay is not an integer"};
                }
                delay_ = static_cast<decltype(delay_)>(delay.GetInt());
            }
            if (!doc_.HasMember("msg")) {
                throw std::runtime_error("JSON document has no 'msg' member");
            }
            auto &msg = doc_["msg"];
            if (msg.IsArray()) {
                throw std::runtime_error("Value of 'msg' member is no array");
            }
            writer << msg;
        } else { // array; no transformation
            writer << doc_;
        }

        out_buf_ = writer.move_data();
    }

    [[nodiscard]] std::uint16_t delay() const noexcept { return delay_; }
    OwningBuffer move_buffer() { return std::move(out_buf_); }

private:
    std::uint16_t delay_ = 0;
    OwningBuffer out_buf_;
    rapidjson::Document &doc_; // NOLINT
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
            exited = run_loop_body(yield);
            next_response_++;
        }
        if (continuous_mode) {
            next_response_--;
            while (!exited) {
                SPDLOG_INFO("Will read message #{} (continuous mode)", ++count);
                exited = run_loop_body(yield);
            }
        }
        SPDLOG_INFO("All responses given; exiting");
    }

private:
    bool run_loop_body(const asio::yield_context &yield)
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

        MsgpackToJson msgpack2json{data.data(), data.size()};
        msgpack2json.convert();

        echo_pipe_.write(msgpack2json.asio_buffer(), yield);
        handle_client_data(*next_response_, yield);
        return false;
    }

    void handle_client_data(rapidjson::Document &doc, asio::yield_context yield)
    {
        JsonToMsgpack json_to_msgpack{doc};
        json_to_msgpack.convert();
        if (json_to_msgpack.delay() != 0U) {
            SPDLOG_INFO("Will wait {} seconds before sending response",
                json_to_msgpack.delay());
            asio::steady_timer timer{iocontext};
            timer.expires_after(std::chrono::seconds{json_to_msgpack.delay()});
            timer.async_wait(yield);
        }
        OwningBuffer const buf = json_to_msgpack.move_buffer();

        Header h{};
        memcpy(&h.marker, "dds", 4);
        h.size = buf.len_;
        SPDLOG_INFO("Writing response; size {} (header) + {} (body)", sizeof(h),
            buf.len_);

        std::array const buffers = {
            // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
            asio::const_buffer(reinterpret_cast<char *>(&h), sizeof(h)),
            asio::const_buffer(buf.buf_, buf.len_),
        };
        async_write(sock_, buffers, yield);

        SPDLOG_INFO("Response succcesfully written");
    }

    local::stream_protocol::socket sock_;
    EchoPipe &echo_pipe_; // NOLINT
    std::vector<rapidjson::Document> responses_;
    decltype(responses_.begin()) next_response_;
};

class Dispatcher {
public:
    Dispatcher(EchoPipe &echo_pipe, std::vector<rapidjson::Document> responses)
        : acceptor_{try_fds()}, echo_pipe_{echo_pipe}, responses_{
                                                           std::move(responses)}
    {
        if (!acceptor_.is_open()) {
            throw std::runtime_error{"UNIX socket is not open"};
        }
    }
    Dispatcher(Dispatcher &&) = delete;
    Dispatcher &operator=(Dispatcher &&) = delete;
    Dispatcher(const Dispatcher &) = delete;
    const Dispatcher &operator=(const Dispatcher &) = delete;

    ~Dispatcher()
    {
        if (!iocontext.stopped()) {
            SPDLOG_INFO("Closing listening UNIX socket (dispatcher finished)");
        }
        error_code ec;
        acceptor_.close(ec);
    }

    void accept_one(asio::yield_context yield)
    {
        local::stream_protocol::socket client_socket{iocontext};
        acceptor_.async_accept(client_socket, yield);
        SPDLOG_INFO("Accepted a connection on UNIX socket");
        auto client = std::make_unique<Client>(
            std::move(client_socket), echo_pipe_, std::move(responses_));
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
    }

private:
    static local::stream_protocol::acceptor try_fds()
    {
        auto maybe_sock = try_single_fd(4);
        if (!maybe_sock) {
            maybe_sock = try_single_fd(3);
        }
        if (!maybe_sock) {
            throw std::runtime_error{"No UNIX socket provided"};
        }
        return std::move(*maybe_sock);
    }

    static std::optional<local::stream_protocol::acceptor> try_single_fd(int fd)
    {
        struct ::stat statbuf = {0};
        if (fstat(fd, &statbuf) == -1) {
            error_code const ec = {errno, boost::system::system_category()};
            SPDLOG_INFO("fstat() failed for fd {}: {}", fd, ec.message());
            return std::nullopt;
        }
        if ((statbuf.st_mode & S_IFSOCK) == 0) {
            SPDLOG_INFO("File descriptor {0} is not a socket: {1:o}", fd,
                statbuf.st_mode & S_IFMT);
            return std::nullopt;
        }

        // NOLINTNEXTLINE(cppcoreguidelines-pro-type-member-init,hicpp-member-init)
        sockaddr_un addr;
        socklen_t len = sizeof(addr);
        // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
        if (::getsockname(fd, reinterpret_cast<sockaddr *>(&addr), &len) ==
            -1) {
            error_code const ec = {errno, boost::system::system_category()};
            SPDLOG_INFO("Call to getsockname failed on socket {}: {}", fd,
                ec.message());
            return std::nullopt;
        }
        if (addr.sun_family != AF_UNIX) {
            SPDLOG_INFO("Socket {} is not a unix socket", fd);
            return std::nullopt;
        }

        int const new_fd = ::dup(fd); // NOLINT
        if (new_fd == -1) {
            error_code const ec = {errno, boost::system::system_category()};
            SPDLOG_INFO("Call to dup of fd {} failed: {}", fd, ec.message());
            return std::nullopt;
        }

        SPDLOG_INFO(
            "Using dup of file descriptor {} as unix listening socket", fd);

        local::stream_protocol::acceptor sock{iocontext};
        sock.assign(local::stream_protocol(), new_fd);
        return std::move(sock);
    }

    local::stream_protocol::acceptor acceptor_;
    EchoPipe &echo_pipe_; // NOLINT
    std::vector<rapidjson::Document> responses_;
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
            Dispatcher dispatcher{echo_pipe, std::move(responses)};
            dispatcher.accept_one(yield);
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
