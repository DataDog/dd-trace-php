<?php

namespace DDTrace\Tests\Common;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Util\Versions;
use PHPUnit\Framework\TestCase;

/**
 * A basic class to be extended when testing integrations.
 */
abstract class IntegrationTestCase extends TestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    const IS_SANDBOX = false;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        if (!static::isSandboxed()) {
            putenv('DD_TRACE_SANDBOX_ENABLED=false');
        }
        IntegrationsLoader::reload();
    }

    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        putenv('DD_TRACE_SANDBOX_ENABLED');
    }

    protected static function isSandboxed()
    {
        return static::IS_SANDBOX === true;
    }

    protected function setUp()
    {
        parent::setUp();
        if (Versions::phpVersionMatches('5.4') && self::isSandboxed()) {
            $this->markTestSkipped('Sandboxed tests are skipped on PHP 5.4.');
        }
    }

    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param array[] $traces
     * @param SpanAssertion[] $expectedSpans
     * @param bool $isSandbox
     */
    public function assertSpans($traces, $expectedSpans, $isSandbox = null)
    {
        $isSandbox = null === $isSandbox ? self::isSandboxed() : $isSandbox;
        $this->assertExpectedSpans($traces, $expectedSpans, $isSandbox);
    }

    /**
     * Checks that the provide span exists in the provided traces and matches expectations.
     *
     * @param array[] $traces
     * @param SpanAssertion $expectedSpan
     */
    public function assertOneSpan($traces, SpanAssertion $expectedSpan)
    {
        $this->assertOneExpectedSpan($traces, $expectedSpan);
    }
}
