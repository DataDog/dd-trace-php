<?php

use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;

\DDTrace\install_hook(
    'OpenTelemetry\SDK\Resource\Detectors\Service::getResource',
    null,
    function (\DDTrace\HookData $hook) {
        $attributes = [];

        $rootSpan = \DDTrace\root_span();
        if ($rootSpan) {
            $attributes['service.name'] = $rootSpan->service;
        } else {
            if (ddtrace_config_app_name() === '') {
                return;
            }
            $attributes['service.name'] = \ddtrace_config_app_name();
        }
        
        $builder = (new AttributesFactory)->builder($attributes);
        $newResource = ResourceInfo::create($builder->build());
        $resource = $hook->returned;
        $resource = $resource->merge($newResource);
        $hook->overrideReturnValue($resource);
    });