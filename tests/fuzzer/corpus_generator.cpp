// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#ifdef __has_include
#  if __has_include(<version>)
#    include <version>
#  endif
#endif

#ifdef __cpp_lib_filesystem
#include <filesystem>
namespace fs = std::filesystem;
#else
#include <experimental/filesystem>
namespace fs = std:: experimental::filesystem;
#endif

#include <fstream>
#include <sstream>
#include <random>
#include <msgpack.hpp>
#include <network/proto.hpp>
#include <boost/uuid/uuid.hpp>
#include <boost/uuid/uuid_generators.hpp>
#include <boost/uuid/uuid_io.hpp>

std::mt19937 rng;

namespace {
std::string filename(const std::string &prefix)
{
    std::stringstream ss;
    ss << prefix;
    if (prefix.back() != '/') {
        ss << '/';
    }

    for (int i = 0; i < 20; i++) {
        unsigned value = rng() % std::numeric_limits<uint8_t>::max();
        ss << std::setw(2) << std::setfill('0') << std::hex << value;
    }
    return ss.str();
}

void pack_str(msgpack::packer<std::stringstream> &p, const std::string &str) {
    p.pack_str(str.size());
    p.pack_str_body(str.c_str(), str.size());
}

void pack_str(msgpack::packer<std::stringstream> &p, std::string_view str) {
    p.pack_str(str.size());
    p.pack_str_body(str.data(), str.size());
}

std::string generate_string() {
    std::string values = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

    std::string output(rng() % 256, 0);
    std::generate(output.begin(), output.end(),
        [&values]() { return values[rng() % values.size()]; });

    return output;
}

// TODO generate complex data types

void generate_request(msgpack::packer<std::stringstream> &packer) {
    constexpr std::array<std::string_view, 10> addrs = {{
        "server.request.query",
        "server.request.method",
        "server.request.cookies",
        "server.request.uri.raw",
        "server.request.headers.no_cookies",
        "server.request.body",
        "server.request.body.raw",
        "server.request.body.filenames",
        "server.request.body.files_field_names",
        "server.request.path_params"
    }};

    size_t num_items = rng() % 64;
    packer.pack_map(num_items);
    while (num_items--) {
        pack_str(packer, addrs[rng() % addrs.size()]);
        pack_str(packer, generate_string());
    }
}

void generate_response(msgpack::packer<std::stringstream> &packer) {
    constexpr std::array<std::string_view, 6> addrs = {{
        "server.response.body.raw",
        "server.response.end",
        "server.response.header",
        "server.response.headers.no_cookies",
        "server.response.status",
        "server.response.write"
    }};
    
    size_t num_items = rng() % 64;
    packer.pack_map(num_items);
    while (num_items--) {
        pack_str(packer, addrs[rng() % addrs.size()]);
        pack_str(packer, generate_string());
    }
}

}

int main(int argc, char *argv[])
{
    if (argc < 2) {
        std::cerr << "Usage: " << argv[0] << " <path> <samples> [<seed value>]\n";
        exit(EXIT_FAILURE);
    }

    if (!fs::is_directory(argv[1])) {
        std::cerr << argv[1] << " is not a directory\n";
        exit(EXIT_FAILURE);
    }

    size_t samples = 400;
    if (argc >= 3) {
        samples = std::stoi(argv[2]);
    }

    unsigned seed = std::random_device()();
    if (argc >= 4) {
        seed = std::stoi(argv[3]);
    }

    rng = std::mt19937(seed);

    std::cout << "Generating "<< samples << " with seed " << seed << std::endl;
    while (samples--) {
        auto corpus = filename(argv[1]);

        std::cout << "Writing file " << corpus << std::endl;

        std::ofstream os(corpus, std::ofstream::out);

        // Client Init
        {
            std::stringstream ss;
            dds::network::client_init::request msg;
            msg.pid = 1923;
            msg.client_version = "1.2.3";
            msg.runtime_version = "7.4.2";
            msg.settings.rules_file = ".github/workflows/release/recommended.json";
            msg.settings.waf_timeout_ms = 10;

            msgpack::packer<std::stringstream> packer(ss);

            packer.pack_array(2);
            std::string name = dds::network::client_init::request::name;
            packer.pack_str(name.size());
            packer.pack_str_body(name.c_str(), name.size());
            packer.pack(msg);

            dds::network::header_t h{"dds"};
            const std::string &str = ss.str();
            h.size = str.size();

            os.write(reinterpret_cast<char *>(&h), sizeof(h));
            os.write(str.c_str(), str.size());
        }

        // Request Init
        {
            std::stringstream ss;
            msgpack::packer<std::stringstream> packer(ss);

            packer.pack_array(2);
            std::string name = dds::network::request_init::request::name;
            pack_str(packer, name);
            packer.pack_array(1);

            generate_request(packer);

            dds::network::header_t h{"dds"};
            const std::string &str = ss.str();
            h.size = str.size();

            os.write(reinterpret_cast<char *>(&h), sizeof(h));
            os.write(str.c_str(), str.size());
        }

        // Request Shutdown
        {
            std::stringstream ss;
            msgpack::packer<std::stringstream> packer(ss);

            packer.pack_array(2);
            std::string name = dds::network::request_shutdown::request::name;
            pack_str(packer, name);
            packer.pack_array(1);

            generate_response(packer);

            dds::network::header_t h{"dds"};
            const std::string &str = ss.str();
            h.size = str.size();

            os.write(reinterpret_cast<char *>(&h), sizeof(h));
            os.write(str.c_str(), str.size());
        }

        os.close();
    }
    return 0;
}
