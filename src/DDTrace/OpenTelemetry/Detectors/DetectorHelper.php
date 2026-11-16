<?php

namespace DDTrace\OpenTelemetry\Detectors;

use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;

class DetectorHelper
{
    /**
     * Merges additional attributes into the resource returned by a detector hook.
     *
     * @param \DDTrace\HookData $hook The hook data containing the returned resource
     * @param array<string, mixed> $attributes Attributes to merge into the resource
     */
    public static function mergeAttributes(\DDTrace\HookData $hook, array $attributes): void
    {
        if (empty($attributes)) {
            return;
        }

        $builder = (new AttributesFactory())->builder($attributes);
        $newResource = ResourceInfo::create($builder->build());
        $mergedResource = $hook->returned->merge($newResource);

        $hook->overrideReturnValue($mergedResource);
    }
}

