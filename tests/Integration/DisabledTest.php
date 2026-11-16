<?php

namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Common\CLITestCase;
use DDTrace\Tests\Common\SpanAssertion;

final class DisabledTest extends CLITestCase
{
    private $script = '';

    protected function getScriptLocation()
    {
        return __DIR__ . '/' . $this->script;
    }

    public function testDisablingCLI()
    {
        $this->script = 'LongRunning/long_running_script_manual.php';
        $agentRequest = $this->getAgentRequestFromCommand('', [
            'DD_TRACE_CLI_ENABLED' => 'false',
        ]);

        $this->assertEmpty($agentRequest);
    }
}