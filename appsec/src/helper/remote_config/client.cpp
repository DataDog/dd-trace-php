// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "client.hpp"
#include "exception.hpp"
#include "product.hpp"
#include <algorithm>
#include <regex>
#include <set>
#include <spdlog/spdlog.h>
#include <stdexcept>
#include <string_view>

extern "C" {
#include <dlfcn.h>
}

namespace {
struct ddog_CharSlice {
    const char *ptr;
    uintptr_t len;
};
ddog_RemoteConfigReader *(*ddog_remote_config_reader_for_path)(
    const char *path);
bool (*ddog_remote_config_read)(
    ddog_RemoteConfigReader *reader, ddog_CharSlice *data);
void (*ddog_remote_config_reader_drop)(struct ddog_RemoteConfigReader *);

// if before is the same as after, disconsidering elements not in products
bool has_updates(
    const std::unordered_set<dds::remote_config::product> &products,
    const std::set<dds::remote_config::config> &before,
    const std::set<dds::remote_config::config> &after)
{
    auto set_is_subset_of = [&products](auto &set1, auto &set2) {
        for (auto &&elem_set_1 : set1) { // NOLINT(readability-use-anyofallof)
            if (!products.contains(elem_set_1.get_product())) {
                continue;
            }
            if (!set2.contains(elem_set_1)) {
                return false;
            }
        }
        return true;
    };

    return !set_is_subset_of(before, after) || !set_is_subset_of(after, before);
}
} // namespace

namespace dds::remote_config {

void resolve_symbols()
{
    ddog_remote_config_reader_for_path =
        // NOLINTNEXTLINE
        reinterpret_cast<decltype(ddog_remote_config_reader_for_path)>(
            dlsym(RTLD_DEFAULT, "ddog_remote_config_reader_for_path"));
    if (ddog_remote_config_reader_for_path == nullptr) {
        throw std::runtime_error{
            "Failed to resolve ddog_remote_config_reader_for_path"};
    }

    ddog_remote_config_read =
        // NOLINTNEXTLINE
        reinterpret_cast<decltype(ddog_remote_config_read)>(
            dlsym(RTLD_DEFAULT, "ddog_remote_config_read"));
    if (ddog_remote_config_read == nullptr) {
        throw std::runtime_error{"Failed to resolve ddog_remote_config_read"};
    }

    ddog_remote_config_reader_drop =
        // NOLINTNEXTLINE
        reinterpret_cast<decltype(ddog_remote_config_reader_drop)>(
            dlsym(RTLD_DEFAULT, "ddog_remote_config_reader_drop"));
    if (ddog_remote_config_reader_drop == nullptr) {
        throw std::runtime_error{
            "Failed to resolve ddog_remote_config_reader_drop"};
    }
}

client::client(remote_config::settings settings,
    std::vector<std::shared_ptr<listener_base>> listeners)
    : reader_{ddog_remote_config_reader_for_path(settings.shmem_path.c_str()),
          ddog_remote_config_reader_drop},
      settings_{std::move(settings)}, listeners_{std::move(listeners)}
{
    assert(settings.enabled == true); // NOLINT

    for (auto const &listener : listeners_) {
        for (const product p : listener->get_supported_products()) {
            std::vector<listener_base *> &vec_listeners =
                listeners_per_product_[p];
            vec_listeners.push_back(listener.get());
            all_products_.insert(p);
        }
    }
}

std::unique_ptr<client> client::from_settings(
    const remote_config::settings &settings,
    std::vector<std::shared_ptr<listener_base>> listeners)
{
    return std::unique_ptr<client>{new client(settings, std::move(listeners))};
}

bool client::poll()
{
    const std::lock_guard lock{mutex_};

    SPDLOG_DEBUG("Polling remote config");

    ddog_CharSlice slice{};
    const bool has_update = ddog_remote_config_read(reader_.get(), &slice);
    if (!has_update) {
        SPDLOG_DEBUG("No update available for {}", settings_.shmem_path);
        return false;
    }

    std::set<config> new_configs;
    // NOLINTNEXTLINE
    std::string_view resp{reinterpret_cast<const char *>(slice.ptr), slice.len};
    auto pos_lf = resp.find('\n');
    if (pos_lf == std::string_view::npos) {
        throw std::runtime_error{
            "Invalid response from remote config (no newline)"};
        return false;
    }
    SPDLOG_DEBUG("Runtime id is {}", resp.substr(0, pos_lf));

    std::string_view configs = resp.substr(pos_lf + 1);
    while (!configs.empty()) {
        auto pos_lf = configs.find('\n');
        if (pos_lf == std::string_view::npos) {
            break;
        }
        new_configs.emplace(config::from_line(configs.substr(0, pos_lf)));
        configs = configs.substr(pos_lf + 1);
    }

    if (!has_updates(all_products_, new_configs, last_configs_)) {
        SPDLOG_DEBUG("Configuration is identical for the subscribed products. "
                     "Skipping update");
        SPDLOG_DEBUG("BEFORE:");
        for (auto &&c : last_configs_) {
            SPDLOG_DEBUG("{}:{}", c.rc_path, c.shm_path);
        }
        SPDLOG_DEBUG("AFTER:");
        for (auto &&c : last_configs_) {
            SPDLOG_DEBUG("{}:{}", c.rc_path, c.shm_path);
        }
        return false;
    }

    return process_response(std::move(new_configs));
}

bool client::process_response(std::set<config> new_configs)
{
    for (auto &listener : listeners_) {
        try {
            listener->init();
        } catch (const std::exception &e) {
            SPDLOG_ERROR("Failed to init listener: {}", e.what());
        }
    }

    // unapply should happen first, because asm_dd aggregator ignores the key...
    for (const auto &cfg : last_configs_) {
        if (new_configs.contains(cfg)) {
            continue;
        }

        const product p = cfg.get_product();
        auto it = listeners_per_product_.find(p);
        if (it == listeners_per_product_.end()) {
            continue;
        }

        SPDLOG_DEBUG("Unapplying config {}", cfg);
        for (listener_base *listener : it->second) {
            try {
                listener->on_unapply(cfg);
            } catch (const std::exception &e) {
                SPDLOG_ERROR("Failed to unapply config {}: {}", cfg, e.what());
            }
        }
    }

    for (const auto &cfg : new_configs) {
        const product p = cfg.get_product();
        if (p == known_products::UNKNOWN) {
            SPDLOG_INFO("Ignoring config with key {}; unsupported product",
                cfg.rc_path);
            continue;
        }
        auto it = listeners_per_product_.find(p);
        if (it == listeners_per_product_.end()) {
            SPDLOG_INFO(
                "No listeners for product {}; skipping key {}", p, cfg.rc_path);
            continue;
        }

        SPDLOG_DEBUG("Applying config {}", cfg);
        for (listener_base *listener : it->second) {
            try {
                listener->on_update(cfg);
            } catch (const std::exception &e) {
                SPDLOG_ERROR("Failed to apply config {}: {}", cfg, e.what());
            }
        }
    }

    for (auto &listener : listeners_) {
        try {
            listener->commit();
        } catch (const std::exception &e) {
            SPDLOG_ERROR("Failed to commit listener: {}", e.what());
        }
    }

    last_configs_ = std::move(new_configs);

    return true;
}

} // namespace dds::remote_config
