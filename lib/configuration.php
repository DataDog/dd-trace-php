<?php

function _ddtrace_config_string($value, $default)
{
    if (false === $value || null === $value) {
        return $default;
    }

    return trim($value);
}

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

function _ddtrace_config_json($value, $default)
{
    if (false === $value || null === $value) {
        return $default;
    }

    $parsed = \json_decode($this->stringValue('trace.sampling.rules'), true);
    if (false === $parsed) {
        $parsed = $default;
    }

    return $parsed;
}

function _ddtrace_config_indexed_array($value, $default)
{
    if (false === $value || null === $value) {
        return $default;
    }

    return array_map(
        function ($entry) {
            return strtolower(trim($entry));
        },
        explode(',', $value)
    );
}

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
 * Whether or not debug mode is enabled.
 *
 * @return bool
 */
function ddtrace_config_debug_is_enabled()
{
    return \_ddtrace_config_bool(\dd_trace_env_config('DD_TRACE_DEBUG'), false);
}

/**
 * Whether or not distributed tracing is enabled globally.
 *
 * @return bool
 */
function ddtrace_config_distributed_tracing_is_enabled()
{
    return \_ddtrace_config_bool(\dd_trace_env_config('DD_DISTRIBUTED_TRACING'), true);
}

/**
 * Whether or not automatic trace analytics configuration is enabled.
 *
 * @return bool
 */
function ddtrace_config_analytics_is_enabled()
{
    return \_ddtrace_config_bool(\dd_trace_env_config('DD_TRACE_ANALYTICS_ENABLED'), true);
}

/**
 * Whether or not priority sampling is enabled globally.
 *
 * @return bool
 */
function ddtrace_config_priority_sampling_is_enabled()
{
    return \ddtrace_config_analytics_is_enabled() && \_ddtrace_config_bool(\dd_trace_env_config('DD_PRIORITY_SAMPLING'), true);
}

/**
 * Append hostname as a root span tag
 *
 * @return bool
 */
function ddtrace_config_hostname_reporting_is_enabled()
{
    return \_ddtrace_config_bool(\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME'), false);
}

/**
 * Use normalized URL as resource name
 *
 * @return bool
 */
function ddtrace_config_url_resource_name_is_enabled()
{
    return \_ddtrace_config_bool(\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME'), true);
}

/**
 * Set URL hostname as service name
 *
 * @return bool
 */
function ddtrace_config_http_client_split_by_domain_is_enabled()
{
    return \_ddtrace_config_bool(\dd_trace_env_config('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN'), false);
}

/**
 * Whether or not sandboxed tracing closures are enabled.
 *
 * @return bool
 */
function ddtrace_config_sandbox_is_enabled()
{
    return \_ddtrace_config_bool(\dd_trace_env_config('DD_TRACE_SANDBOX_ENABLED'), true);
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
function ddtrace_config_autofinish_span_is_enabled()
{
    return \_ddtrace_config_bool(\dd_trace_env_config('DD_AUTOFINISH_SPANS'), false);
}

/**
 * Returns the sampling rate provided by the user. Default: 1.0 (keep all).
 *
 * @return float
 */
function ddtrace_config_sampling_rate()
{
    return \_ddtrace_config_float(\dd_trace_env_config('DD_TRACE_SAMPLE_RATE'), 1.0, 0.0, 1.0);
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
    $json = \_ddtrace_config_json(\dd_trace_env_config('DD_TRACE_SAMPLING_RULES'), []);
    // We do a proper parsing here to make sure that once the sampling rules leave this method
    // they are always properly defined.
    foreach ($json as &$rule) {
        if (!is_array($rule) || !isset($rule['sample_rate'])) {
            continue;
        }
        $service = isset($rule['service']) ? strval($rule['service']) : '.*';
        $name = isset($rule['name']) ? strval($rule['name']) : '.*';
        $rate = isset($rule['sample_rate']) ? floatval($rule['sample_rate']) : 1.0;
        $this->samplingRulesCache[] = [
            'service' => $service,
            'name' => $name,
            'sample_rate' => $rate,
        ];
    }
    return $json;
}

function ddtrace_config_integration_analytics_is_enabled($name)
{
    $integrationNameForEnv = strtoupper(str_replace('-', '_', trim($name)));
    return \_ddtrace_config_bool(\dd_trace_env_config("DD_${integrationNameForEnv}_ANALYTICS_ENABLED"), false);
}

function ddtrace_config_integration_analytics_sample_rate($name)
{
    $integrationNameForEnv = strtoupper(str_replace('-', '_', trim($name)));
    return \_ddtrace_config_float(\dd_trace_env_config("DD_${integrationNameForEnv}_ANALYTICS_SAMPLE_RATE"), 1.0);
}

function ddtrace_config_disabled_integrations()
{
    return \_ddtrace_config_indexed_array(\dd_trace_env_config('DD_INTEGRATIONS_DISABLED'), []);
}

/**
 * Returns the global tags to be set on all spans.
 */
function ddtrace_config_global_tags()
{
    return \_ddtrace_config_associative_array(\dd_trace_env_config('DD_TRACE_GLOBAL_TAGS'), []);
}

/**
 * Returns the service mapping.
 */
function ddtrace_config_service_mapping()
{
    return \_ddtrace_config_associative_array(\dd_trace_env_config('DD_SERVICE_MAPPING'), []);
}
