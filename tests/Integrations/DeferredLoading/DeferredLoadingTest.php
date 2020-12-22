<?php

namespace DDTrace\Tests\Integrations\DeferredLoading;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use PHPUnit\Framework\TestCase;

final class DeferredLoadingTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/index.php';
    }

    public function testCanDeferLoadMultipleIntegrations()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/'));
            TestCase::assertSame('OK', $response);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('web.request')
                ->withChildren([
                    SpanAssertion::exists('PDO.__construct'),
                    SpanAssertion::exists('PDO.prepare'),
                    SpanAssertion::exists('PDOStatement.execute'),
                    SpanAssertion::exists('Predis.Client.__construct'),
                    SpanAssertion::exists('Predis.Client.executeCommand'),
                ]),
        ]);
    }
}
