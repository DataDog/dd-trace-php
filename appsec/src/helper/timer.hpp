// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

using std::chrono::duration;
using std::chrono::system_clock;

namespace dds {
class timer {
public:
    virtual system_clock::duration time_since_epoch()
    {
        return system_clock::now().time_since_epoch();
    }
};

} // namespace dds
