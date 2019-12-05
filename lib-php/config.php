<?php

function dd_config_is_trace_analytics_enabled($name = null)
{
    if (is_string($name)) {
        return dd_config_is_trace_analytics_enabled()
            && _dd_config_bool('DD_' . strtoupper($name) . '_ANALYTICS_ENABLED', false);
    }
    return _dd_config_bool('DD_TRACE_ANALYTICS_ENABLED', false);
}

function dd_config_trace_analytics_sample_rate($name = null)
{
    if (is_string($name)) {
        return _dd_config_float('DD_' . strtoupper($name) . '_ANALYTICS_SAMPLE_RATE', 1.0);
    }
    return _dd_config_float('DD_TRACE_ANALYTICS_SAMPLE_RATE', 1.0);
}

function _dd_config_bool($name, $default)
{
    $found = getenv($name);
    if ($found === false) {
        return $default;
    }
    return in_array(strtolower($found), ['1', 'true', 'on']);
}

function _dd_config_float($name, $default)
{
    $found = getenv($name);
    if ($found === false) {
        return $default;
    }
    return floatval($found);
}
