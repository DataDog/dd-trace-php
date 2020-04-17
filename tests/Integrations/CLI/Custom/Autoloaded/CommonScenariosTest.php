<?php

namespace DDTrace\Tests\Integrations\CLI\Custom\Autoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Integrations\CLI\CLITestCase;

final class CommonScenariosTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Custom/Version_Autoloaded/run';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'console_test_app',
        ]);
    }

    public function testCommandWithNoArguments()
    {
        $traces = $this->getTracesFromCommand();

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'run',
                'console_test_app',
                'cli',
                'run'
            )->withExactTags([
                'integration.name' => 'web',
            ])
        ]);
    }
}
