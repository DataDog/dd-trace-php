// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "client.hpp"
#include "../ffi.hpp"
#include "product.hpp"
#include <set>
#include <spdlog/spdlog.h>
#include <stdexcept>
#include <string_view>

extern "C" {
#include <dlfcn.h>
}

SIDECAR_FFI_SYMBOL(ddog_remote_config_reader_for_path);
SIDECAR_FFI_SYMBOL(ddog_remote_config_read);
SIDECAR_FFI_SYMBOL(ddog_remote_config_reader_drop);

namespace {

bool sets_are_indentical_for_subbed_products(
    const std::unordered_set<dds::remote_config::product> &products,
    const std::set<dds::remote_config::config> &before,
    const std::set<dds::remote_config::config> &after)
{
    auto set_is_subset_of = [&products](auto &set1, auto &set2) {
        for (auto &&elem_set_1 : set1) { // NOLINT(readability-use-anyofallof)
            if (!products.contains(elem_set_1.config_key().product())) {
                continue;
            }
            if (!set2.contains(elem_set_1)) {
                return false;
            }
        }
        return true;
    };

    return set_is_subset_of(before, after) && set_is_subset_of(after, before);
}
} // namespace

namespace dds::remote_config {

client::client(remote_config::settings settings,
    std::vector<std::shared_ptr<listener_base>> listeners,
    std::shared_ptr<telemetry::telemetry_submitter> msubmitter)
    : reader_{ffi::ddog_remote_config_reader_for_path(
                  settings.shmem_path.c_str()),
          ffi::ddog_remote_config_reader_drop.get_fn()},
      settings_{std::move(settings)}, listeners_{std::move(listeners)},
      msubmitter_{std::move(msubmitter)}
{
    assert(settings_.enabled == true); // NOLINT

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
    std::vector<std::shared_ptr<listener_base>> listeners,
    std::shared_ptr<telemetry::telemetry_submitter> msubmitter)
{
    return std::unique_ptr<client>{
        new client(settings, std::move(listeners), std::move(msubmitter))};
}

bool client::poll()
{
    const std::lock_guard lock{mutex_};

    SPDLOG_DEBUG("Polling remote config");

    ddog_CharSlice slice{};
    const bool has_update = ffi::ddog_remote_config_read(reader_.get(), &slice);
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

    if (sets_are_indentical_for_subbed_products(
            all_products_, new_configs, last_configs_)) {
        SPDLOG_DEBUG("Configuration is identical for the subscribed products.  "
                     "Skipping update");
        return false;
    }

    return process_response(std::move(new_configs));
}

bool client::process_response(std::set<config> new_configs)
{
    using log_level = telemetry::telemetry_submitter::log_level;

    for (auto &listener : listeners_) {
        try {
            listener->init();
        } catch (const std::exception &e) {
            SPDLOG_ERROR("Failed to init listener: {}", e.what());
        }
    }

    // unapply should happen first
    for (const config &cfg : last_configs_) {
        if (new_configs.contains(cfg)) {
            continue;
        }

        const product p = cfg.config_key().product();
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
                if (msubmitter_) {
                    std::string identifier = fmt::format("rc::{}::exception",
                        cfg.config_key().product().name_lower());
                    std::string tags =
                        telemetry::telemetry_tags{}
                            .add("log_type", identifier)
                            .add("rc_config_id", cfg.config_key().config_id())
                            .consume();
                    msubmitter_->submit_log(
                        telemetry::telemetry_submitter::log_level::Error,
                        std::move(identifier),
                        fmt::format("Failed to unapply config {}: {}",
                            cfg.rc_path, e.what()),
                        std::nullopt, std::move(tags), false);
                }
            }
        }
    }

    for (const auto &cfg : new_configs) {
        const product p = cfg.config_key().product();
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
                if (msubmitter_) {
                    std::string identifier = fmt::format("rc::{}::exception",
                        cfg.config_key().product().name_lower());
                    std::string tags =
                        telemetry::telemetry_tags{}
                            .add("log_type", identifier)
                            .add("rc_config_id", cfg.config_key().config_id())
                            .consume();
                    msubmitter_->submit_log(log_level::Error,
                        std::move(identifier),
                        fmt::format("Failed to apply config {}: {}",
                            cfg.rc_path, e.what()),
                        std::nullopt, std::move(tags), false);
                }
            }
        }
    }

    for (auto &listener : listeners_) {
        try {
            listener->commit();
        } catch (const std::exception &e) {
            SPDLOG_ERROR("Failed to commit listener: {}", e.what());
            if (msubmitter_) {
                msubmitter_->submit_log(log_level::Error,
                    "rc::client::exception",
                    fmt::format("Failed to commit listener: {}", e.what()),
                    std::nullopt, std::nullopt, false);
            }
        }
    }

    last_configs_ = std::move(new_configs);

    return true;
}

} // namespace dds::remote_config
