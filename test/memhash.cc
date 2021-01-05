#include <catch2/catch.hpp>
#include <ddprof/memhash.hh>

TEST_CASE("memhash tests", "[memhash]") {
    auto empty = ddprof::memhash::hash(0, 0, "");
    auto ddprof = ddprof::memhash::hash(0, sizeof("ddprof") - 1, "ddprof");

    REQUIRE(ddprof != 0);
    REQUIRE(empty != ddprof);
}
