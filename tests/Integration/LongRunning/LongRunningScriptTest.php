<?php

namespace DDTrace\Tests\Integration\LongRunning;

use DDTrace\Tests\Common\CLITestCase;

final class LongRunningScriptTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/long_running_script_manual.php';
    }

    public function testMultipleTracesFromLongRunningScriptSetCorrectTraceCountHeader()
    {
        if (5 === \PHP_MAJOR_VERSION) {
            $this->markTestSkipped('We do not officially support and test long running scripts on PHP 5');
            return;
        }
        $agentRequest = $this->getAgentRequestFromCommand('', [
            'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
            'DD_TRACE_BGS_TIMEOUT' => 3000,
        ]);

        $this->assertSame('3', $agentRequest[0]['headers']['X-Datadog-Trace-Count']);
        $this->assertCount(3, json_decode($agentRequest[0]['body'], true));
    }
}
