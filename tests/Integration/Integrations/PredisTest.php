<?php

namespace DDTrace\Tests\Integration\Integrations;

use DDTrace\Encoders\Json;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Transport\Stream;
use DDTrace\Integrations\Predis;
use DDTrace\Tests\DebugTransport;

use PHPUnit\Framework;
use OpenTracing\GlobalTracer;

final class PredisTest extends Framework\TestCase
{
    public static function setUpBeforeClass()
    {
        Predis::load();
    }

    /**
     * @var DDTrace\Test\DebugTransport
     */
    private $transport;

    public function setUp(){
        $this->transport = new DebugTransport();
        GlobalTracer::set(new Tracer($this->transport));
    }

    public function redisHostname(){
        return $_SERVER["REDIS_HOSTNAME"] ? $_SERVER["REDIS_HOSTNAME"] : 'localhost';
    }

    public function testPredisWorks()
    {
        $client = new \Predis\Client([ "host" => $this->redisHostname() ]);

        $client->set('foo', 'bar');
        $this->assertEquals($client->get('foo'), 'bar');

        GlobalTracer::get()->flush();
        $this->assertEquals($this->transport->getTraces(), []);
    }
}
