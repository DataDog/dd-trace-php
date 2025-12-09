<?php

use DDTrace\OpenTelemetry\Detectors\DetectorHelper;

\DDTrace\install_hook(
    'OpenTelemetry\SDK\Resource\Detectors\Service::getResource',
    null,
    function (\DDTrace\HookData $hook) {
        $attributes = [];

        $rootSpan = \DDTrace\root_span();
        if ($rootSpan) {
            $attributes['service.name'] = $rootSpan->service;
        } else {
            $appName = \ddtrace_config_app_name();
            if ($appName === '') {
                return;
            }
            $attributes['service.name'] = $appName;
        }

        DetectorHelper::mergeAttributes($hook, $attributes);
    });