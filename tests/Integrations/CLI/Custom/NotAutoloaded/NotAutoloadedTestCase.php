<?php

namespace DDTrace\Tests\Integrations\CLI\Custom\NotAutoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Integrations\CLI\CLITestCase;

final class NotAutoloadedTestCase extends CLITestCase
{
    // this doesn't use the sandbox API, but it doesn't use legacy API either
    const IS_SANDBOX = true;

    /**
     * {@inheritDoc}
     */
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Custom/Version_Not_Autoloaded/cli.php';
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
