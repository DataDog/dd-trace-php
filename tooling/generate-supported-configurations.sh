#!/bin/bash
# Generates metadata/supported-configurations.json from config definitions.
#
set -euo pipefail

cd "$(dirname "$0")"

readonly CONFIG_HEADER_FILES=(
    "ext/configuration.h"
    "appsec/src/extension/configuration.h"
)
readonly OTEL_CONFIG_FILE="ext/otel_config.c"
readonly PROFILING_CONFIG_FILE="profiling/src/config.rs"
readonly GENERATOR_SCRIPT_FILE="tooling/generate-supported-configurations.sh"
readonly CONFIG_GENERATION_INPUT_FILES=(
    "${CONFIG_HEADER_FILES[@]}"
    "${OTEL_CONFIG_FILE}"
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

$otelPath = getenv('DDTRACE_SUPPORTED_CONFIG_OTEL_FILE') ?: "../ext/otel_config.c";
if (file_exists($otelPath)) {
    preg_match_all('/ZAI_STRL\("(OTEL_[A-Z0-9_]+)"\)/', file_get_contents($otelPath), $m);
    $otelVars = array_unique($m[1]);
    sort($otelVars);
    foreach ($otelVars as $v) {
        add_supported_entry($supported, $v, ["implementation" => "A", "type" => "string", "default" => ""]);
    }
}

$profilingPath = getenv('DDTRACE_SUPPORTED_CONFIG_PROFILING_FILE') ?: "../profiling/src/config.rs";
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
    "${CPP_COMPILER_CMD[@]}" $(php-config --includes) -I.. -I../ext -I../zend_abstract_interface -I../src/dogstatsd -I../components-rs -x c -E - <<CODE | grep -A9999 -m1 -F "JSON_CONFIGURATION_MARKER" | tail -n+2
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
DD_CONFIGURATION

CODE
}

{
    for header in "${CONFIG_HEADER_FILES[@]}"; do
        extract_c_supported_configurations "../$header"
    done
} | DDTRACE_SUPPORTED_CONFIG_OTEL_FILE="../${OTEL_CONFIG_FILE}" DDTRACE_SUPPORTED_CONFIG_PROFILING_FILE="../${PROFILING_CONFIG_FILE}" php "$PHP_CODE_FILE"
