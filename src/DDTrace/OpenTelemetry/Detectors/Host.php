<?php

use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;

\DDTrace\install_hook(
    'OpenTelemetry\SDK\Resource\Detectors\Host::getResource',
    null,
    function (\DDTrace\HookData $hook) {
        $attributes = [];

        if (\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME')) {
            $attributes['host.name'] = \dd_trace_env_config('DD_HOSTNAME');
        }

        $builder = (new AttributesFactory)->builder($attributes);
        $newResource = ResourceInfo::create($builder->build());
        $resource = $hook->returned;
        $resource = $resource->merge($newResource);
        $hook->overrideReturnValue($resource);
    });