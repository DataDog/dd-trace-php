<?php

namespace DDTrace\Tests\Integrations\APCu;

use DDTrace\Obfuscation;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Util\Versions;

class APCuTest extends IntegrationTestCase
{
    const IS_SANDBOX = false;

    protected function setUp()
    {
        parent::setUp();
        $this->isolateTracer(function () {
            // Cleaning up existing data from previous tests
            apcu_clear_cache();
        });
    }

    public function testAdd()
    {
        $traces = $this->isolateTracer(function () {
            apcu_add('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('APCu.apcu_add', 'apcu', 'apcu', 'apcu_add')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'apcu.query' => 'apcu_add ' . Obfuscation::toObfuscatedString('key'),
                    'apcu.command' => 'apcu_add',
                ]),
        ]);
    }
}