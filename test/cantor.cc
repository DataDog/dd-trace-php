#include <catch2/catch.hpp>
#include <ddprof/memhash.hh>

TEST_CASE("cantor tests", "[cantor_hash]") {
    // clang-format off
    uint64_t oracle[6][6] = {
        {  0,   1,   3,   6,  10,  15},
        {  2,   4,   7,  11,  16,  22},
        {  5,   8,  12,  17,  23,  30},
        {  9,  13,  18,  24,  31,  39},
        { 14,  19,  25,  32,  40,  49},
        { 20,  26,  33,  41,  50,  60},
    };
    // clang-format on

    for (uint64_t y = 0; y < 6; ++y) {
        for (uint64_t x = 0; x < 6; ++x) {
            INFO("y,x=" << y << "," << x);
            REQUIRE(oracle[y][x] == ddprof::cantor_hash(x, y));
        }
    }
}
