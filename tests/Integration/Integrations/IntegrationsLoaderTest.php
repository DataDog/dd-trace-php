<?php

namespace DDTrace\Tests\Integration\Integrations;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Unit\BaseTestCase;

final class IntegrationsLoaderTest extends BaseTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    private static $inline = '
        $client = new \Memcached();
        $client->addServer("memcached_integration", "11211");
        $client->get("key");
    ';

    public function testIntegrationsAreLoadedByDefault()
    {
        $traces = $this->traceCliScriptInline(self::$inline, []);
        $this->assertOneExpectedSpan($traces, SpanAssertion::exists('Memcached.get'));
    }

    public function testGlobalConfigCanDisableLoading()
    {
        $traces = $this->traceCliScriptInline(self::$inline, [ 'DD_TRACE_ENABLED' => false ]);
        $this->assertSpanNotExist($traces, SpanAssertion::exists('Memcached.get'));
    }

    public function testSingleIntegrationLoadingCanBeDisabled()
    {
        $traces = $this->traceCliScriptInline(self::$inline, [ 'DD_INTEGRATIONS_DISABLED' => 'memcached' ]);
        $this->assertSpanNotExist($traces, SpanAssertion::exists('Memcached.get'));
    }
}
