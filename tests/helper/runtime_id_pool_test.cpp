// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include "utils.hpp"
#include <runtime_id_pool.hpp>

namespace dds {

TEST(RuntimeIDPool, InvalidConstruction)
{
    EXPECT_THROW(runtime_id_pool ids{""}, std::invalid_argument);
}

TEST(RuntimeIDPool, ConstructionAndGet)
{
    auto uuid = generate_random_uuid();
    runtime_id_pool ids{uuid};

    EXPECT_STREQ(ids.get().c_str(), uuid.c_str());
}

TEST(RuntimeIDPool, AddAndGet)
{
    auto uuid = generate_random_uuid();
    runtime_id_pool ids{uuid};

    ids.add(generate_random_uuid());

    EXPECT_STREQ(ids.get().c_str(), uuid.c_str());
}

TEST(RuntimeIDPool, RemoveLast)
{
    auto uuid = generate_random_uuid();
    runtime_id_pool ids{uuid};
    ids.remove(uuid);

    EXPECT_STREQ(ids.get().c_str(), uuid.c_str());
}

TEST(RuntimeIDPool, RemoveCurrent)
{
    auto uuid = generate_random_uuid();
    runtime_id_pool ids{uuid};

    auto uuid2 = generate_random_uuid();
    ids.add(uuid2);

    ids.remove(uuid);

    EXPECT_STREQ(ids.get().c_str(), uuid2.c_str());
}

TEST(RuntimeIDPool, RemoveLastAndAdd)
{
    auto uuid = generate_random_uuid();
    runtime_id_pool ids{uuid};
    ids.remove(uuid);

    auto uuid2 = generate_random_uuid();
    ids.add(uuid2);

    EXPECT_STREQ(ids.get().c_str(), uuid2.c_str());
}

TEST(RuntimeIDPool, RemoveAddEmptyAndGet)
{
    auto uuid = generate_random_uuid();
    runtime_id_pool ids{uuid};
    ids.remove(uuid);
    ids.add("");

    EXPECT_STREQ(ids.get().c_str(), uuid.c_str());
}

TEST(RuntimeIDPool, RemoveDuplicateAndGet)
{
    auto uuid = generate_random_uuid();
    auto uuid2 = generate_random_uuid();
    runtime_id_pool ids{uuid};
    ids.add(uuid);
    ids.add(uuid);
    ids.add(uuid2);

    ids.remove(uuid);

    EXPECT_STREQ(ids.get().c_str(), uuid.c_str());
}

TEST(RuntimeIDPool, RemoveAllDuplicatesAndGet)
{
    auto uuid = generate_random_uuid();
    auto uuid2 = generate_random_uuid();
    runtime_id_pool ids{uuid};
    ids.add(uuid);
    ids.add(uuid);
    ids.add(uuid2);

    ids.remove(uuid);
    ids.remove(uuid);
    ids.remove(uuid);

    EXPECT_STREQ(ids.get().c_str(), uuid2.c_str());
}

TEST(RuntimeIDPool, AddManyAndRemove)
{
    auto uuid = generate_random_uuid();
    runtime_id_pool ids{uuid};

    std::array<std::string, 20> uuid_array;

    for (auto i = 0; i < 20; ++i) {
        uuid_array[i] = generate_random_uuid();
        ids.add(uuid_array[i]);
    }

    for (auto i = 0; i < 20; ++i) {
        ids.remove(uuid_array[i]);
        EXPECT_STREQ(ids.get().c_str(), uuid.c_str());
    }

    ids.remove(uuid);
    EXPECT_STREQ(ids.get().c_str(), uuid.c_str());
}

} // namespace dds
