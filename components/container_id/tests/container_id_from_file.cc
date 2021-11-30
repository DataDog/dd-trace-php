extern "C" {
#include <components/container_id/container_id.h>
}

#include <catch2/catch.hpp>
#include <cstring>

TEST_CASE("parse a Docker container ID", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.docker"));
    REQUIRE(strcmp("9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f", id) == 0);
}

TEST_CASE("parse a Kubernetes container ID", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.kubernetes"));
    REQUIRE(strcmp("3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1", id) == 0);
}

TEST_CASE("parse an ECS container ID", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.ecs"));
    REQUIRE(strcmp("38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce", id) == 0);
}

TEST_CASE("parse a Fargate container ID", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.fargate"));
    REQUIRE(strcmp("432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da", id) == 0);
}

TEST_CASE("parse a Fargate 1.4+ container ID", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.fargate.1.4"));
    REQUIRE(strcmp("34dc0b5e626f2c5c4c5170e34b10e765-1234567890", id) == 0);
}

/* Whitespace around the matching ID is permitted so long as it is matched
 * within a valid cgroup line.
 */
TEST_CASE("parse a container ID with leading and trailing whitespace", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.whitespace"));
    REQUIRE(strcmp("3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860", id) == 0);
}

TEST_CASE("a non-container Linux cgroup file makes an empty string", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.linux"));
    REQUIRE(id[0] == '\0');
}

TEST_CASE("missing cgroup file makes an empty string", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(false == datadog_php_container_id_from_file(id, "/path/to/cgroup.missing"));
    REQUIRE(id[0] == '\0');
}

/* To be consistent with other tracers, unrecognized services that match the
 * generic container ID regex patterns are considered valid.
 */
TEST_CASE("parse unrecognized container ID", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.unrecognized"));
    REQUIRE(strcmp("9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f", id) == 0);
}

TEST_CASE("error edge cases when parsing container ID", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.edge_cases"));
    REQUIRE(id[0] == '\0');
}

TEST_CASE("a NULL cgroup file makes an empty string", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(false == datadog_php_container_id_from_file(id, nullptr));
    REQUIRE(id[0] == '\0');
}

TEST_CASE("a NULL buf does not crash", "[container_id]") {
    REQUIRE(false == datadog_php_container_id_from_file(nullptr, "./stubs/cgroup.docker"));
    REQUIRE(true);
}

TEST_CASE("an empty cgroup file makes an empty string", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(false == datadog_php_container_id_from_file(id, ""));
    REQUIRE(id[0] == '\0');
}

TEST_CASE("the buffer defaults to an empty string", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    id[0] = 'a';
    REQUIRE(false == datadog_php_container_id_from_file(id, ""));
    REQUIRE(id[0] == '\0');
}

TEST_CASE("valid container ID with invalid line pattern makes an empty string", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.invalid_line_container_id"));
    REQUIRE(id[0] == '\0');
}

TEST_CASE("valid task ID with invalid line pattern makes an empty string", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.invalid_line_task_id"));
    REQUIRE(id[0] == '\0');
}

/* To be consistent with other tracers we only match lower case hex. */
TEST_CASE("uppercase container IDs return an empty string", "[container_id]") {
    char id[DATADOG_PHP_CONTAINER_ID_MAX_LEN + 1];
    REQUIRE(datadog_php_container_id_from_file(id, "./stubs/cgroup.upper"));
    REQUIRE(id[0] == '\0');
}
