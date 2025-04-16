<?php

namespace DDTrace\Tests\Unit\Util;

use DDTrace\Integrations\Integration;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\TracerTestTrait;

final class OrphansTest extends IntegrationTestCase
{
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
        \DDTrace\trace_method(self::class, "foo", function (\DDTrace\SpanData $span) {
            Integration::handleOrphan($span);
        });

        $this->resetRequestDumper();

        $this->setResponse(["rate_by_service" => ["service:,env:" => 1]]);
        self::foo();
        if (\dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
            \dd_trace_synchronous_flush();
        } else {
            usleep(333 * 2 * 1000); // DD_TRACE_AGENT_FLUSH_INTERVAL * 2 * 1000
        }
        $response = $this->retrieveDumpedTraceData();
        $this->assertEquals(0, self::getSampling($response));

        $this->setResponse(["rate_by_service" => ["service:,env:" => 0]]);
        self::foo();
        if (\dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
            \dd_trace_synchronous_flush();
        } else {
            usleep(333 * 2 * 1000); // DD_TRACE_AGENT_FLUSH_INTERVAL * 2 * 1000
        }
        $response = $this->retrieveDumpedTraceData();
        $this->assertEquals(0, self::getSampling($response));

        $this->setResponse(["rate_by_service" => ["service:,env:" => 0.5]]);
        self::foo();
        if (\dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
            \dd_trace_synchronous_flush();
        } else {
            usleep(333 * 2 * 1000); // DD_TRACE_AGENT_FLUSH_INTERVAL * 2 * 1000
        }
        $response = $this->retrieveDumpedTraceData();
        $this->assertEquals(0, self::getSampling($response));
    }
}
