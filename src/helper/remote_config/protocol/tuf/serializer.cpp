// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include <rapidjson/document.h>
#include <rapidjson/prettywriter.h>

#include "../../../json_helper.hpp"
#include "../cached_target_files.hpp"
#include "base64.h"
#include "exception.hpp"
#include "serializer.hpp"

namespace dds::remote_config::protocol {

void serialize_client_tracer(rapidjson::Document::AllocatorType &alloc,
    rapidjson::Value &client_field, const client_tracer &client_tracer)
{
    rapidjson::Value tracer_object(rapidjson::kObjectType);

    tracer_object.AddMember("language", "php", alloc);
    tracer_object.AddMember("runtime_id", client_tracer.runtime_id, alloc);
    tracer_object.AddMember(
        "tracer_version", client_tracer.tracer_version, alloc);
    tracer_object.AddMember("service", client_tracer.service, alloc);
    tracer_object.AddMember("env", client_tracer.env, alloc);
    tracer_object.AddMember("app_version", client_tracer.app_version, alloc);

    client_field.AddMember("client_tracer", tracer_object, alloc);
}

void serialize_config_states(rapidjson::Document::AllocatorType &alloc,
    rapidjson::Value &client_field,
    const std::vector<config_state> &config_states)
{
    rapidjson::Value config_states_object(rapidjson::kArrayType);

    for (const auto &config_state : config_states) {
        rapidjson::Value config_state_object(rapidjson::kObjectType);
        config_state_object.AddMember("id", config_state.id, alloc);
        config_state_object.AddMember("version", config_state.version, alloc);
        config_state_object.AddMember("product", config_state.product, alloc);
        config_state_object.AddMember(
            "apply_state", static_cast<int>(config_state.apply_state), alloc);
        config_state_object.AddMember(
            "apply_error", config_state.apply_error, alloc);
        config_states_object.PushBack(config_state_object, alloc);
    }

    client_field.AddMember("config_states", config_states_object, alloc);
}

void serialize_client_state(rapidjson::Document::AllocatorType &alloc,
    rapidjson::Value &client_field, const client_state &client_state)
{
    rapidjson::Value client_state_object(rapidjson::kObjectType);

    client_state_object.AddMember(
        "targets_version", client_state.targets_version, alloc);
    client_state_object.AddMember("root_version", 1, alloc);
    client_state_object.AddMember("has_error", client_state.has_error, alloc);
    client_state_object.AddMember("error", client_state.error, alloc);
    client_state_object.AddMember(
        "backend_client_state", client_state.backend_client_state, alloc);

    serialize_config_states(
        alloc, client_state_object, client_state.config_states);

    client_field.AddMember("state", client_state_object, alloc);
}

void serialize_client(rapidjson::Document::AllocatorType &alloc,
    rapidjson::Document &document, const client &client)
{
    rapidjson::Value client_object(rapidjson::kObjectType);

    client_object.AddMember("id", client.id, alloc);
    client_object.AddMember("is_tracer", true, alloc);
    // activation capability;
    char const bytes = static_cast<char>(client.capabilities);
    client_object.AddMember("capabilities",
        base64_encode(std::string_view(&bytes, 1), false), alloc);

    rapidjson::Value products(rapidjson::kArrayType);
    for (const std::string &product_str : client.products) {
        products.PushBack(rapidjson::Value(product_str, alloc).Move(), alloc);
    }
    client_object.AddMember("products", products, alloc);

    serialize_client_tracer(alloc, client_object, client.client_tracer);
    serialize_client_state(alloc, client_object, client.client_state);

    document.AddMember("client", client_object, alloc);
}

void serialize_cached_target_files_hashes(
    rapidjson::Document::AllocatorType &alloc, rapidjson::Value &parent,
    const std::vector<cached_target_files_hash> &cached_target_files_hash_list)
{
    rapidjson::Value cached_target_files_array(rapidjson::kArrayType);

    for (const cached_target_files_hash &ctfh : cached_target_files_hash_list) {
        rapidjson::Value cached_target_file_hash_object(rapidjson::kObjectType);
        cached_target_file_hash_object.AddMember(
            "algorithm", ctfh.algorithm, alloc);
        cached_target_file_hash_object.AddMember("hash", ctfh.hash, alloc);
        cached_target_files_array.PushBack(
            cached_target_file_hash_object, alloc);
    }

    parent.AddMember("hashes", cached_target_files_array, alloc);
}

void serialize_cached_target_files(rapidjson::Document::AllocatorType &alloc,
    rapidjson::Document &document,
    const std::vector<cached_target_files> &cached_target_files_list)
{
    rapidjson::Value cached_target_files_array(rapidjson::kArrayType);

    for (const cached_target_files &ctf : cached_target_files_list) {
        rapidjson::Value cached_target_file_object(rapidjson::kObjectType);
        cached_target_file_object.AddMember("path", ctf.path, alloc);
        cached_target_file_object.AddMember("length", ctf.length, alloc);
        serialize_cached_target_files_hashes(
            alloc, cached_target_file_object, ctf.hashes);
        cached_target_files_array.PushBack(cached_target_file_object, alloc);
    }

    document.AddMember("cached_target_files", cached_target_files_array, alloc);
}

std::string serialize(const get_configs_request &request)
{
    rapidjson::Document document;
    rapidjson::Document::AllocatorType &alloc = document.GetAllocator();

    document.SetObject();

    serialize_client(alloc, document, request.client);
    serialize_cached_target_files(alloc, document, request.cached_target_files);

    dds::string_buffer buffer;
    rapidjson::Writer<decltype(buffer)> writer(buffer);

    // This has to be tested
    if (!document.Accept(writer)) {
        throw serializer_exception();
    }

    return buffer.get_string_ref();
}

} // namespace dds::remote_config::protocol
