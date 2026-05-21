<?php

namespace DDTrace\FeatureFlags;

interface ExposureWriter
{
    /**
     * @param array<string, mixed> $event
     * @return void
     */
    public function write(array $event);

    /**
     * @return void
     */
    public function flush();
}
