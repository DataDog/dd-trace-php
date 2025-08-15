<?php

namespace DDTrace\Tests\Integrations\CLI\Custom\Autoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

final class NoRootSpanTest extends CLITestCase
{
    protected function ddSetUp()
    {
        parent::ddSetUp();
    }

    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Custom/Version_Autoloaded/no-root-span';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_GENERATE_ROOT_SPAN' => '0',
            'DD_TRACE_AUTO_FLUSH_ENABLED' => '1',
            'DD_TRACE_SANDBOX_ENABLED' => '1',
        ]);
    }

    public function testCommandWillAutoFlush()
    {
        $traces = $this->getTracesFromCommand();

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'my_app',
                'foo_service',
                'custom',
                'foo_resource'
            )->withExactTags([
                'foo' => 'bar',
                '_dd.code_origin.frames.0.method' => 'my_app',
                '_dd.code_origin.type' => 'entry',
                '_dd.code_origin.frames.1.line' => '30',
                '_dd.code_origin.frames.1.file' => '%s',
                '_dd.code_origin.frames.0.file' => '%s',
                '_dd.code_origin.frames.0.line' => '6',
            ])->withChildren([
                SpanAssertion::exists(
                    'mysqli_connect',
                    'mysqli_connect'
                ),
                SpanAssertion::exists(
                    'curl_exec',
                    'http://' . HTTPBIN_SERVICE_HOST . '/status/?'
                ),
            ])
        ]);
    }
}
