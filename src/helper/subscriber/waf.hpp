// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#ifndef WAF_HPP
#define WAF_HPP

#include <ddwaf.h>
#include <spdlog/spdlog.h>
#include <string>
#include <string_view>

#include "../engine.hpp"
#include "../exception.hpp"
#include "../parameter.hpp"
#include "../scope.hpp"

namespace dds::waf {

void initialise_logging(spdlog::level::level_enum level);

class instance : public dds::subscriber {
  public:
    using ptr = std::shared_ptr<instance>;

    class listener : public dds::subscriber::listener {
      public:
        listener() = default;
        listener(const listener&) = delete;
        listener &operator=(const listener&) = delete;
        listener(listener &&) noexcept ;
        explicit listener(ddwaf_context ctx);
        listener &operator=(listener &&) noexcept;
        ~listener() override;

        dds::result call(dds::parameter &data, unsigned timeout) override;

      protected:
        ddwaf_context handle_{};
    };

    // NOLINTNEXTLINE(google-runtime-references)
    explicit instance(dds::parameter &rule);
    instance(const instance &) = delete;
    instance &operator=(const instance&) = delete;
    instance(instance &&) noexcept;
    instance &operator=(instance &&) noexcept ;
    ~instance() override;

    std::vector<std::string_view> get_subscriptions() override;

    listener::ptr get_listener() override;

    static instance::ptr from_file(std::string_view rule_file);
    static instance::ptr from_string(std::string_view rule);

  protected:
    ddwaf_handle handle_{nullptr};
};

parameter parse_file(std::string_view filename);
parameter parse_string(std::string_view config);

} // namespace dds::waf

#endif // WAF_HPP
