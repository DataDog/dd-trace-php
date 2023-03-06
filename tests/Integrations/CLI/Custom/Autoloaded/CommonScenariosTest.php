<?php

namespace DDTrace\Tests\Integrations\CLI\Custom\Autoloaded;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

final class CommonScenariosTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Custom/Version_Autoloaded/run';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'console_test_app',
        ]);
    }

    public function testCommandWithNoArguments()
    {
        $traces = $this->getTracesFromCommand();

        $expectedSpan = SpanAssertion::build(
            'run',
            'console_test_app',
            'cli',
            'run'
        );

        if (PHP_MAJOR_VERSION >= 8) {
            $expectedSpan->withExactTags([
                Tag::COMPONENT => 'lumen',
                Tag::SPAN_KIND => 'server'
            ]);
        }

        $this->assertSpans($traces, [$expectedSpan]);
    }
}
