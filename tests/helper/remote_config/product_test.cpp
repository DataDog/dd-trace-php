// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../common.hpp"
#include "remote_config/config.hpp"
#include "remote_config/exception.hpp"
#include "remote_config/listener.hpp"
#include "remote_config/product.hpp"

namespace dds {

namespace mock {

ACTION(ThrowErrorApplyingConfig)
{
    throw remote_config::error_applying_config("some error");
};

class listener_mock : public remote_config::product_listener_base {
public:
    listener_mock() = default;
    ~listener_mock() override = default;

    MOCK_METHOD(
        void, on_update, ((const remote_config::config &config)), (override));
    MOCK_METHOD(
        void, on_unapply, ((const remote_config::config &config)), (override));
    MOCK_METHOD((const remote_config::protocol::capabilities_e),
        get_capabilities, (), (override));
    MOCK_METHOD((const std::string_view), get_name, (), (override));
    MOCK_METHOD(void, init, (), (override));
    MOCK_METHOD(void, commit, (), (override));
};
} // namespace mock

remote_config::config get_config(std::string id)
{
    return {"some product", id, "some contents", "some path", {}, 123, 321,
        remote_config::protocol::config_state::applied_state::UNACKNOWLEDGED,
        ""};
}

remote_config::config get_config() { return get_config("some id"); }

remote_config::config unacknowledged(remote_config::config c)
{
    c.apply_state =
        remote_config::protocol::config_state::applied_state::UNACKNOWLEDGED;
    return c;
}

remote_config::config acknowledged(remote_config::config c)
{
    c.apply_state =
        remote_config::protocol::config_state::applied_state::ACKNOWLEDGED;
    return c;
}

TEST(RemoteConfigProduct, InvalidListener)
{
    EXPECT_THROW(remote_config::product(nullptr), std::runtime_error);
}

TEST(RemoteConfigProduct, NameFromListenerIsSaved)
{
    auto listener = std::make_shared<mock::listener_mock>();
    remote_config::product product(listener);

    EXPECT_EQ("MOCK_PRODUCT", product.get_name());
}

TEST(RemoteConfigProduct, ConfigsAreEmptyByDefault)
{
    auto listener = std::make_shared<mock::listener_mock>();
    remote_config::product product(listener);

    EXPECT_EQ(0, product.get_configs().size());
}

TEST(RemoteConfigProduct, ConfigsAreSaved)
{
    auto listener = std::make_shared<mock::listener_mock>();
    remote_config::product product(listener);

    remote_config::config config = get_config();

    EXPECT_CALL(*listener, init()).Times(1);
    EXPECT_CALL(*listener, on_update(config)).Times(1);
    EXPECT_CALL(*listener, commit()).Times(1);

    product.assign_configs({{"config name", config}});

    auto configs_on_product = product.get_configs();
    auto config_saved = configs_on_product.find("config name");

    EXPECT_EQ(1, configs_on_product.size());
    EXPECT_EQ("config name", config_saved->first);
    EXPECT_EQ(acknowledged(config), config_saved->second);
}

TEST(
    RemoteConfigProduct, WhenAConfigIsSavedTheProductListenerIsCalledToOnUpdate)
{
    auto listener = std::make_shared<mock::listener_mock>();
    remote_config::config config = get_config();
    EXPECT_CALL(*listener, init()).Times(1);
    EXPECT_CALL(*listener, on_update(config)).Times(1);
    EXPECT_CALL(*listener, on_unapply(_)).Times(0);
    EXPECT_CALL(*listener, commit()).Times(1);
    remote_config::product product(listener);

    product.assign_configs({{"config name", config}});

    EXPECT_EQ(
        remote_config::protocol::config_state::applied_state::ACKNOWLEDGED,
        product.get_configs().find("config name")->second.apply_state);
}

TEST(RemoteConfigProduct,
    WhenAConfigIsRemovedTheProductListenerIsCalledToUnApply)
{
    auto listener = std::make_shared<mock::listener_mock>();
    remote_config::config config = get_config();

    EXPECT_CALL(*listener, init()).Times(2);
    EXPECT_CALL(*listener, on_update(unacknowledged(config))).Times(1);
    EXPECT_CALL(*listener, on_unapply(acknowledged(config))).Times(1);
    EXPECT_CALL(*listener, commit()).Times(2);
    remote_config::product product(listener);

    product.assign_configs({{"config name", unacknowledged(config)}});
    product.assign_configs({});

    EXPECT_EQ(0, product.get_configs().size());
}

TEST(RemoteConfigProduct, WhenAConfigDoesNotChangeItsListenerShouldBeCalled)
{
    auto listener = std::make_shared<mock::listener_mock>();
    remote_config::config config = get_config();

    EXPECT_CALL(*listener, init()).Times(2);
    EXPECT_CALL(*listener, on_update(unacknowledged(config))).Times(1);
    EXPECT_CALL(*listener, on_update(acknowledged(config))).Times(1);
    EXPECT_CALL(*listener, commit()).Times(2);

    remote_config::product product(listener);

    product.assign_configs({{"config name", unacknowledged(config)}});
    product.assign_configs({{"config name", acknowledged(config)}});

    EXPECT_EQ(1, product.get_configs().size());
}

TEST(RemoteConfigProduct, WhenAConfigChangeItsHashItsListenerUpdateIsCalled)
{
    auto listener = std::make_shared<mock::listener_mock>();
    remote_config::config config = get_config();
    remote_config::config same_config_different_hash = get_config();
    same_config_different_hash.hashes.emplace("hash key", "hash value");

    EXPECT_CALL(*listener, init()).Times(2);
    EXPECT_CALL(*listener, on_update(unacknowledged(config))).Times(1);
    EXPECT_CALL(
        *listener, on_update(unacknowledged(same_config_different_hash)))
        .Times(1);
    EXPECT_CALL(*listener, on_unapply(_)).Times(0);
    EXPECT_CALL(*listener, commit()).Times(2);
    remote_config::product product(listener);

    product.assign_configs({{"config name", config}});
    product.assign_configs({{"config name", same_config_different_hash}});

    EXPECT_EQ(1, product.get_configs().size());
}

TEST(RemoteConfigProduct, SameConfigWithDifferentNameItsTreatedAsNewConfig)
{
    auto listener = std::make_shared<mock::listener_mock>();
    remote_config::config config = get_config();

    EXPECT_CALL(*listener, init()).Times(2);
    EXPECT_CALL(*listener, on_update(unacknowledged(config))).Times(2);
    EXPECT_CALL(*listener, on_unapply(acknowledged(config))).Times(1);
    EXPECT_CALL(*listener, commit()).Times(2);
    remote_config::product product(listener);

    product.assign_configs({{"config name 01", config}});
    product.assign_configs({{"config name 02", config}});

    EXPECT_EQ(1, product.get_configs().size());
}

TEST(RemoteConfigProduct, WhenAListenerFailsUpdatingAConfigItsStateGetsError)
{
    auto listener = std::make_shared<mock::listener_mock>();
    remote_config::config config = get_config();

    EXPECT_CALL(*listener, init()).Times(1);
    EXPECT_CALL(*listener, on_update(_))
        .WillRepeatedly(mock::ThrowErrorApplyingConfig());
    remote_config::product product(listener);
    EXPECT_CALL(*listener, commit()).Times(1);

    product.assign_configs({{"config name", config}});

    EXPECT_EQ(1, product.get_configs().size());
    EXPECT_EQ(remote_config::protocol::config_state::applied_state::ERROR,
        product.get_configs().find("config name")->second.apply_state);
    EXPECT_EQ("some error",
        product.get_configs().find("config name")->second.apply_error);
}

} // namespace dds
