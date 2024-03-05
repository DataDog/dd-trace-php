<?php

namespace DDTrace\Tests\Unit\Util;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Util\Common;

final class OrphansTest extends IntegrationTestCase
{
    use TracerTestTrait;

    static function foo()
    {
        // no-op
    }

    protected function ddSetUp()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS=1',
            'DD_TRACE_GENERATE_ROOT_SPAN=0',
            'DD_TRACE_AUTO_FLUSH_ENABLED=1',
            'DD_TRACE_CURL_ENABLED=0'
        ]);
    }

    static function getSampling($response) {
        $root = json_decode($response[0]["body"], true);
        $spans = $root["chunks"][0]["spans"] ?? $root[0];
        return $spans[0]["metrics"]["_sampling_priority_v1"];
    }

    function testOrphansRemovalWithAgentSampling()
    {
        if (\extension_loaded('xdebug')) {
            $this->markTestSkipped('Request Replayer isn\'t run in debug test suites');
        }

        \DDTrace\trace_method("DDTrace\Tests\Unit\Util\CommonTest", "foo", function (\DDTrace\SpanData $span) {
            Common::handleOrphan($span);
        });

        $this->resetRequestDumper();

        $this->setResponse(["rate_by_service" => ["service:,env:" => 1]]);
        self::foo();
        usleep(333 * 2 * 1000); // DD_TRACE_AGENT_FLUSH_INTERVAL * 2 * 1000
        $response = $this->retrieveDumpedTraceData();
        $this->assertEquals(0, self::getSampling($response));

        $this->setResponse(["rate_by_service" => ["service:,env:" => 0]]);
        self::foo();
        usleep(333 * 2 * 1000);
        $response = $this->retrieveDumpedTraceData();
        $this->assertEquals(0, self::getSampling($response));

        $this->setResponse(["rate_by_service" => ["service:,env:" => 0.5]]);
        self::foo();
        usleep(333 * 2 * 1000);
        $response = $this->retrieveDumpedTraceData();
        $this->assertEquals(0, self::getSampling($response));
    }
}
