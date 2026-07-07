#!/bin/bash
# Generates metadata/supported-configurations.json from config definitions.
#
set -euo pipefail

cd "$(dirname "$0")"

readonly CONFIG_HEADER_FILES=(
    "ext/configuration.h"
    "appsec/src/extension/configuration.h"
)
readonly OTEL_CONFIG_FILES=(
    "ext/otel_config.c"
    "tracer/tracer_otel_config.c"
    "src/DDTrace/OpenTelemetry/Configuration.php"
)
readonly PROFILING_CONFIG_FILE="profiling/src/config.rs"
readonly GENERATOR_SCRIPT_FILE="tooling/generate-supported-configurations.sh"
readonly CONFIG_GENERATION_INPUT_FILES=(
    "${CONFIG_HEADER_FILES[@]}"
    "${OTEL_CONFIG_FILES[@]}"
    "${PROFILING_CONFIG_FILE}"
    "${GENERATOR_SCRIPT_FILE}"
)

if [[ "${1:-}" == "--print-input-files" ]]; then
    printf '%s\n' "${CONFIG_GENERATION_INPUT_FILES[@]}"
    exit 0
fi
if [[ $# -gt 0 ]]; then
    echo "Usage: $0 [--print-input-files]" >&2
    exit 1
fi

# Maps C config type to JSON schema type.
PHP_CODE_FILE=$(mktemp "${TMPDIR:-/tmp}/ddtrace-supported-configurations.XXXXXX.php")
trap 'rm -f "$PHP_CODE_FILE"' EXIT

cat >"$PHP_CODE_FILE" <<'ENDPHP'
<?php
function map_type($raw) {
    $raw = trim($raw);
    if (preg_match('/^CUSTOM\((.+)\)$/', $raw, $m)) {
        $inner = trim($m[1]);
        // CUSTOM() types don't always map 1:1 to JSON schema types.
        // In particular, CUSTOM(INT) values are exposed as strings at the config boundary.
        if ($inner === 'INT') {
            return 'string';
        }
        if ($inner === 'MAP') {
            return 'map';
        }
        $raw = $inner;
    }
    $map = [
        'BOOL' => 'boolean', 'STRING' => 'string', 'INT' => 'int', 'DOUBLE' => 'decimal',
        'MAP' => 'map', 'JSON' => 'array', 'SET_OR_MAP_LOWERCASE' => 'map',
        'SET' => 'array', 'SET_LOWERCASE' => 'array',
        'uint32_t' => 'int', 'uint64_t' => 'int',
    ];
    return $map[$raw] ?? 'string';
}

function normalize_default($v, $type, $name) {
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
    // C-preprocessed string defaults keep one extra escaping layer.
    // Normalize all string-typed defaults consistently.
    if ($type === 'string') {
        $v = stripslashes($v);
    }
    return $v;
}

function normalize_aliases($aliases, $canonical) {
    $out = [];
    foreach ($aliases as $a) {
        if ($a !== '' && $a !== $canonical) {
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

function add_supported_entry(&$supported, $name, $entry) {
    // Keep the first-seen source as canonical for duplicate names.
    if (!isset($supported[$name])) {
        $supported[$name] = [$entry];
    }
}

function add_otel_entries(&$supported, $names, $metadata) {
    $names = array_unique($names);
    sort($names);
    foreach ($names as $name) {
        if (isset($metadata[$name])) {
            // The SDK metadata table is authoritative for OTEL vars: overwrite any
            // entry derived from an extension CONFIG of the same name (e.g.
            // OTEL_EXPORTER_OTLP_METRICS_ENDPOINT) so the published default is the
            // SDK's rather than the extension's runtime resolution.
            [$type, $default] = $metadata[$name];
            $supported[$name] = [["implementation" => "A", "type" => $type, "default" => $default]];
        } else {
            // Not in the table: an OTEL var resolved by the SDK that we don't model.
            add_supported_entry($supported, $name, ["implementation" => "A", "type" => "string", "default" => ""]);
        }
    }
}

// temporary solution until we merge configs
function map_rust_type($rawType, $parser) {
    $map = [
        'ZAI_CONFIG_TYPE_BOOL' => 'boolean',
        'ZAI_CONFIG_TYPE_STRING' => 'string',
        'ZAI_CONFIG_TYPE_INT' => 'int',
        'ZAI_CONFIG_TYPE_DOUBLE' => 'decimal',
        'ZAI_CONFIG_TYPE_MAP' => 'map',
        'ZAI_CONFIG_TYPE_JSON' => 'array',
        'ZAI_CONFIG_TYPE_SET' => 'array',
        'ZAI_CONFIG_TYPE_SET_LOWERCASE' => 'array',
        'ZAI_CONFIG_TYPE_SET_OR_MAP_LOWERCASE' => 'map',
    ];
    if (isset($map[$rawType])) {
        return $map[$rawType];
    }
    if ($rawType === 'ZAI_CONFIG_TYPE_CUSTOM') {
        if ($parser === 'parse_profiling_enabled') {
            return 'boolean';
        }
        if ($parser === 'parse_sampling_distance_filter') {
            return 'int';
        }
        return 'string';
    }
    return 'string';
}

function parse_rust_default($raw) {
    $raw = trim(preg_replace('/\/\/.*$/', '', $raw));
    if ($raw === 'ZaiStr::new()') {
        return '';
    }
    if (preg_match('/ZaiStr::literal\(b"((?:\\\\.|[^"\\\\])*)\\\\0"\)/', $raw, $m)) {
        return stripcslashes($m[1]);
    }
    return '';
}

function extract_rust_alias_groups($source) {
    $groups = [];
    if (preg_match_all('/const\s+([A-Z0-9_]+)\s*:\s*&\[ZaiStr\]\s*=\s*unsafe\s*\{\s*&\[(.*?)\]\s*\};/s', $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $aliases = [];
            if (preg_match_all('/ZaiStr::literal\(b"((?:\\\\.|[^"\\\\])*)\\\\0"\)/', $match[2], $aliasMatches)) {
                foreach ($aliasMatches[1] as $alias) {
                    $aliases[] = stripcslashes($alias);
                }
            }
            $groups[$match[1]] = $aliases;
        }
    }
    return $groups;
}

function extract_rust_env_var_names($source) {
    $names = [];
    if (preg_match_all('/([A-Za-z0-9_]+)\s*=>\s*b"([A-Z0-9_]+)\\\\0"/', $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $names[$match[1]] = $match[2];
        }
    }
    return $names;
}

function add_rust_profiling_configurations(&$supported, $path) {
    if (!file_exists($path)) {
        return;
    }
    $source = file_get_contents($path);
    if ($source === false || $source === '') {
        return;
    }

    $envVarByConfigId = extract_rust_env_var_names($source);
    if (empty($envVarByConfigId)) {
        return;
    }
    $aliasesByConstName = extract_rust_alias_groups($source);

    if (!preg_match_all('/zai_config_entry\s*\{(.*?)\n\s*},/s', $source, $entryMatches, PREG_SET_ORDER)) {
        return;
    }

    foreach ($entryMatches as $entryMatch) {
        $entryBlock = $entryMatch[1];
        if (!preg_match('/name:\s*([A-Za-z0-9_]+)\.env_var_name\(\),/', $entryBlock, $nameMatch)) {
            continue;
        }
        $configId = $nameMatch[1];
        if (!isset($envVarByConfigId[$configId])) {
            continue;
        }
        $name = $envVarByConfigId[$configId];

        if (!preg_match('/type_:\s*(ZAI_CONFIG_TYPE_[A-Z_]+),/', $entryBlock, $typeMatch)) {
            continue;
        }
        if (!preg_match('/default_encoded_value:\s*([^\n]+),/', $entryBlock, $defaultMatch)) {
            continue;
        }

        $parser = '';
        if (preg_match('/parser:\s*([^\n,]+),/', $entryBlock, $parserMatch)) {
            $parserRaw = trim($parserMatch[1]);
            if (preg_match('/Some\(([^)]+)\)/', $parserRaw, $parserNameMatch)) {
                $parser = trim($parserNameMatch[1]);
            }
        }

        $aliases = [];
        if (preg_match('/aliases:\s*([A-Z0-9_]+)\.as_ptr\(\),/', $entryBlock, $aliasesMatch)) {
            $aliases = $aliasesByConstName[$aliasesMatch[1]] ?? [];
        }

        $mappedType = map_rust_type(trim($typeMatch[1]), $parser);
        $entry = [
            "implementation" => "A",
            "type" => $mappedType,
            "default" => normalize_default(parse_rust_default($defaultMatch[1]), $mappedType, $name),
        ];
        $normAliases = normalize_aliases($aliases, $name);
        if (!empty($normAliases)) {
            sort($normAliases);
            $entry["aliases"] = $normAliases;
        }
        add_supported_entry($supported, $name, $entry);
    }
}

$supported = [];
foreach (explode("|NEXT_CONFIG|", file_get_contents("php://stdin")) as $configLine) {
    $configLine = preg_replace('((\\\\{2})*\K"\s*")', '', $configLine);
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
    add_supported_entry($supported, $name, $entry);
}

// Type and default for OTEL configs, sourced from the open-telemetry/sdk PHP
// package (Common/Configuration/ValueTypes.php and Defaults.php, v1.x), since
// these vars are resolved by the SDK rather than the extension. SDK enum/mixed
// types map to the schema's "string", list -> "array", map -> "map", and
// integer -> "int"; per the schema every default is a string and map/array
// defaults are "". The *_LOGS_* vars are undefined in the SDK's ValueTypes and
// mirror their generic OTLP counterparts. The metrics temporality default is the
// SDK value "cumulative"; DatadogResolver overrides it to "delta" at runtime.
// These SDK defaults are authoritative even where the extension also defines the
// config (e.g. OTEL_EXPORTER_OTLP_METRICS_ENDPOINT), so the published default
// reflects the OTel spec, not the extension's runtime resolution.
$otelMetadata = [
    "OTEL_BLRP_EXPORT_TIMEOUT"                          => ["int",    "30000"],
    "OTEL_BLRP_MAX_EXPORT_BATCH_SIZE"                   => ["int",    "512"],
    "OTEL_BLRP_MAX_QUEUE_SIZE"                          => ["int",    "2048"],
    "OTEL_BLRP_SCHEDULE_DELAY"                          => ["int",    "1000"],
    "OTEL_EXPORTER_OTLP_ENDPOINT"                       => ["string", "http://localhost:4318"],
    "OTEL_EXPORTER_OTLP_HEADERS"                        => ["map",    ""],
    "OTEL_EXPORTER_OTLP_LOGS_ENDPOINT"                  => ["string", "http://localhost:4318"],
    "OTEL_EXPORTER_OTLP_LOGS_HEADERS"                   => ["map",    null],
    "OTEL_EXPORTER_OTLP_LOGS_PROTOCOL"                  => ["string", "http/protobuf"],
    "OTEL_EXPORTER_OTLP_LOGS_TIMEOUT"                   => ["int",    "10000"],
    "OTEL_EXPORTER_OTLP_METRICS_ENDPOINT"               => ["string", "http://localhost:4318"],
    "OTEL_EXPORTER_OTLP_METRICS_HEADERS"                => ["map",    null],
    "OTEL_EXPORTER_OTLP_METRICS_PROTOCOL"               => ["string", "http/protobuf"],
    "OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE" => ["string", "cumulative"],
    "OTEL_EXPORTER_OTLP_METRICS_TIMEOUT"                => ["int",    "10000"],
    "OTEL_EXPORTER_OTLP_PROTOCOL"                       => ["string", "http/protobuf"],
    "OTEL_EXPORTER_OTLP_TIMEOUT"                        => ["int",    "10000"],
    "OTEL_LOG_LEVEL"                                    => ["string", "info"],
    "OTEL_LOGS_EXPORTER"                                => ["array",  "otlp"],
    "OTEL_METRICS_EXPORTER"                             => ["array",  "otlp"],
    "OTEL_METRIC_EXPORT_INTERVAL"                       => ["int",    "60000"],
    "OTEL_METRIC_EXPORT_TIMEOUT"                        => ["int",    "30000"],
    "OTEL_PROPAGATORS"                                  => ["array",  "tracecontext,baggage"],
    "OTEL_RESOURCE_ATTRIBUTES"                          => ["map",    ""],
    "OTEL_SERVICE_NAME"                                 => ["string", ""],
    "OTEL_TRACES_EXPORTER"                              => ["array",  "otlp"],
    "OTEL_TRACES_SAMPLER"                               => ["string", "parentbased_always_on"],
    "OTEL_TRACES_SAMPLER_ARG"                           => ["string", ""],
];

// OTEL env vars the C extension reads directly via ZAI_STRL("OTEL_...").
$otelPaths = ["../ext/otel_config.c", "../tracer/tracer_otel_config.c"];
foreach ($otelPaths as $otelPath) {
    if (file_exists($otelPath)) {
        preg_match_all('/ZAI_STRL\("(OTEL_[A-Z0-9_]+)"\)/', file_get_contents($otelPath), $m);
        add_otel_entries($supported, $m[1], $otelMetadata);
    }
}

// OTEL configs read by the OpenTelemetry SDK rather than the extension (e.g.
// OTEL_EXPORTER_OTLP_HEADERS), enumerated in the PHP telemetry whitelist.
// Scope to the OTEL_CONFIG_WHITELIST array literal so unrelated OTEL_ mentions
// elsewhere in the file (comments, error strings) can't be published.
$otelWhitelistPath = "../src/DDTrace/OpenTelemetry/Configuration.php";
if (file_exists($otelWhitelistPath)
    && preg_match('/OTEL_CONFIG_WHITELIST\s*=\s*\[(.*?)\]/s', file_get_contents($otelWhitelistPath), $whitelistMatch)) {
    preg_match_all('/\'(OTEL_[A-Z0-9_]+)\'/', $whitelistMatch[1], $m);
    add_otel_entries($supported, $m[1], $otelMetadata);
}

$profilingPath = "../profiling/src/config.rs";
add_rust_profiling_configurations($supported, $profilingPath);

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
            if (!empty($existingEntries)) {
                // Existing keys must keep their implementation labels exactly as-is.
                // Update the "A" entry when present, otherwise update the first entry.
                $targetIdx = 0;
                foreach ($existingEntries as $idx => $existingEntry) {
                    if (($existingEntry["implementation"] ?? null) === "A") {
                        $targetIdx = $idx;
                        break;
                    }
                }
                $impl = $existingEntries[$targetIdx]["implementation"] ?? null;
                $existingEntries[$targetIdx] = $generatedEntry;
                if ($impl !== null) {
                    $existingEntries[$targetIdx]["implementation"] = $impl;
                } else {
                    unset($existingEntries[$targetIdx]["implementation"]);
                }
            } else {
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

cat <<EOT >../ext/version.h
#ifndef PHP_DDTRACE_VERSION
#define PHP_DDTRACE_VERSION "$(cat "../VERSION")"
#endif
EOT

PHP_VERSION_ID=${PHP_VERSION_ID:-0}

CPP_COMPILER=${CPP_COMPILER:-${CC:-}}
CPP_COMPILER_CMD=()
if [[ -n "$CPP_COMPILER" ]]; then
    read -r -a CPP_COMPILER_CMD <<<"$CPP_COMPILER"
    # If CC/CPP_COMPILER points to a missing wrapper (e.g. "ccache cc" without ccache),
    # fall back to auto-discovery below.
    if [[ ${#CPP_COMPILER_CMD[@]} -gt 0 ]] && ! command -v "${CPP_COMPILER_CMD[0]}" >/dev/null 2>&1; then
        CPP_COMPILER_CMD=()
    fi
fi
if [[ ${#CPP_COMPILER_CMD[@]} -eq 0 ]]; then
    for candidate in cc clang gcc; do
        if command -v "$candidate" >/dev/null 2>&1; then
            CPP_COMPILER_CMD=("$candidate")
            break
        fi
    done
fi
if [[ ${#CPP_COMPILER_CMD[@]} -eq 0 ]]; then
    echo "Error: no C compiler found (tried CC, cc, clang, gcc)" >&2
    exit 1
fi

extract_c_supported_configurations() {
    local header="$1"
    # Normalize to non-Linux defaults so generated metadata stays stable
    # across developer machines and CI runners.
    "${CPP_COMPILER_CMD[@]}" $(php-config --includes) -I.. -I../ext -I../zend_abstract_interface -I../src/dogstatsd -I../components-rs -x c -E - <<CODE | grep -A9999 -m1 -F "JSON_CONFIGURATION_MARKER" | tail -n+2
#undef __linux__
#define DDTRACE
#include "$header"

#undef PHP_VERSION_ID
#define PHP_VERSION_ID $PHP_VERSION_ID
#undef DD_SIDECAR_TRACE_SENDER_DEFAULT
#if PHP_VERSION_ID >= 80300
#define DD_SIDECAR_TRACE_SENDER_DEFAULT true
#else
#define DD_SIDECAR_TRACE_SENDER_DEFAULT false
#endif
// Do not expand CALIASES() directly, otherwise parameter counting in macros is broken.
#define ALTCALIASES(...) ,##__VA_ARGS__
#define ALT
#define EXPAND(x) x
#define CUSTOM(id) id
// Preserve the literal config type tokens (e.g. CUSTOM(INT)) so the generator can
// map them to the correct JSON schema type.
#define CONFIG(type, name, default_value, ...) CALIAS(#type, name, default_value,)
#define SYSCFG(type, name, default_value, ...) CONFIG(type, name, default_value, __VA_ARGS__)
#define CALIAS(type, name, default_value, aliases, ...) type, #name, default_value EXPAND(ALT##aliases) |NEXT_CONFIG|

JSON_CONFIGURATION_MARKER
#ifdef DD_ALL_CONFIGURATIONS
DD_ALL_CONFIGURATIONS
#else
DD_CONFIGURATION
#endif

CODE
}

{
    for header in "${CONFIG_HEADER_FILES[@]}"; do
        extract_c_supported_configurations "../$header"
    done
} | php "$PHP_CODE_FILE"
