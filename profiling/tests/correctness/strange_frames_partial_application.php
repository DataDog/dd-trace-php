<?php

/* Verify that PHP 8.6 partial function application (PFA) closure frames appear
 * properly for both free functions and static methods in wall-time and sample
 * profiles.
 */

namespace Datadog\Test {
    function target(string $first, string $second): string {
        usleep(150_000);
        return "$first $second";
    }

    final class Helper {
        public static function format(string $prefix, string $value): string {
            usleep(150_000);
            return "$prefix:$value";
        }
    }
}

namespace {
    function main() {
        $fn = \Datadog\Test\target('hello', ?);
        $fn('world');

        $method = \Datadog\Test\Helper::format('tag', ?);
        $method('value');
    }

    main();
}
