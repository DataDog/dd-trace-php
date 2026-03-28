<?php

use DDTrace\OpenTelemetry\Detectors\DetectorHelper;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;

\DDTrace\install_hook(
    'OpenTelemetry\SDK\Resource\Detectors\Host::getResource',
    null,
    function (\DDTrace\HookData $hook) {
        if (\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME')) {
            $ddHostname = \dd_trace_env_config('DD_HOSTNAME');
            if ($ddHostname !== '') {
                DetectorHelper::mergeAttributes($hook, ['host.name' => $ddHostname]);
            }
            return;
        }

        // DD_TRACE_REPORT_HOSTNAME is not set — strip auto-detected host.name so it
        // doesn't appear in logs unless explicitly set in OTEL_RESOURCE_ATTRIBUTES.
        $filtered = [];
        foreach ($hook->returned->getAttributes() as $key => $value) {
            if ($key !== 'host.name') {
                $filtered[$key] = $value;
            }
        }
        $builder = (new AttributesFactory())->builder($filtered);
        $hook->overrideReturnValue(ResourceInfo::create($builder->build(), $hook->returned->getSchemaUrl()));
    });
