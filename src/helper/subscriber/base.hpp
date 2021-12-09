// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef SUBSCRIBER_BASE_HPP
#define SUBSCRIBER_BASE_HPP

#include "../parameter.hpp"
#include "../result.hpp"
#include <memory>
#include <vector>

namespace dds {

class subscriber {
public:
    using ptr = std::shared_ptr<subscriber>;

    class listener {
    public:
        using ptr = std::shared_ptr<listener>;

        listener() = default;
        listener(const listener &) = default;
        listener &operator=(const listener &) = delete;
        listener(listener &&) = default;
        listener &operator=(listener &&) = delete;

        virtual ~listener() = default;
        // NOLINTNEXTLINE(google-runtime-references)
        virtual result call(parameter &data) = 0;
    };

    subscriber() = default;
    subscriber(const subscriber &) = delete;
    subscriber &operator=(const subscriber &) = delete;
    subscriber(subscriber &&) = delete;
    subscriber &operator=(subscriber &&) = delete;

    virtual ~subscriber() = default;

    virtual std::vector<std::string_view> get_subscriptions() = 0;
    virtual listener::ptr get_listener() = 0;
};

} // namespace dds
#endif
