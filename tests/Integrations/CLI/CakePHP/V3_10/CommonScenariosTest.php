<?php

namespace DDTrace\Tests\Integrations\CLI\CakePHP\V3_10;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

class CommonScenariosTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/CakePHP/Version_3_10/bin/cake.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'cake_console_test_app',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            'DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS' => 1,
        ]);
    }

    public function testCommandWithNoArguments()
    {
        $this->resetRequestDumper();

        $traces = $this->getTracesFromCommand();

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'cakephp.console',
                'cake_console_test_app',
                'cli',
                'cake_console'
            )->withExactTags([
                Tag::COMPONENT => 'cakephp',
            ])
        ]);
    }

    public function testCommandWithArgument()
    {
        $this->resetRequestDumper();

        $traces = $this->getTracesFromCommand('routes');

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'cakephp.console',
                'cake_console_test_app',
                'cli',
                'cake_console routes'
            )->withExactTags([
                Tag::COMPONENT => 'cakephp',
            ])
        ]);
    }
}
