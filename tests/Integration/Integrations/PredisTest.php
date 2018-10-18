<?php

namespace DDTrace\Tests\Integration\Integrations;

use DDTrace\Encoders\Json;
// use DDTrace\Tests\RequestReplayer;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
// use DDTrace\Version;
use DDTrace\Integrations\Predis;

// use Predis\Client;
use PHPUnit\Framework;
// use Prophecy\Argument;
// use Psr\Log\LoggerInterface;
use OpenTracing\GlobalTracer;


final class PredisTest extends Framework\TestCase
{
    public static function setUpBeforeClass()
    {
        $tracer = new Tracer(new Http(new Json()));
        GlobalTracer::set($tracer);

        Predis::load();
    }

    public function redisHostname(){
        return $_SERVER["REDIS_HOSTNAME"] ? $_SERVER["REDIS_HOSTNAME"] : 'localhost';
    }

    public function testPredisWorks()
    {
        $client = new \Predis\Client([ host => $this->redisHostname() ]);

        $client->set('foo', 'bar');
        $this->assertEquals($client->get('foo'), 'bar');
        return;
    }
}
