<?php

namespace DDTrace;

use DDTrace\Configuration\AbstractConfiguration;

/**
 * DDTrace global configuration object.
 */
class Configuration extends AbstractConfiguration
{
    /**
     * Whether or not distributed tracing is enabled globally.
     *
     * @return bool
     */
    public function isDistributedTracingEnabled()
    {
        return $this->registry->boolValue('distributed.tracing', true);
    }

    /**
     * Whether or not priority sampling is enabled globally.
     *
     * @return bool
     */
    public function isPrioritySamplingEnabled()
    {
        return $this->isDistributedTracingEnabled()
            && $this->registry->boolValue('priority.sampling', true);
    }
}
