// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "utils.hpp"
#include <fstream>
#include <ios>
#include <rapidjson/error/en.h>
#include <string>
#include <string_view>

namespace dds {
std::string read_file(std::string_view filename)
{
    std::ifstream file(filename.data(), std::ios::in);
    if (!file) {
        throw std::system_error(errno, std::generic_category());
    }

    auto file_size = std::filesystem::file_size(filename.data());
    std::string buffer(file_size, '\0');
    buffer.resize(file_size);

    auto buffer_size = buffer.size();
    if (buffer_size > static_cast<decltype(buffer_size)>(
                          std::numeric_limits<std::streamsize>::max())) {
        throw std::runtime_error{"file is too large"};
    }

    file.read(buffer.data(), static_cast<std::streamsize>(buffer.size()));
    buffer.resize(file.gcount());
    file.close();
    return buffer;
}
} // namespace dds
