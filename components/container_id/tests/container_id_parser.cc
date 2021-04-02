extern "C" {
#include "container_id/container_id.h"
}

#include <string.h>

#include <catch2/catch.hpp>

#define MAX_ID_LEN DATADOG_PHP_CONTAINER_ID_MAX_LEN

typedef datadog_php_container_id_parser dd_parser;

TEST_CASE("parser: cgroup valid lines", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    const char *line = "4:perf_event:/";
    REQUIRE(parser.is_valid_line(&parser, line));

    // TODO Add more variations

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}

TEST_CASE("parser: cgroup invalid lines", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    const char *line = "foo line";
    REQUIRE(false == parser.is_valid_line(&parser, line));

    // TODO Add more variations

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}

TEST_CASE("parser: parse a Docker container ID", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    char buf[MAX_ID_LEN + 1];

    const char *line = "12:pids:/docker/9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f";
    REQUIRE(parser.extract_container_id(&parser, buf, line));
    REQUIRE(strcmp("9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f", buf) == 0);

    // TODO Add more variations

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}

// TODO Add more tests
