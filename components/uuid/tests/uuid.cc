extern "C" {
#include <components/uuid/uuid.h>
}

#include <catch2/catch.hpp>
#include <cstring>

TEST_CASE("uuid encode32 nil", "[uuid]") {
    datadog_php_uuid uuid;
    datadog_php_uuid_default_ctor(&uuid);

    alignas(16) char dest[32];
    datadog_php_uuid_encode32(uuid, dest);

    alignas(16) char expect[33] = "00000000000000000000000000000000";
    CHECK(memcmp(expect, dest, 32) == 0);
}

TEST_CASE("uuid encode32", "[uuid]") {
    datadog_php_uuid uuid = {
        {29, 202, 33, 60, 217, 201, 77, 49, 162, 30, 13, 192, 25, 215, 90, 236},
    };

    alignas(32) char dest[32];
    datadog_php_uuid_encode32(uuid, dest);

    alignas(32) char expect[33] = "1dca213cd9c94d31a21e0dc019d75aec";
    CHECK(memcmp(expect, dest, 32) == 0);
}

TEST_CASE("uuid encode36", "[uuid]") {
    datadog_php_uuid uuid = {
        {190, 40, 194, 233, 78, 82, 76, 113, 164, 20, 69, 167, 221, 234, 5, 240},
    };

    alignas(32) char dest[36];
    datadog_php_uuid_encode36(uuid, dest);

    alignas(32) char expect[37] = "be28c2e9-4e52-4c71-a414-45a7ddea05f0";
    CHECK(memcmp(expect, dest, 36) == 0);
}

TEST_CASE("uuidv4 encode36 easy", "[uuid]") {
    // this byte pattern is already a valid UUIDv4, so we won't change it
    alignas(16) uint8_t bytes[16] = {190, 40, 194, 233, 78, 82, 76, 113, 164, 20, 69, 167, 221, 234, 5, 240};
    datadog_php_uuid uuidv4;
    datadog_php_uuidv4_bytes_ctor(&uuidv4, bytes);

    alignas(32) char dest[36];
    datadog_php_uuid_encode36(uuidv4, dest);

    alignas(32) char expect[37] = "be28c2e9-4e52-4c71-a414-45a7ddea05f0";
    CHECK(memcmp(expect, dest, 36) == 0);
}

TEST_CASE("uuidv4 encode36 bytes 6", "[uuid]") {
    datadog_php_uuid uuidv4;

    /* These tests are valid UUIDv4s except for byte 6, which we are testing
     * that it alters correctly.
     */

    /* byte 6 is 10111111; upper bits are flipped, lower all 1s which shouldn't
     * change at all.
     */
    alignas(16) uint8_t bytes[16] = {190, 40, 194, 233, 78, 82, 191, 113, 164, 20, 69, 167, 221, 234, 5, 240};
    datadog_php_uuidv4_bytes_ctor(&uuidv4, bytes);

    alignas(32) char dest[36];
    datadog_php_uuid_encode36(uuidv4, dest);

    alignas(32) char expect1[37] = "be28c2e9-4e52-4f71-a414-45a7ddea05f0";
    CHECK(memcmp(expect1, dest, 36) == 0);

    /* byte 6 is 10110000; upper bits are flipped, lower all 0s which shouldn't
     * change at all.
     */
    bytes[6] = 176;
    datadog_php_uuidv4_bytes_ctor(&uuidv4, bytes);

    datadog_php_uuid_encode36(uuidv4, dest);

    alignas(32) char expect2[37] = "be28c2e9-4e52-4071-a414-45a7ddea05f0";
    CHECK(memcmp(expect2, dest, 36) == 0);
}
