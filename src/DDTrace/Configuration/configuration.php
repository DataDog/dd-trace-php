<?php

namespace DDTrace\Configuration;

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
        $floatValue = (float) $value;
    } else {
        $floatValue = (float) $default;
    }

    if (null !== $min && $floatValue < $min) {
        $floatValue = $min;
    }

    if (null !== $max && $floatValue > $max) {
        $floatValue = $max;
    }

    return $floatValue;
}

function dd_config_analytics_is_enabled()
{
    return _dd_config_bool(\dd_trace_env_config('DD_TRACE_ANALYTICS_ENABLED'), true);
}

function dd_config_integration_analytics_is_enabled($name)
{
    $integrationNameForEnv = strtoupper(str_replace('-', '_', trim($name)));
    return _dd_config_bool(\dd_trace_env_config("DD_${integrationNameForEnv}_ANALYTICS_ENABLED"), false);
}

function dd_config_integration_analytics_sample_rate($name)
{
    $integrationNameForEnv = strtoupper(str_replace('-', '_', trim($name)));
    return _dd_config_float(\dd_trace_env_config("DD_${integrationNameForEnv}_ANALYTICS_SAMPLE_RATE"), 1.0);
}
