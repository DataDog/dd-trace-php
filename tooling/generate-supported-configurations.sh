#!/bin/bash
# Generates metadata/supported-configurations.json from ext/configuration.h
#
set -euo pipefail

cd "$(dirname "$0")"

# Maps C config type to JSON schema type.
PHP_CODE=$(cat <<'ENDPHP'
function map_type($raw) {
    $raw = trim($raw);
    if (preg_match('/^CUSTOM\((.+)\)$/', $raw, $m)) {
        $raw = trim($m[1]);
    }
    $map = [
        'BOOL' => 'boolean', 'STRING' => 'string', 'INT' => 'int', 'DOUBLE' => 'decimal',
        'MAP' => 'map', 'JSON' => 'array', 'SET_OR_MAP_LOWERCASE' => 'map',
        'SET' => 'array', 'SET_LOWERCASE' => 'array',
        'CUSTOM(INT)' => 'string', 'CUSTOM(MAP)' => 'map',
    ];
    return $map[$raw] ?? 'string';
}

function normalize_default($v, $type, $name) {
    $v = preg_replace('/\s*ALT\s*$/', '', trim($v));
    if ($v === '"' || $v === '') {
        return '';
    }
    if (strtoupper($v) === 'NULL') {
        // OTEL env vars are string-typed and use "" (not null) as their "unset" default.
        if (strpos($name, 'OTEL_') === 0) {
            return '';
        }
        return null;
    }
    if ($type === 'boolean') {
        if ($v === '0') return 'false';
        if ($v === '1') return 'true';
    }
    if ($name === 'DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP') {
        $v = stripslashes($v);
    }
    return $v;
}

function normalize_aliases($aliases, $canonical) {
    $out = [];
    foreach ($aliases as $a) {
        $a = trim(preg_replace('/\s*ALT\s*$/', '', $a));
        if ($a !== '' && $a !== $canonical && $a !== 'ALT') {
            $out[$a] = true;
        }
    }
    return array_keys($out);
}

function normalize_supported_entries($entries, $canonical) {
    if (!is_array($entries)) {
        return [];
    }
    $normalized = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (isset($entry["aliases"]) && is_array($entry["aliases"])) {
            $aliases = normalize_aliases($entry["aliases"], $canonical);
            if (!empty($aliases)) {
                sort($aliases);
                $entry["aliases"] = $aliases;
            } else {
                unset($entry["aliases"]);
            }
        }
        $normalized[] = $entry;
    }
    return $normalized;
}

$supported = [];
foreach (explode("|NEXT_CONFIG|", file_get_contents("php://stdin")) as $configLine) {
    $config = str_getcsv(trim($configLine), ",", '"', '\\');
    if (count($config) < 3) {
        continue;
    }
    [$type, $name, $default] = array_map('trim', array_slice($config, 0, 3));
    $aliases = count($config) > 3 ? array_slice($config, 3) : [];
    $mappedType = map_type($type);
    $entry = [
        "implementation" => "A",
        "type" => $mappedType,
        "default" => normalize_default($default, $mappedType, $name),
    ];
    $norm = normalize_aliases($aliases, $name);
    if (!empty($norm)) {
        sort($norm);
        $entry["aliases"] = $norm;
    }
    $supported[$name] = [$entry];
}

$otelPath = "../ext/otel_config.c";
if (file_exists($otelPath)) {
    preg_match_all('/ZAI_STRL\("(OTEL_[A-Z0-9_]+)"\)/', file_get_contents($otelPath), $m);
    $otelVars = array_unique($m[1]);
    sort($otelVars);
    foreach ($otelVars as $v) {
        if (!isset($supported[$v])) {
            $supported[$v] = [["implementation" => "A", "type" => "string", "default" => ""]];
        }
    }
}

if (empty($supported)) {
    fwrite(STDERR, "Error: no supported configurations were generated\n");
    exit(1);
}
ksort($supported);

$outputPath = "../metadata/supported-configurations.json";
$output = [
    "version" => "2",
    "supportedConfigurations" => $supported,
    "deprecations" => (object)[],
];
if (file_exists($outputPath)) {
    $existing = json_decode(file_get_contents($outputPath), true);
    if (is_array($existing)) {
        $output["deprecations"] = isset($existing["deprecations"]) && is_array($existing["deprecations"])
            ? (object)$existing["deprecations"] : (object)[];
        $existingSupported = $existing["supportedConfigurations"] ?? [];
        $merged = [];
        foreach ($supported as $name => $entries) {
            $generatedEntry = $entries[0];
            $existingEntries = normalize_supported_entries($existingSupported[$name] ?? [], $name);
            $updated = false;
            foreach ($existingEntries as $idx => $existingEntry) {
                if (($existingEntry["implementation"] ?? null) === "A") {
                    $existingEntries[$idx] = $generatedEntry;
                    $updated = true;
                    break;
                }
            }
            if (!$updated) {
                $existingEntries[] = $generatedEntry;
            }
            $merged[$name] = $existingEntries;
        }
        ksort($merged);
        $output["supportedConfigurations"] = $merged;
    }
} else {
    $output["supportedConfigurations"] = $supported;
}

$dir = dirname($outputPath);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$json = preg_replace_callback('/^ +/m', function ($m) {
    return str_repeat('  ', (int)(strlen($m[0]) / 4));
}, $json);
file_put_contents($outputPath, $json . "\n");
echo "Wrote supported configurations to $outputPath\n";
ENDPHP
)

cat <<EOT >../ext/version.h
#ifndef PHP_DDTRACE_VERSION
#define PHP_DDTRACE_VERSION "$(cat "../VERSION")"
#endif
EOT

PHP_VERSION_ID=${PHP_VERSION_ID:-0}
gcc $(php-config --includes) -I.. -I../ext -I../zend_abstract_interface -I../src/dogstatsd -I../components-rs -x c -E - <<CODE | grep -A9999 -m1 -F 'JSON_CONFIGURATION_MARKER' | tail -n+2 | php -r "$PHP_CODE"
#include "../ext/configuration.h"

#undef PHP_VERSION_ID
#define PHP_VERSION_ID $PHP_VERSION_ID
#undef DD_SIDECAR_TRACE_SENDER_DEFAULT
#if PHP_VERSION_ID >= 80300
#define DD_SIDECAR_TRACE_SENDER_DEFAULT true
#else
#define DD_SIDECAR_TRACE_SENDER_DEFAULT false
#endif
// Do not expand CALIASES() directly, otherwise parameter counting in macros is broken
#define ALTCALIASES(...) ,##__VA_ARGS__
#define EXPAND(x) x
#define CUSTOM(id) id
#define CONFIG(type, name, default_value, ...) CALIAS(type, name, default_value,)
#define CALIAS(type, name, default_value, aliases, ...) #type, #name, default_value EXPAND(ALT##aliases) |NEXT_CONFIG|

JSON_CONFIGURATION_MARKER
DD_CONFIGURATION

CODE
