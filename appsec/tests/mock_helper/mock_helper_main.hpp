// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <optional>
#include <type_traits>
#include <vector>

#include <boost/asio/posix/stream_descriptor.hpp>
#include <boost/asio/spawn.hpp>
#include <rapidjson/writer.h>
#include <spdlog/spdlog.h>

#include <mpack.h>

namespace asio = boost::asio;
namespace posix = asio::posix;
namespace asio = boost::asio;

extern boost::asio::io_context iocontext;

class EchoPipe { // lifetime: till the end of the program
  public:
    EchoPipe();

    void write(asio::const_buffer buff, asio::yield_context yield);

    template <typename Callable> void add_close_cb(Callable &&cb)
    {
        spawn(
            iocontext,
            [this, cb = std::forward<Callable>(cb)](auto yield) {
#ifdef __linux__
                auto wait_type = posix::stream_descriptor::wait_error;
#else
                auto wait_type = posix::stream_descriptor::wait_read;
#endif
                stream_.async_wait(wait_type, yield);
                SPDLOG_INFO("The echo pipe was closed"); // NOLINT

                post(iocontext, [cb = std::move(cb)] { cb(); });
            },
            [](const std::exception_ptr &e) {
                if (e) {
                    std::rethrow_exception(e);
                }
            });
    }

private:
    static posix::stream_descriptor try_fds();

    static std::optional<posix::stream_descriptor> try_single_fd(int fd);

    posix::stream_descriptor stream_;
};

class MsgpackToJson {
  public:
    using writer_t = rapidjson::Writer<rapidjson::StringBuffer>;

    MsgpackToJson(const char *buffer, size_t size);

    template <typename T,
        typename = std::enable_if_t<sizeof(T) == 1 || std::is_void_v<T>, void>>
    MsgpackToJson(const T *buffer, size_t size)
        // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
        : MsgpackToJson{reinterpret_cast<const char *>(buffer), size} {}

    MsgpackToJson(const MsgpackToJson &) = delete;
    MsgpackToJson(MsgpackToJson &&) = delete;
    MsgpackToJson &operator=(const MsgpackToJson &) = delete;
    MsgpackToJson &operator=(MsgpackToJson &&) = delete;

    ~MsgpackToJson() { mpack_tree_destroy(&tree_); }

    void convert();

    writer_t& writer() {
        return writer_;
    }

    asio::const_buffer asio_buffer() {
        const char *str = buffer_.GetString();
        const size_t len = buffer_.GetLength();
        return {str, len};
    }

  private:
    void write(mpack_node_t &node);

    mpack_tree_t tree_{};
    rapidjson::StringBuffer buffer_;
    writer_t writer_{buffer_};
};
