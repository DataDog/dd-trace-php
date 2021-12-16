<?php

// THE FOLLOWING FUNCTIONS ARE IMPLEMENTED AT THE C-level
// \ddtrace_config_app_name()
// \ddtrace_config_distributed_tracing_enabled()
// \ddtrace_config_integration_enabled()
// \ddtrace_config_trace_enabled()
// \DDTrace\Config\integration_analytics_enabled()
// \DDTrace\Config\integration_analytics_sample_rate()

/**
 * Reads and normalizes a string configuration param, applying default value if appropriate.
 *
 * @param string|null $value
 * @param string $default
 * @return string
 */
function _ddtrace_config_string($value, $default)
{
    if (false === $value || null === $value || "" === $value) {
        return $default;
    }

    return trim($value);
}

/**
 * Reads and normalizes a boolean configuration param, applying default value if appropriate.
 *
 * @param string|null $value
 * @param boolean $default
 * @return boolean
 */
function _ddtrace_config_bool($value, $default)
{
    if (false === $value || null === $value || "" === $value) {
        return $default;
    }

    $value = strtolower($value);
    if ($value === '1' || $value === 'true') {
        return true;
    } elseif ($value === '0' || $value === 'false') {
        return false;
    } else {
        return $default;
    }
}

/**
 * Reads and normalizes a float configuration param, applying default value if appropriate.
 *
 * @param string|null $value
 * @param float $default
 * @param float $min if the final value is less then $min, then $min is returned.
 * @param float $max if the final value is greater then $max, then $max is returned.
 * @return float
 */
function _ddtrace_config_float($value, $default, $min = null, $max = null)
{
    if (false === $value || null === $value || "" === $value) {
        return $default;
    }

    $value = strtolower($value);
    if (is_numeric($value)) {
        $floatValue = (float)$value;
    } else {
        $floatValue = (float)$default;
    }

    if (null !== $min && $floatValue < $min) {
        $floatValue = $min;
    }

    if (null !== $max && $floatValue > $max) {
        $floatValue = $max;
    }

    return $floatValue;
}

/**
 * Reads a string formatted as a json object from a configuration param, applying default value if appropriate.
 *
 * @param string|null $value
 * @param array $default
 * @return array
 */
function _ddtrace_config_json($value, $default)
{
    if (false === $value || null === $value || "" === $value) {
        return $default;
    }

    // If the char `'` used to escape the json object reaches this variable, it has to be removed.
    $value = trim($value, "'");

    $parsed = \json_decode($value, true);
    if (null === $parsed) {
        return $default;
    }

    return $parsed;
}

/**
 * Reads a string formatted as a csv list from a configuration param, applying default value if appropriate.
 *
 * @param string|null $value
 * @param string[] $default
 * @return string[]
 */
function _ddtrace_config_indexed_array($value, $default)
{
    if (false === $value || null === $value || "" === $value) {
        return $default;
    }

    return array_map(
        function ($entry) {
            return trim($entry);
        },
        explode(',', $value)
    );
}

/**
 * Reads a string formatted as an associative array from a configuration param, applying default value if appropriate.
 * Note that no normalization nor escaping is currently applied.
 * Examples include: 'key1:value1,key2:value2'.
 *
 * @param string|null $value
 * @param string[] $default
 * @return string[]
 */
function _ddtrace_config_associative_array($value, $default)
{
    if (false === $value || null === $value || "" === $value) {
        return $default;
    }

    $result = [];
    $elements = explode(',', $value);
    foreach ($elements as $element) {
        $keyAndValue = explode(':', $element);

        if (count($keyAndValue) !== 2) {
            continue;
        }

        $keyFragment = trim($keyAndValue[0]);
        $valueFragment = trim($keyAndValue[1]);

        if (empty($keyFragment)) {
            continue;
        }

        $result[$keyFragment] = $valueFragment;
    }
    return $result;
}

function ddtrace_config_read_env_or_ini($name)
{
    $ini_name = strtolower(strtr($name, [
        "DD_TRACE_" => "datadog.trace.",
        "DD_" => "datadog.",
    ]));
    $ini = ini_get($ini_name);
    if ($ini !== false) {
        return $ini;
    }
    return \getenv($name);
}

/**
 * Returns the configured environment or empty string if none is configured.
 *
 * @return string
 */
function ddtrace_config_env()
{
    return \_ddtrace_config_string(\ddtrace_config_read_env_or_ini('DD_ENV'), "");
}

/**
 * Returns the configured service version or empty string if none is configured.
 *
 * @return string
 */
