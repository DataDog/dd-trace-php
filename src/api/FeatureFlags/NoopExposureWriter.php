<?php

namespace DDTrace\FeatureFlags;

final class NoopExposureWriter implements ExposureWriter
{
    public function write(array $event)
    {
    }

    public function flush()
    {
    }
}
