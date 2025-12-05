<?php

use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;

\DDTrace\install_hook(
    'OpenTelemetry\SDK\Resource\Detectors\Environment::getResource',
    null,
    function (\DDTrace\HookData $hook) {
        $attributes = [];

        if (\dd_trace_env_config('DD_ENV')!='') {
            $attributes['deployment.environment.name'] = \dd_trace_env_config('DD_ENV');
        }

        if (\dd_trace_env_config('DD_VERSION') != '') {
            $attributes['service.version'] = \dd_trace_env_config('DD_VERSION');
        }

        foreach (\dd_trace_env_config('DD_TAGS') as $key => $value) {
            $attributes[$key] = $value;
        }

        $builder = (new AttributesFactory)->builder($attributes);
        $newResource = ResourceInfo::create($builder->build());
        $resource = $hook->returned;
        $resource = $resource->merge($newResource);
        $hook->overrideReturnValue($resource);
    });