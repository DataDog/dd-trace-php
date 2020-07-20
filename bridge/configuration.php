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
    if (false === $value || null === $value) {
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
    if (false === $value || null === $value) {
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
    if (false === $value || null === $value) {
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
    if (false === $value || null === $value) {
        return $default;
    }

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
    if (false === $value || null === $value) {
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
    if (false === $value || null === $value) {
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

/**
 * Returns the configured environment or null if none is configured.
 *
 * @return string
 */
function ddtrace_config_env()
{
    return \_ddtrace_config_string(\getenv('DD_ENV'), null);
}

/**
 * Returns the configured service version or null if none is configured.
 *
 * @return string
 */
function ddtrace_config_service_version()
{
    return \_ddtrace_config_string(\getenv('DD_VERSION'), null);
}

/**
 * Whether or not debug mode is enabled.
 *
 * @return bool
 */
function ddtrace_config_debug_enabled()
{
    return \_ddtrace_config_bool(\getenv('DD_TRACE_DEBUG'), false);
}

/**
 * Whether or not automatic trace analytics configuration is enabled.
 *
 * @return bool
 */
function ddtrace_config_analytics_enabled()
{
    return \_ddtrace_config_bool(\getenv('DD_TRACE_ANALYTICS_ENABLED'), false);
}

/**
 * Whether or not priority sampling is enabled globally.
 *
 * @return bool
 */
function ddtrace_config_priority_sampling_enabled()
{
    return \ddtrace_config_distributed_tracing_enabled()
        && \_ddtrace_config_bool(\getenv('DD_PRIORITY_SAMPLING'), true);
}

/**
 * Append hostname as a root span tag
 *
 * @return bool
 */
function ddtrace_config_hostname_reporting_enabled()
{
    return \_ddtrace_config_bool(\getenv('DD_TRACE_REPORT_HOSTNAME'), false);
}

/**
 * Use normalized URL as resource name
 *
 * @return bool
 */
function ddtrace_config_url_resource_name_enabled()
{
    return \_ddtrace_config_bool(\getenv('DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED'), true);
}

/**
 * @return string[]
 */
function ddtrace_config_path_fragment_regex()
{
    return \_ddtrace_config_indexed_array(\getenv('DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX'), []);
}

/**
 * @return string[]
 */
function ddtrace_config_path_mapping_incoming()
{
    return \_ddtrace_config_indexed_array(\getenv('DD_TRACE_RESOURCE_URI_MAPPING_INCOMING'), []);
}

/**
 * @return string[]
 */
function ddtrace_config_path_mapping_outgoing()
{
    return \_ddtrace_config_indexed_array(\getenv('DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING'), []);
}

/**
 * Set URL hostname as service name
 *
 * @return bool
 */
function ddtrace_config_http_client_split_by_domain_enabled()
{
    return \_ddtrace_config_bool(\getenv('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN'), false);
}

/**
 * Whether or not sandboxed tracing closures are enabled.
 *
 * @return bool
 */
function ddtrace_config_sandbox_enabled()
{
    return \dd_trace_env_config("DD_TRACE_SANDBOX_ENABLED");
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
    return \_ddtrace_config_bool(\getenv('DD_AUTOFINISH_SPANS'), false);
}

/**
 * Returns the sampling rate provided by the user. Default: 1.0 (keep all).
 *
 * @return float
 */
function ddtrace_config_sampling_rate()
{
    $deprecated = \_ddtrace_config_float(\getenv('DD_SAMPLING_RATE'), 1.0, 0.0, 1.0);
    return \_ddtrace_config_float(\getenv('DD_TRACE_SAMPLE_RATE'), $deprecated, 0.0, 1.0);
}

/**
 * Returns the sampling rules defined for the current service.
 * Results are cached so it is perfectly fine to call this method multiple times.
 * The expected format for sampling rule env variable is:
 * - example: DD_TRACE_SAMPLING_RULES=[]
 *        --> sample rate is 100%
 * - example: DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.2}]
 *        --> sample rate is 20%
 * - example: DD_TRACE_SAMPLING_RULES=[{"service": "a.*", "name": "b", "sample_rate": 0.1}, {"sample_rate": 0.2}]
 *        --> sample rate is 20% except for spans of service starting with 'a' and with name 'b' where rate is 10%
 *
 * Note that 'service' and 'name' is optional when when omitted the '*' pattern is assumed.
 *
 * @return array
 */
function ddtrace_config_sampling_rules()
{
    $json = \_ddtrace_config_json(\getenv('DD_TRACE_SAMPLING_RULES'), []);
    $normalized = [];
    // We do a proper parsing here to make sure that once the sampling rules leave this method
    // they are always properly defined.
    foreach ($json as &$rule) {
        if (!is_array($rule) || !isset($rule['sample_rate'])) {
            continue;
        }
        $service = isset($rule['service']) ? strval($rule['service']) : '.*';
        $name = isset($rule['name']) ? strval($rule['name']) : '.*';
        $rate = isset($rule['sample_rate']) ? floatval($rule['sample_rate']) : 1.0;
        $normalized[] = [
            'service' => $service,
            'name' => $name,
            'sample_rate' => $rate,
        ];
    }
    return $normalized;
}

/**
 * Returns the global tags to be set on all spans.
 */
function ddtrace_config_global_tags()
{
    $rawValue = \getenv('DD_TAGS');
    if (false === $rawValue) {
        // Fallback to legacy env variable name
        $rawValue = \getenv('DD_TRACE_GLOBAL_TAGS');
    }
    return \_ddtrace_config_associative_array($rawValue, []);
}

/**
 * Returns the service mapping.
 */
function ddtrace_config_service_mapping()
{
    return \_ddtrace_config_associative_array(\getenv('DD_SERVICE_MAPPING'), []);
}
