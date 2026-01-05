<?php

use DDTrace\OpenTelemetry\Detectors\DetectorHelper;

\DDTrace\install_hook(
    'OpenTelemetry\SDK\Resource\Detectors\Environment::getResource',
    null,
    function (\DDTrace\HookData $hook) {
        $attributes = [];

        $ddEnv = \dd_trace_env_config('DD_ENV');
        if ($ddEnv !== '') {
            $attributes['deployment.environment.name'] = $ddEnv;
        }

        $ddVersion = \dd_trace_env_config('DD_VERSION');
        if ($ddVersion !== '') {
            $attributes['service.version'] = $ddVersion;
        }

        foreach (\dd_trace_env_config('DD_TAGS') as $key => $value) {
            $attributes[$key] = $value;
        }

        DetectorHelper::mergeAttributes($hook, $attributes);
    });