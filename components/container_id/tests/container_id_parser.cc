extern "C" {
#include "container_id/container_id.h"
}

#include <string.h>

#include <catch2/catch.hpp>

#define MAX_ID_LEN DATADOG_PHP_CONTAINER_ID_MAX_LEN

typedef datadog_php_container_id_parser dd_parser;

TEST_CASE("parser: valid cgroup lines", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    const char *lines[] = {
        "4:perf_event:/",
        "1:name=systemd:/ecs/34dc0b5e626f2c5c4c5170e34b10e765-1234567890",
        "2:memory:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da",
        "7:pids:/user.slice/user-0.slice/session-14.scope",
        "7:pids:/user.slice/user-0.slice/session-14.scope\n",
        "1::/",
        "0::",
        NULL
    };

    for (size_t i = 0; lines[i] != NULL; i++) {
        REQUIRE(parser.is_valid_line(&parser, lines[i]));
    }

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}

TEST_CASE("parser: invalid cgroup lines", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    const char *lines[] = {
        "foo line",
        "a4:perf_event:/",
        ":perf_event:/",
        "1:perf_event",
        "\n",
        "",
        NULL
    };

    for (size_t i = 0; lines[i] != NULL; i++) {
        REQUIRE(false == parser.is_valid_line(&parser, lines[i]));
    }

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}


TEST_CASE("parser: successfully parse a container ID", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    const char *lines[] = {
        "9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f",
        "12:pids:/docker/9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f",
        "1::9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f",
        "1:devices:9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f\n",
        "1:devices:      9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f        ",
        "1:name=systemd:/ecs/9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f/34dc0b5e626f2c5c4c5170e34b10e765-1234567890",  // Contains valid task ID
        "8:net_cls,net_prio:/ecs/55091c13-b8cf-4801-b527-f4601742204d/9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f",
        NULL
    };

    char buf[MAX_ID_LEN + 1] = {0};

    for (size_t i = 0; lines[i] != NULL; i++) {
        memset(buf, 0, sizeof buf);
        REQUIRE(buf[0] == '\0');

        REQUIRE(parser.extract_container_id(&parser, buf, lines[i]));
        REQUIRE(strcmp("9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f", buf) == 0);
    }

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}

TEST_CASE("parser: fail to parse a container ID", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    const char *lines[] = {
        "d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f",  // 63 characters
        "34dc0b5e626f2c5c4c5170e34b10e765-1234567890",  // Task ID
        "",
        "a",
        "zd5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f",
        NULL
    };

    char buf[MAX_ID_LEN + 1] = {0};

    for (size_t i = 0; lines[i] != NULL; i++) {
        memset(buf, 0, sizeof buf);
        REQUIRE(buf[0] == '\0');

        REQUIRE(false == parser.extract_container_id(&parser, buf, lines[i]));
        REQUIRE(buf[0] == '\0');
    }

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}

TEST_CASE("parser: successfully parse a task ID", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    const char *lines[] = {
        "34dc0b5e626f2c5c4c5170e34b10e765-1234567890",
        "2:memory:/ecs/55091c13-b8cf-4801-b527-f4601742204d/34dc0b5e626f2c5c4c5170e34b10e765-1234567890",
        "aaaaaaaaaa34dc0b5e626f2c5c4c5170e34b10e765-1234567890aaaaaaa",
        "1:name=systemd:/ecs/34dc0b5e626f2c5c4c5170e34b10e765-1234567890",
        "1:name=systemd:/ecs/         34dc0b5e626f2c5c4c5170e34b10e765-1234567890",
        "1:name=systemd:/ecs/         34dc0b5e626f2c5c4c5170e34b10e765-1234567890       ",
        "1:name=systemd:/ecs/55091c13-b8cf-4801-b527-f4601742204d/34dc0b5e626f2c5c4c5170e34b10e765-1234567890",
        "1:name=systemd:/ecs/9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f/34dc0b5e626f2c5c4c5170e34b10e765-1234567890",  // Contains valid container ID
        NULL
    };

    char buf[MAX_ID_LEN + 1] = {0};

    for (size_t i = 0; lines[i] != NULL; i++) {
        memset(buf, 0, sizeof buf);
        REQUIRE(buf[0] == '\0');

        REQUIRE(parser.extract_task_id(&parser, buf, lines[i]));
        REQUIRE(strcmp("34dc0b5e626f2c5c4c5170e34b10e765-1234567890", buf) == 0);
    }

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}


TEST_CASE("parser: successfully parse a task ID variations", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    const char *line;
    char buf[MAX_ID_LEN + 1] = {0};

    /* We wrap these in SECTIONs so that buf gets reset every time. */

    SECTION("successful task ID 0") {
        line = "34dc0b5e626f2c5c4c5170e34b10e765-1";

        REQUIRE(parser.extract_task_id(&parser, buf, line));
        REQUIRE(strcmp("34dc0b5e626f2c5c4c5170e34b10e765-1", buf) == 0);
    }

    SECTION("successful task ID 1") {
        line = "34dc0b5e626f2c5c4c5170e34b10e765-12345678901234567890";

        REQUIRE(parser.extract_task_id(&parser, buf, line));
        REQUIRE(strcmp("34dc0b5e626f2c5c4c5170e34b10e765-12345678901234567890", buf) == 0);
    }

    SECTION("successful task ID 2") {
        line = "34dc0b5e626f2c5c4c5170e34b10e765-123456789012345678900000000000000000000000000";

        REQUIRE(parser.extract_task_id(&parser, buf, line));
        REQUIRE(strcmp("34dc0b5e626f2c5c4c5170e34b10e765-12345678901234567890", buf) == 0);
    }

    SECTION("successful task ID 3") {
        line = "34dc0b5e626f2c5c4c5170e34b10e765-123456789012345678900000000000000000000000000";

        REQUIRE(parser.extract_task_id(&parser, buf, line));
        REQUIRE(strcmp("34dc0b5e626f2c5c4c5170e34b10e765-12345678901234567890", buf) == 0);
    }

    SECTION("successful task ID 4") {
        line = "aaaa0b5e626f2c5c4c5170e34b10e765-1234567890bbbb0b5e626f2c5c4c5170e34b10e765-1234567890";

        REQUIRE(parser.extract_task_id(&parser, buf, line));
        REQUIRE(strcmp("aaaa0b5e626f2c5c4c5170e34b10e765-1234567890", buf) == 0);
    }

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}

TEST_CASE("parser: fail to parse a task ID", "[container_id_parser]") {
    dd_parser parser;
    REQUIRE(datadog_php_container_id_parser_ctor(&parser));

    const char *lines[] = {
        "4dc0b5e626f2c5c4c5170e34b10e765-1234567890",  // 31 hex characters
        "9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f",  // Container ID
        "34dc0b5e626f2c5c4c5170e34b10e765-",
        "a-0",
        "",
        "a",
        "\n",
        NULL
    };

    char buf[MAX_ID_LEN + 1] = {0};

    for (size_t i = 0; lines[i] != NULL; i++) {
        memset(buf, 0, sizeof buf);
        REQUIRE(buf[0] == '\0');

        REQUIRE(false == parser.extract_task_id(&parser, buf, lines[i]));
        REQUIRE(buf[0] == '\0');
    }

    REQUIRE(datadog_php_container_id_parser_dtor(&parser));
}
