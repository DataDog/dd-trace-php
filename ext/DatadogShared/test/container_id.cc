extern "C" {
#include <datadog/container_id.h>
#include <datadog/string.h>
}

#include <catch2/catch.hpp>
#include <string.h>

TEST_CASE("parse a Docker container ID", "[container_id]") {
    datadog_string *id = datadog_container_id("./stubs/cgroup.docker");
    REQUIRE(id != NULL);
    REQUIRE(strcmp("9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f", id->val) == 0);
    REQUIRE(id->len == 64);
    datadog_string_free(id);
}

TEST_CASE("parse a Kubernetes container ID", "[container_id]") {
    datadog_string *id = datadog_container_id("./stubs/cgroup.kubernetes");
    REQUIRE(id != NULL);
    REQUIRE(strcmp("3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1", id->val) == 0);
    REQUIRE(id->len == 64);
    datadog_string_free(id);
}

TEST_CASE("parse an ECS container ID", "[container_id]") {
    datadog_string *id = datadog_container_id("./stubs/cgroup.ecs");
    REQUIRE(id != NULL);
    REQUIRE(strcmp("38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce", id->val) == 0);
    REQUIRE(id->len == 64);
    datadog_string_free(id);
}

TEST_CASE("parse a Fargate container ID", "[container_id]") {
    datadog_string *id = datadog_container_id("./stubs/cgroup.fargate");
    REQUIRE(id != NULL);
    REQUIRE(strcmp("432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da", id->val) == 0);
    REQUIRE(id->len == 64);
    datadog_string_free(id);
}

TEST_CASE("parse a container ID with leading and trailing whitespace", "[container_id]") {
    datadog_string *id = datadog_container_id("./stubs/cgroup.whitespace");
    REQUIRE(id != NULL);
    REQUIRE(strcmp("3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860", id->val) == 0);
    REQUIRE(id->len == 64);
    datadog_string_free(id);
}

TEST_CASE("a non-container Linux cgroup file returns NULL", "[container_id]") {
    datadog_string *id = datadog_container_id("./stubs/cgroup.linux");
    REQUIRE(id == NULL);
}

TEST_CASE("missing cgroup file returns NULL", "[container_id]") {
    datadog_string *id = datadog_container_id("/path/to/cgroup.missing");
    REQUIRE(id == NULL);
}

TEST_CASE("unrecognized container ID returns NULL", "[container_id]") {
    datadog_string *id = datadog_container_id("./stubs/cgroup.unrecognized");
    REQUIRE(id == NULL);
}

TEST_CASE("error edge cases when parsing container ID", "[container_id]") {
    datadog_string *id = datadog_container_id("./stubs/cgroup.edge_cases");
    REQUIRE(id == NULL);
}
