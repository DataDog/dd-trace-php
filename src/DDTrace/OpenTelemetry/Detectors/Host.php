<?php

use DDTrace\OpenTelemetry\Detectors\DetectorHelper;

\DDTrace\install_hook(
    'OpenTelemetry\SDK\Resource\Detectors\Host::getResource',
    null,
    function (\DDTrace\HookData $hook) {
        $attributes = [];

        if (\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME')) {
            $ddHostname = \dd_trace_env_config('DD_HOSTNAME');
            // Only override if DD_HOSTNAME is explicitly set to avoid
            // clobbering the hostname detected by OTel's Host detector
            if ($ddHostname !== '') {
                $attributes['host.name'] = $ddHostname;
            }
        }

        DetectorHelper::mergeAttributes($hook, $attributes);
    });