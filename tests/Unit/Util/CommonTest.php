<?php

namespace DDTrace\Tests\Unit\Util;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\MultiPHPUnitVersionAdapter;
use DDTrace\Tests\Common\SnapshotTestTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\Utils;
use DDTrace\Util\Common;

final class CommonTest extends IntegrationTestCase
{
    use SnapshotTestTrait;
    use TracerTestTrait;

    static function foo()
    {
        // no-op
    }

    protected function ddSetUp()
    {
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS=1',
            'DD_TRACE_GENERATE_ROOT_SPAN=0',
            'DD_TRACE_DEBUG=1',
            'DD_TRACE_AUTO_FLUSH_ENABLED=1',
            'DD_TRACE_AGENT_FLUSH_INTERVAL=333',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED=0',
            'DD_TRACE_AGENT_RETRIES=3',
            'DD_TRACE_AGENT_URL=http://request-replayer:80',
            'DD_TRACE_AGENT_PORT=80'
        ]);
    }

    function testOrphansRemovalWithAgentSampling()
    {
        \DDTrace\trace_method("DDTrace\Tests\Unit\Util\CommonTest", "foo", function (\DDTrace\SpanData $span) {
            Common::handleOrphan($span);
        });

        $this->resetRequestDumper();

        $this->setResponse(["rate_by_service" => ["service:,env:" => 0]]);
        CommonTest::foo();
        usleep(5000 * 2 * 1000);
        $response = $this->retrieveDumpedTraceData();
        echo json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL;

        $this->setResponse(["rate_by_service" => ["service:,env:" => 0]]);
        CommonTest::foo();
        usleep(5000 * 2 * 1000);
        $response = $this->retrieveDumpedTraceData();
        echo json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL;

        $this->setResponse(["rate_by_service" => ["service:,env:" => 1]]);
        CommonTest::foo();
        usleep(5000 * 2 * 1000);
        $response = $this->retrieveDumpedTraceData();
        echo json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL;
    }
}
