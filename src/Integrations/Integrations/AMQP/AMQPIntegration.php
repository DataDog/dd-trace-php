<?php

namespace DDTrace\Integrations\AMQP\V3_5;

use DDTrace\Integrations\Integration;

class AMQPIntegration extends Integration
{
    const NAME = 'amqp';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to AMQP requests
     */
    public function init()
    {
        $integration = $this;

        return Integration::LOADED;
    }
}
