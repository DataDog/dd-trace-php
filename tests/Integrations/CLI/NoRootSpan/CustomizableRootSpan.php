<?php

namespace DDTrace\Tests\Integrations\CLI\NoRootSpan;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Integrations\CLI\CLITestCase;

class CustomizableRootSpan extends CLITestCase
{
    const IS_SANDBOX = true;

    protected function getScriptLocation()
    {
        return __DIR__ . '/sample_script.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'no_root_span_app',
            'DD_TRACE_NO_AUTOLOADER' => 'true',
            'DD_TRACE_ENTRY_POINTS' => 'dd_test_dummy_function',
        ]);
    }

    protected static function getInis()
    {
        return array_merge(parent::getInis(), [
            'error_log' => __DIR__ . '/error.log',
        ]);
    }


    public function testCommandWithNoArguments()
    {
        $traces = $this->getTracesFromCommand();
        error_log('Traces' . print_r($traces, 1));

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'laravel.artisan',
                'artisan_test_app',
                'cli',
                'artisan'
            )->withExactTags([
                'integration.name' => 'laravel',
            ])
        ]);
    }
}
