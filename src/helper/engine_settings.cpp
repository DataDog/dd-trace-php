// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "engine_settings.hpp"

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
namespace dds {

const std::string &engine_settings::default_rules_file()
{
    struct def_rules_file {
        def_rules_file()
        {
            std::error_code ec;
            auto self = std::filesystem::read_symlink({"/proc/self/exe"}, ec);
            if (ec) {
                // should not happen on Linux
                file = "<error resolving /proc/self/exe: " + ec.message() + ">";
            } else {
                auto self_dir = self.parent_path();
                file = self_dir / "../etc/dd-appsec/recommended.json";
            }
        }
        std::string file;
    };

    static def_rules_file drf;
    return drf.file;
}
} // namespace dds
