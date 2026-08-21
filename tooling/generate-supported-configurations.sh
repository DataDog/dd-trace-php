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
    "tracer/configuration.h"
    "${OTEL_CONFIG_FILES[@]}"
    "${PROFILING_CONFIG_FILE}"
    "${GENERATOR_SCRIPT_FILE}"
)

SELF_TEST=0
if [[ $# -gt 1 ]]; then
    echo "Usage: $0 [--print-input-files|--self-test]" >&2
    exit 1
fi
case "${1:-}" in
    --print-input-files)
        printf '%s\n' "${CONFIG_GENERATION_INPUT_FILES[@]}"
        exit 0
        ;;
    --self-test)
        SELF_TEST=1
        ;;
    "")
        ;;
    *)
        echo "Usage: $0 [--print-input-files|--self-test]" >&2
        exit 1
        ;;
esac

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

$SENSITIVE_CONFIGURATIONS = [];

function mask_c_non_code($source) {
    $source = str_replace(["\\\r\n", "\\\n", "\\\r"], '', $source);
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $escaped = false;
    $length = strlen($source);
    for ($i = 0; $i < $length; $i++) {
        $char = $source[$i];
        $next = $i + 1 < $length ? $source[$i + 1] : '';
        if ($lineComment) {
            if ($char === "\n") {
                $lineComment = false;
            } else {
                $source[$i] = ' ';
            }
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') {
                $source[$i] = ' ';
                $source[$i + 1] = ' ';
                $blockComment = false;
                $i++;
            } elseif ($char !== "\n") {
                $source[$i] = ' ';
            }
            continue;
        }
        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === $quote) {
                $quote = null;
            }
            if ($char !== "\n") {
                $source[$i] = ' ';
            }
            continue;
        }
        if ($char === '/' && $next === '/') {
            $source[$i] = ' ';
            $source[$i + 1] = ' ';
            $lineComment = true;
            $i++;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $source[$i] = ' ';
            $source[$i + 1] = ' ';
            $blockComment = true;
            $i++;
            continue;
        }
        if ($char === '"' || $char === "'") {
            $quote = $char;
            $source[$i] = ' ';
        }
    }
    return $source;
}

function config_macro_arglist($source, $openParen) {
    $depth = 0;
    $length = strlen($source);
    for ($i = $openParen; $i < $length; $i++) {
        if ($source[$i] === '(') {
            $depth++;
        } elseif ($source[$i] === ')' && --$depth === 0) {
            return substr($source, $openParen + 1, $i - $openParen - 1);
        }
    }
    return substr($source, $openParen + 1);
}

function extract_sensitive_config_names_from_source($source) {
    $sensitive = [];
    $source = mask_c_non_code($source);
    preg_match_all('/\b(?:CONFIG|SYSCFG|CALIAS)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as [$macro, $offset]) {
        $openParen = $offset + strrpos($macro, '(');
        $argList = config_macro_arglist($source, $openParen);
        if (preg_match('/^\s*[^,]+,\s*([A-Z][A-Z0-9_]+)/', $argList, $nameMatch)
            && preg_match('/\.sensitive\s*=\s*true\b/', $argList)) {
            $sensitive[$nameMatch[1]] = true;
        }
    }
    return $sensitive;
}

function extract_sensitive_config_names($paths) {
    $sensitive = [];
    foreach ($paths as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $source = file_get_contents($path);
        if ($source === false || $source === '') {
            continue;
        }
        foreach (extract_sensitive_config_names_from_source($source) as $name => $_) {
            $sensitive[$name] = true;
        }
    }
    return $sensitive;
}

function mark_sensitive($entry, $name) {
    global $SENSITIVE_CONFIGURATIONS;
    if (isset($SENSITIVE_CONFIGURATIONS[$name])) {
        $entry["sensitive"] = true;
    }
    return $entry;
}

function add_supported_entry(&$supported, $name, $entry) {
    // Keep the first-seen source as canonical for duplicate names.
    if (!isset($supported[$name])) {
        $supported[$name] = [mark_sensitive($entry, $name)];
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
            $entry = ["implementation" => "A", "type" => $type, "default" => $default];
            $supported[$name] = [mark_sensitive($entry, $name)];
        } else {
            // Not in the table: an OTEL var resolved by the SDK that we don't model.
            add_supported_entry($supported, $name, ["implementation" => "A", "type" => "string", "default" => ""]);
        }
    }
}

function php_token_is_ignored($token) {
    return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

function php_next_significant_token($tokens, $index, $direction) {
    $tokenCount = count($tokens);
    for ($i = $index + $direction; $i >= 0 && $i < $tokenCount; $i += $direction) {
        if (!php_token_is_ignored($tokens[$i])) {
            return $i;
        }
    }
    return null;
}

function extract_otel_config_names($source, $constant) {
    $tokens = token_get_all($source);
    $tokenCount = count($tokens);
    for ($i = 0; $i < $tokenCount; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONST) {
            continue;
        }
        $nameIndex = php_next_significant_token($tokens, $i, 1);
        if ($nameIndex === null || !is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING || $tokens[$nameIndex][1] !== $constant) {
            continue;
        }
        $equalsIndex = php_next_significant_token($tokens, $nameIndex, 1);
        if ($equalsIndex === null || $tokens[$equalsIndex] !== '=') {
            continue;
        }
        $arrayIndex = php_next_significant_token($tokens, $equalsIndex, 1);
        if ($arrayIndex === null || $tokens[$arrayIndex] !== '[') {
            continue;
        }
        $names = [];
        for ($j = $arrayIndex + 1; $j < $tokenCount; $j++) {
            $token = $tokens[$j];
            if (php_token_is_ignored($token) || $token === ',') {
                continue;
            }
            if ($token === ']') {
                return $names;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $nextIndex = php_next_significant_token($tokens, $j, 1);
                if ($nextIndex !== null && ($tokens[$nextIndex] === ',' || $tokens[$nextIndex] === ']')) {
                    $value = eval('return ' . $token[1] . ';');
                    if (preg_match('/^OTEL_[A-Z0-9_]+$/D', $value)) {
                        $names[] = $value;
                    }
                    continue;
                }
            }
            throw new RuntimeException("Unsupported entry in $constant; use direct string literals");
        }
        throw new RuntimeException("Unterminated array for $constant");
    }
    return [];
}

