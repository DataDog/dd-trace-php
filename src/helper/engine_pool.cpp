// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "engine_pool.hpp"
#include "subscriber/waf.hpp"
#ifdef __cpp_lib_filesystem
#    include <filesystem>
#else
#    include <experimental/filesystem>
// NOLINTNEXTLINE(cert-dcl58-cpp)
namespace std {
namespace filesystem = experimental::filesystem;
} // namespace std
#endif

#include <mutex>
#include <spdlog/spdlog.h>

namespace dds {

std::shared_ptr<engine> engine_pool::load_file(std::string rules_path)
{
    std::lock_guard guard{mutex_};

    auto hit = cache_.find(rules_path);
    if (hit != cache_.end()) {
        std::shared_ptr engine_ptr = hit->second.lock();
        if (engine_ptr) { // not expired
            SPDLOG_DEBUG("Cache hit for rules file {}", rules_path);
            return engine_ptr;
        }
    }

    // no cache hit

    SPDLOG_DEBUG("Will load WAF rules from {}", rules_path);
    // may throw std::exception
    subscriber::ptr waf = waf::instance::from_file(rules_path);

    std::shared_ptr engine_ptr{engine::create()};
    engine_ptr->subscribe(waf);
    cache_.emplace(std::move(rules_path), engine_ptr);
    last_engine_ = engine_ptr;

    cleanup_cache();

    return engine_ptr;
}

void engine_pool::cleanup_cache()
{
    for (auto it = cache_.begin(); it != cache_.end();) {
        if (it->second.expired()) {
            it = cache_.erase(it);
        } else {
            it++;
        }
    }
}

const std::string &engine_pool::default_rules_file()
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
