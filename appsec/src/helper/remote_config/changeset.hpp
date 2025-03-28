#pragma once

#include "../parameter.hpp"
#include "config.hpp"
#include <unordered_map>
#include <unordered_set>
namespace dds::remote_config {

struct changeset {
    std::unordered_map<parsed_config_key, parameter> added;
    std::unordered_set<parsed_config_key> removed;
};
} // namespace dds::remote_config
