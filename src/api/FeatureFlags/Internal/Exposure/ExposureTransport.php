<?php

namespace DDTrace\FeatureFlags\Internal\Exposure;

interface ExposureTransport
{
    /**
     * @param array<string, mixed> $payload
     * @return bool
     */
    public function send(array $payload);
}
