<?php

namespace DDTrace\Integrations\PHPRedis;

use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class PHPRedisSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'phpredis';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to PDO requests
     */
    public function init()
    {
        $integration = $this;
        error_log('loading....');

        \DDTrace\trace_method('Redis', 'connect', function (SpanData $span, $args) {
            error_log('Hello!');
        });

        return SandboxedIntegration::LOADED;
    }
}
