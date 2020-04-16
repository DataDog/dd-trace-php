<?php

function _dd_config_string($value, $default)
{
    if (false === $value || null === $value) {
        return $default;
    }

    return trim($value);
}

function _dd_config_bool($value, $default)
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

function _dd_config_float($value, $default, $min = null, $max = null)
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

function _dd_config_json($value, $default)
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

function _dd_config_indexed_array($value, $default)
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

function _dd_config_associative_array($value, $default)
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

function dd_config_trace_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_ENABLED'), true);
}

function dd_config_debug_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_DEBUG'), false);
}

function dd_config_distributed_tracing_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_DISTRIBUTED_TRACING'), true);
}

function dd_config_analytics_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_ANALYTICS_ENABLED'), true);
}

function dd_config_priority_sampling_is_enabled()
{
    return \dd_config_analytics_is_enabled() && \_dd_config_bool(\dd_trace_env_config('DD_PRIORITY_SAMPLING'), true);
}

function dd_config_hostname_reporting_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME'), false);
}

function dd_config_url_resource_name_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME'), true);
}

function dd_config_http_client_split_by_domain_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN'), false);
}

function dd_config_sandbox_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_SANDBOX_ENABLED'), true);
}

function dd_config_autofinish_span_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_AUTOFINISH_SPANS'), false);
}

function dd_config_sampling_rate()
{
    return \_dd_config_float(\dd_trace_env_config('DD_TRACE_SAMPLE_RATE'), 1.0, 0.0, 1.0);
}

function dd_config_sampling_rules()
{
    $json = \_dd_config_json(\dd_trace_env_config('DD_TRACE_SAMPLING_RULES'), []);
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

function dd_config_integration_is_enabled($name)
{
    $disabled = \dd_config_disabled_integrations();
    return \dd_config_trace_is_enabled() && !in_array($name, $disabled);
}

function dd_config_integration_analytics_is_enabled($name)
{
    $integrationNameForEnv = strtoupper(str_replace('-', '_', trim($name)));
    return \_dd_config_bool(\dd_trace_env_config("DD_${integrationNameForEnv}_ANALYTICS_ENABLED"), false);
}

function dd_config_integration_analytics_sample_rate($name)
{
    $integrationNameForEnv = strtoupper(str_replace('-', '_', trim($name)));
    return \_dd_config_float(\dd_trace_env_config("DD_${integrationNameForEnv}_ANALYTICS_SAMPLE_RATE"), 1.0);
}

function dd_config_disabled_integrations()
{
    return \_dd_config_indexed_array(\dd_trace_env_config('DD_INTEGRATIONS_DISABLED'), []);
}

function dd_config_global_tags()
{
    return \_dd_config_associative_array(\dd_trace_env_config('DD_TRACE_GLOBAL_TAGS'), []);
}

function dd_config_service_mapping()
{
    return \_dd_config_associative_array(\dd_trace_env_config('DD_SERVICE_MAPPING'), []);
}

function dd_config_app_name($default = '')
{
    return \_dd_config_string(\dd_trace_env_config('DD_SERVICE_NAME'), $default);
}
