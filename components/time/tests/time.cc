extern "C" {
#include <components/time/time.h>
}

#include <catch2/catch.hpp>

TEST_CASE("cpu-time returns non-zero value", "[time]") {
    datadog_php_cpu_time_result result = datadog_php_cpu_time_now();
    REQUIRE(result.tag == DATADOG_PHP_CPU_TIME_OK);

    struct timespec now = result.ok;
    bool nonzero = now.tv_sec > 0 || now.tv_nsec > 0;
    REQUIRE(nonzero);
}
