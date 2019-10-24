<?php

namespace DDTrace\Tests\Integrations\CLI\NoAutoloader;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Integrations\CLI\CLITestCase;

final class NoAutoloaderTestCase extends CLITestCase
{
    /**
     * {@inheritDoc}
     */
    protected function getScriptLocation()
    {
        return __DIR__ . '/test-script.php';
    }

    public function testCliCommandWithoutAutoloaderByDefaultNoTraces()
    {
        $traces = $this->getTracesFromCommand();

        $this->assertEmpty($traces);
    }

    public function testCliCommandNoAutoloaderEnabledByEnv()
    {
        $traces = $this->getTracesFromCommand('', [
            'DD_TRACE_NO_AUTOLOADER' => 'true',
        ]);

        $this->assertSpans($traces, [
            SpanAssertion::exists('test-script.php'),
        ]);
    }
}
