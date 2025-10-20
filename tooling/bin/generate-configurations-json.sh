#!/bin/bash

set -euo pipefail

cd $(dirname "$0")

PHP_CODE=$(cat <<'CODE'
$configs = $aliases = [];
foreach (explode("|NEXT_CONFIG|", file_get_contents("php://stdin")) as $configLine) {
    $config = str_getcsv(trim($configLine), ",", '"', '\\');
    if (count($config) > 1) {
        [$type, $name, $default] = $config;
        $configs[$name] = ["A"];
        if (count($config) > 3) {
            $aliases[$name] = array_slice($config, 3);
        }
    }
}
preg_match_all("(OTEL_[A-Z_]+)", file_get_contents("../../ext/otel_config.c"), $m);
foreach ($m[0] as $v) {
    $configs[$v] = ["A"];
}
print json_encode([
    "supportedConfigurations" => $configs,
    "aliases" => $aliases,
], JSON_PRETTY_PRINT);
CODE)

cat <<EOT >../../ext/version.h
#ifndef PHP_DDTRACE_VERSION
#define PHP_DDTRACE_VERSION "$(cat "../../VERSION")"
#endif
EOT

gcc $(php-config --includes) -I../../ext -I../.. -I../../zend_abstract_interface -I../../src/dogstatsd -x c -E - <<'CODE' | grep -A9999 -m1 -F 'JSON_CONFIGURATION_MARKER' | tail -n+2 | php -r "$PHP_CODE"
#include "../../ext/configuration.h"

// Do not expand CALIASES() directly, otherwise parameter counting in macros is broken
#define ALTCALIASES(...) ,##__VA_ARGS__
#define EXPAND(x) x
#define CUSTOM(id) id
#define CONFIG(type, name, default_value, ...) CALIAS(type, name, default_value,)
#define CALIAS(type, name, default_value, aliases, ...) #type, #name, default_value EXPAND(ALT##aliases) |NEXT_CONFIG|

JSON_CONFIGURATION_MARKER
DD_CONFIGURATION

CODE
