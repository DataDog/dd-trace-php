<?php

namespace DDTrace\Tests\Integrations\PHPRedis;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Util\Versions;
use Predis\Configuration\Options;

class PHPRedisTest extends IntegrationTestCase
{
    private $host = 'redis_integration';
    private $port = '6379';

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        IntegrationsLoader::load();
    }

    public function testPredisIntegrationCreatesSpans()
    {
        $traces = $this->isolateTracer(function () {
            $redis = new \Redis();
            $redis->connect($this->host);
        });
        error_log('Traces' . print_r($traces, 1));

        $this->assertFlameGraph($traces, [
        ]);
    }
}