function ddtrace_config_service_version()
{
    return \_ddtrace_config_string(\ddtrace_config_read_env_or_ini('DD_VERSION'), "");
}

/**
 * Whether or not debug mode is enabled.
 *
 * @return bool
 */
function ddtrace_config_debug_enabled()
{
    return \_ddtrace_config_bool(\ddtrace_config_read_env_or_ini('DD_TRACE_DEBUG'), false);
}

/**
 * Whether or not automatic trace analytics configuration is enabled.
 *
 * @return bool
 */
function ddtrace_config_analytics_enabled()
{
    return \_ddtrace_config_bool(\ddtrace_config_read_env_or_ini('DD_TRACE_ANALYTICS_ENABLED'), false);
}

/**
 * Whether or not priority sampling is enabled globally.
 *
 * @return bool
 */
function ddtrace_config_priority_sampling_enabled()
{
    return \ddtrace_config_distributed_tracing_enabled()
        && \_ddtrace_config_bool(\ddtrace_config_read_env_or_ini('DD_PRIORITY_SAMPLING'), true);
}

/**
 * Append hostname as a root span tag
 *
 * @return bool
 */
function ddtrace_config_hostname_reporting_enabled()
{
    return \_ddtrace_config_bool(\ddtrace_config_read_env_or_ini('DD_TRACE_REPORT_HOSTNAME'), false);
}

/**
 * Use normalized URL as resource name
 *
 * @return bool
 */
function ddtrace_config_url_resource_name_enabled()
{
    return \_ddtrace_config_bool(\ddtrace_config_read_env_or_ini('DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED'), true);
}

/**
 * @return string[]
 */
function ddtrace_config_path_fragment_regex()
{
    return \_ddtrace_config_indexed_array(\ddtrace_config_read_env_or_ini('DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX'), []);
}

/**
 * @return string[]
 */
function ddtrace_config_path_mapping_incoming()
{
    return \_ddtrace_config_indexed_array(
        \ddtrace_config_read_env_or_ini('DD_TRACE_RESOURCE_URI_MAPPING_INCOMING'),
        []
    );
}

/**
 * @return string[]
 */
function ddtrace_config_path_mapping_outgoing()
{
    return \_ddtrace_config_indexed_array(
        \ddtrace_config_read_env_or_ini('DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING'),
        []
    );
}

/**
 * Set URL hostname as service name
 *
 * @return bool
 */
function ddtrace_config_http_client_split_by_domain_enabled()
{
    return \_ddtrace_config_bool(\ddtrace_config_read_env_or_ini('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN'), false);
}

/**
 * Set Redis client service name based on hostname
 *
 * @return bool
 */
function ddtrace_config_redis_client_split_by_host_enabled()
{
    return \_ddtrace_config_bool(\ddtrace_config_read_env_or_ini('DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST'), false);
}

/**
 * Whether or not also unfinished spans should be finished (and thus sent) when tracer is flushed.
 * Motivation: We had users reporting that in some cases they have manual end-points that `echo` some content and
 * then just `exit(0)` at the end of action's method. While the shutdown hook that flushes traces would still be
 * called, many spans would be unfinished and thus discarded. With this option enabled spans are automatically
 * finished (if not finished yet) when the tracer is flushed.
 *
 * @return bool
 */
function ddtrace_config_autofinish_span_enabled()
{
    return \_ddtrace_config_bool(\ddtrace_config_read_env_or_ini('DD_AUTOFINISH_SPANS'), false);
}

/**
 * Returns the global tags to be set on all spans.
 */
function ddtrace_config_global_tags()
{
    $rawValue = \ddtrace_config_read_env_or_ini('DD_TAGS');
    if (false === $rawValue) {
        // Fallback to legacy env variable name
        $rawValue = \ddtrace_config_read_env_or_ini('DD_TRACE_GLOBAL_TAGS');
    }
    return \_ddtrace_config_associative_array($rawValue, []);
}

/**
 * Returns the service mapping.
 */
function ddtrace_config_service_mapping()
{
    return \_ddtrace_config_associative_array(\ddtrace_config_read_env_or_ini('DD_SERVICE_MAPPING'), []);
}

/**
 * Returns the list of header names to be added as a tag to the root span. Header names are converted to lowercase.
 */
function ddtrace_config_http_headers()
{
    return array_map(
        function ($header) {
            return \strtolower($header);
        },
        \_ddtrace_config_indexed_array(\ddtrace_config_read_env_or_ini('DD_TRACE_HEADER_TAGS'), [])
    );
}
