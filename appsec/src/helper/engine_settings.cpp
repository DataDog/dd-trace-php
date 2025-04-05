// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "engine_settings.hpp"
#include <exception>

#ifdef __has_include
#    if __has_include(<version>)
#        include <version>
#    endif
#endif

#ifdef __cpp_lib_filesystem
#    include <filesystem>
#else
#    include <experimental/filesystem>
// NOLINTNEXTLINE(cert-dcl58-cpp)
namespace std {
namespace filesystem = experimental::filesystem;
} // namespace std
#endif

#include <fstream>

namespace {
std::filesystem::path get_helper_path()
{
    static constexpr std::string_view lib_name{"/libddappsec-helper.so"};

    const std::filesystem::path maps_path{"/proc/self/maps"};
    std::ifstream maps_stream{maps_path};
    std::string line;
    while (std::getline(maps_stream, line)) {
        if (line.find(lib_name) == std::string::npos) {
            continue;
        }

        auto pos = line.find_first_of('/');
        assert(pos != std::string::npos); // NOLINT
        maps_stream.close();
        return std::filesystem::path{line.substr(pos)};
    }

    maps_stream.close();
    throw std::runtime_error{"libappsec-helper.so not found in maps"};
}
} // namespace

namespace dds {

namespace {
struct def_rules_file {
    std::atomic<std::string *> stored_file{};

    def_rules_file() = default;
    def_rules_file(const def_rules_file &) = delete;
    def_rules_file(def_rules_file &&) = delete;
    def_rules_file &operator=(const def_rules_file &) = delete;
    def_rules_file &operator=(def_rules_file &&) = delete;

    const std::string &get()
    {
        auto *cur_val = stored_file.load();
        if (cur_val == nullptr) {
            auto *new_val = initialize().release();
            if (!stored_file.compare_exchange_strong(cur_val, new_val)) {
                delete new_val; // NOLINT
                // cur_val is now the currently stored value
            } else {
                cur_val = new_val;
            }
        }
        return *cur_val;
    }

    static std::unique_ptr<std::string> initialize()
    {
        std::filesystem::path base_path;
        std::unique_ptr<std::string> file;

        try {
            base_path = get_helper_path().parent_path();
        } catch (const std::exception &e) {
            file = std::make_unique<std::string>(
                "<error resolving helper library path: " +
                std::string(e.what()) + ">");

            // try relative to the executable instead
            try {
                auto psp = std::filesystem::path{"/proc/self/exe"};
                if (std::filesystem::is_symlink(psp)) {
                    psp = std::filesystem::read_symlink(psp);
                }
                base_path = psp.parent_path();
            } catch (const std::exception &e) {
                file = std::make_unique<std::string>(
                    "<error resolving both library and executable path: " +
                    std::string(e.what()) + ">");
                return file;
            }
        }

        file = std::make_unique<std::string>(
            base_path / "../etc/recommended.json");
        if (!std::filesystem::exists(*file)) {
            // This fallback file is set by a custom/old installer on
            // appsec repository
            file = std::make_unique<std::string>(
                base_path / "../etc/dd-appsec/recommended.json");
        }

        return file;
    }

    ~def_rules_file()
    {
        auto *val = stored_file.load();
        delete val; // NOLINT
        stored_file.store(nullptr);
    }
};

// can't be a local static inside default_rules_file() because those
// register their atexit() handlers lazily, which is too late for us.
def_rules_file drf{}; // NOLINT
} // namespace

const std::string &engine_settings::default_rules_file() { return drf.get(); }
} // namespace dds
