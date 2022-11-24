// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "client.hpp"
#include "product.hpp"
#include "protocol/tuf/parser.hpp"
#include "protocol/tuf/serializer.hpp"
#include <algorithm>
#include <boost/uuid/uuid.hpp>
#include <boost/uuid/uuid_io.hpp>
#include <map>
#include <regex>

namespace dds::remote_config {

config_path config_path::from_path(const std::string &path)
{
    static const std::regex regex(
        "^(datadog/\\d+|employee)/([^/]+)/([^/]+)/[^/]+$");

    std::smatch base_match;
    if (!std::regex_match(path, base_match, regex) || base_match.size() < 4) {
        throw invalid_path();
    }

    return config_path{base_match[3].str(), base_match[2].str()};
}

client::client(std::unique_ptr<http_api> &&arg_api, service_identifier sid,
    remote_config::settings settings, const std::vector<product> &products,
    std::vector<protocol::capabilities_e> &&capabilities)
    : api_(std::move(arg_api)), id_(dds::generate_random_uuid()),
      sid_(std::move(sid)), settings_(std::move(settings)),
      capabilities_(std::move(capabilities))
{
    for (auto const &product : products) {
        products_.insert(std::pair<std::string, remote_config::product>(
            product.get_name(), product));
    }
}

client::ptr client::from_settings(
    const service_identifier &sid, const remote_config::settings &settings)
{
    if (!settings.enabled) {
        return {};
    }
    return std::make_unique<client>(std::make_unique<http_api>(settings.host,
                                        std::to_string(settings.port)),
        sid, settings);
}

[[nodiscard]] protocol::get_configs_request client::generate_request() const
{
    std::vector<protocol::config_state> config_states;
    std::vector<protocol::cached_target_files> files;

    for (const auto &[product_name, product] : products_) {
        // State
        const auto configs_on_product = product.get_configs();
        for (const auto &[id, config] : configs_on_product) {
            config_states.push_back({config.id, config.version, config.product,
                config.apply_state, config.apply_error});

            std::vector<protocol::cached_target_files_hash> hashes;
            hashes.reserve(config.hashes.size());
            for (auto const &[algo, hash_sting] : config.hashes) {
                hashes.push_back({algo, hash_sting});
            }
            files.push_back({config.path, config.length, std::move(hashes)});
        }
    }

    const protocol::client_tracer ct{sid_.runtime_id, sid_.tracer_version,
        sid_.service, sid_.env, sid_.app_version};

    const protocol::client_state cs{targets_version_, config_states,
        !last_poll_error_.empty(), last_poll_error_, opaque_backend_state_};
    std::vector<std::string> products_str;
    products_str.reserve(products_.size());
    for (const auto &[product_name, product] : products_) {
        products_str.push_back(product_name);
    }
    protocol::client protocol_client = {id_, products_str, ct, cs};
    protocol_client.set_capabilities(capabilities_);

    return {std::move(protocol_client), std::move(files)};
};

bool client::process_response(const protocol::get_configs_response &response)
{
    const std::map<std::string, protocol::path> paths_on_targets =
        response.targets.paths;
    const std::map<std::string, protocol::target_file> target_files =
        response.target_files;
    std::map<std::string, std::map<std::string, config>> configs;
    for (const std::string &path : response.client_configs) {
        try {
            auto cp = config_path::from_path(path);

            // Is path on targets?
            auto path_itr = paths_on_targets.find(path);
            if (path_itr == paths_on_targets.end()) {
                // Not found
                last_poll_error_ = "missing config " + path + " in targets";
                return false;
            }
            auto length = path_itr->second.length;
            std::map<std::string, std::string> hashes = path_itr->second.hashes;
            int custom_v = path_itr->second.custom_v;

            // Is product on the requested ones?
            auto product = products_.find(cp.product);
            if (product == products_.end()) {
                // Not found
                last_poll_error_ = "received config " + path +
                                   " for a product that was not requested";
                return false;
            }

            // Is path on target_files?
            auto path_in_target_files = target_files.find(path);
            std::string raw;
            if (path_in_target_files == target_files.end()) {
                // Check if file in cache
                auto configs_on_product = product->second.get_configs();
                auto config_itr = std::find_if(configs_on_product.begin(),
                    configs_on_product.end(), [&path, &hashes](auto &pair) {
                        return pair.second.path == path &&
                               pair.second.hashes == hashes;
                    });

                if (config_itr == configs_on_product.end()) {
                    // Not found
                    last_poll_error_ = "missing config " + path +
                                       " in target files and in cache files";
                    return false;
                }

                raw = config_itr->second.contents;
                length = config_itr->second.length;
                custom_v = config_itr->second.version;
            } else {
                raw = path_in_target_files->second.raw;
            }

            const std::string path_c = path;
            config config_ = {
                cp.product, cp.id, raw, path_c, hashes, custom_v, length};
            auto configs_itr = configs.find(cp.product);
            if (configs_itr ==
                configs.end()) { // Product not in configs yet. Create entry
                std::map<std::string, config> configs_on_product;
                configs_on_product.emplace(cp.id, config_);
                configs.insert(
                    std::pair<std::string, std::map<std::string, config>>(
                        cp.product, configs_on_product));
            } else { // Product already exists in configs. Add new config
                configs_itr->second.emplace(cp.id, config_);
            }
        } catch (invalid_path &e) {
            last_poll_error_ = "error parsing path " + path;
            return false;
        }
    }

    // Since there have not been errors, we can now update product configs
    for (auto &[name, product] : products_) {
        const auto product_configs = configs.find(name);
        if (product_configs != configs.end()) {
            product.assign_configs(product_configs->second);
        } else {
            product.assign_configs({});
        }
    }

    targets_version_ = response.targets.version;
    opaque_backend_state_ = response.targets.opaque_backend_state;

    return true;
}

bool client::poll()
{
    if (api_ == nullptr) {
        return false;
    }

    auto request = generate_request();

    std::string serialized_request;
    try {
        serialized_request = protocol::serialize(request);
    } catch (protocol::serializer_exception &e) {
        return false;
    }

    auto response_body = api_->get_configs(std::move(serialized_request));
    if (!response_body) {
        return false;
    }

    try {
        auto response = protocol::parse(response_body.value());
        last_poll_error_.clear();
        return process_response(response);
    } catch (protocol::parser_exception &e) {
        return false;
    }
}

} // namespace dds::remote_config