if (getenv('DDTRACE_CONFIG_GENERATOR_SELF_TEST')) {
    $cSource = <<<'C'
// CONFIG(STRING, DD_FALSE_LINE, "", .sensitive = true) \
CONFIG(STRING, DD_FALSE_SPLICED_LINE, "", .sensitive = true)
/* CONFIG(STRING, DD_FALSE_BLOCK, "", .sensitive = true) */
const char *false_string = "CONFIG(STRING, DD_FALSE_STRING, \"\", .sensitive = true)";
CONFIG(CUSTOM(MAP), DD_REAL_NESTED, "escaped quote: \" and )", .sensitive = true)
CONFIG(STRING, DD_REAL_EVEN_ESCAPE, "escaped slash: \\", .sensitive = true)
C;
    $sensitive = extract_sensitive_config_names_from_source($cSource);
    $expectedSensitive = ['DD_REAL_EVEN_ESCAPE', 'DD_REAL_NESTED'];
    $actualSensitive = array_keys($sensitive);
    sort($actualSensitive);
    if ($actualSensitive !== $expectedSensitive) {
        throw new RuntimeException('C sensitive configuration parser self-test failed: ' . json_encode($actualSensitive));
    }

    $phpSource = <<<'PHP'
<?php
// const OTEL_CONFIG_WHITELIST = ['OTEL_FALSE_LINE'];
/* const OTEL_CONFIG_WHITELIST = ['OTEL_FALSE_BLOCK']; */
const OTEL_CONFIG_WHITELIST = [
    'OTEL_REAL_ONE',
    // 'OTEL_FALSE_ENTRY',
    "OTEL_REAL_TWO",
    "OTEL_REAL_\x54HREE",
    "OTEL_REAL_\u{46}OUR",
    "OTEL_FALSE\Q",
    "ignored ] and escaped quote: \" OTEL_FALSE_STRING",
];
PHP;
    $otelNames = extract_otel_config_names($phpSource, 'OTEL_CONFIG_WHITELIST');
    if ($otelNames !== ['OTEL_REAL_ONE', 'OTEL_REAL_TWO', 'OTEL_REAL_THREE', 'OTEL_REAL_FOUR']) {
        throw new RuntimeException('OTel configuration parser self-test failed: ' . json_encode($otelNames));
    }
    $unsupportedEntries = [
        "['OTEL_NESTED']",
        "'OTEL_STRING_KEY' => false",
        "7 => 'OTEL_KEYED_VALUE'",
        "'OTEL_CONCAT' . '_VALUE'",
        "('OTEL_PAREN')",
        "OTEL_CONSTANT_REFERENCE",
    ];
    foreach ($unsupportedEntries as $entry) {
        $unsupportedSource = "<?php const OTEL_CONFIG_WHITELIST = [$entry];";
        try {
            extract_otel_config_names($unsupportedSource, 'OTEL_CONFIG_WHITELIST');
        } catch (RuntimeException $e) {
            continue;
        }
        throw new RuntimeException("OTel configuration parser accepted unsupported entry: $entry");
    }
    exit(0);
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

$SENSITIVE_CONFIGURATIONS = extract_sensitive_config_names([
    "../ext/configuration.h",
    "../tracer/configuration.h",
    "../appsec/src/extension/configuration.h",
]);

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

$otelConfigurationPath = "../src/DDTrace/OpenTelemetry/Configuration.php";
if (file_exists($otelConfigurationPath)) {
    $otelConfigurationSource = file_get_contents($otelConfigurationPath);
    $otelConfigNames = extract_otel_config_names($otelConfigurationSource, "OTEL_CONFIG_WHITELIST");
    $otelSensitiveNames = extract_otel_config_names($otelConfigurationSource, "OTEL_SENSITIVE_CONFIGURATIONS");
    foreach ($otelSensitiveNames as $name) {
        $SENSITIVE_CONFIGURATIONS[$name] = true;
    }
    add_otel_entries($supported, array_merge($otelConfigNames, $otelSensitiveNames), $otelMetadata);
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

if [[ $SELF_TEST -eq 1 ]]; then
    DDTRACE_CONFIG_GENERATOR_SELF_TEST=1 php "$PHP_CODE_FILE"
    exit 0
fi

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
#undef DD_APPSEC_HELPER_RUST_REDIRECTION_DEFAULT
#define DD_APPSEC_HELPER_RUST_REDIRECTION_DEFAULT "true"
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
