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

const std::string &engine_settings::default_rules_file()
{
    struct def_rules_file {
        def_rules_file()
        {
            std::filesystem::path base_path;

            try {
                base_path = get_helper_path().parent_path();
            } catch (const std::exception &e) {
                file = "<error resolving helper library path: " +
                       std::string(e.what()) + ">";

                // try relative to the executable instead
                try {
                    auto psp = std::filesystem::path{"/proc/self/exe"};
                    if (std::filesystem::is_symlink(psp)) {
                        psp = std::filesystem::read_symlink(psp);
                    }
                    base_path = psp.parent_path();
                } catch (const std::exception &e) {
                    file =
                        "<error resolving both library and executable path: " +
                        std::string(e.what()) + ">";
                    return;
                }
            }

            file = base_path / "../etc/recommended.json";
            if (!std::filesystem::exists(file)) {
                // This fallback file is set by a custom/old installer on
                // appsec repository
                file = base_path / "../etc/dd-appsec/recommended.json";
            }
        }
        std::string file;
    };

    static def_rules_file const drf; // NOLINT
    return drf.file;
}
} // namespace dds
