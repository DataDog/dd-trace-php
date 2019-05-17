<?php

namespace DDTrace\Tests\Integrations\CLI\CakePHP\V2_8;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Integrations\CLI\CLITestCase;

final class CommonScenariosTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/CakePHP/Version_2_8/app/Console/cake.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'cake_console_test_app',
        ]);
    }

    public function testCommandWithNoArguments()
    {
        $traces = $this->getTracesFromCommand();

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'cakephp.console',
                'cake_console_test_app',
                'cli',
                'cake_console'
            )->withExactTags([
                'integration.name' => 'cakephp',
            ])
        ]);
    }

    public function testCommandWithArgument()
    {
        $traces = $this->getTracesFromCommand('command_list');

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'cakephp.console',
                'cake_console_test_app',
                'cli',
                'cake_console command_list'
            )->withExactTags([
                'integration.name' => 'cakephp',
            ])
        ]);
    }

    // We can uncomment this when we auto-trace exceptions and errors
    /*
    public function testCommandWithError()
    {
        // This error generates a lot of output to the CLI so we mute it
        $this->setOutputCallback(function() {});

        $traces = $this->getTracesFromCommand('foo_error');

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'cakephp.console',
                'cake_console_test_app',
                'cli',
                'cake_console foo_error'
            )->withExactTags([
                'integration.name' => 'cakephp',
            ])->withExistingTagsNames([
                'error.msg',
                'error.stack'
            ])->setError()
        ]);
    }
    */
}
