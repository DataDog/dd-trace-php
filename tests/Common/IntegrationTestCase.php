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
    use TracerTestTrait, SpanAssertionTrait;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        if (!self::is_sandboxed()) {
            putenv('DD_TRACE_SANDBOX_ENABLED=false');
            IntegrationsLoader::load();
        }
    }

    protected static function is_sandboxed()
    {
        return !defined('static::IS_SANDBOXED') || static::IS_SANDBOXED === true;
    }

    protected function setUp()
    {
        parent::setUp();
        if (Versions::phpVersionMatches('5.4') && self::is_sandboxed()) {
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
    public function assertSpans($traces, $expectedSpans, $isSandbox = false)
    {
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
