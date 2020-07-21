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

    private $errorReportingBefore;

    const IS_SANDBOX = false;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        if (!static::isSandboxed()) {
            \putenv('DD_TRACE_SANDBOX_ENABLED=false');
            \dd_trace_internal_fn('ddtrace_reload_config');
        }
        IntegrationsLoader::reload();
    }

    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        putenv('DD_TRACE_SANDBOX_ENABLED');
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected static function isSandboxed()
    {
        return static::IS_SANDBOX === true;
    }

    protected function setUp()
    {
        $this->errorReportingBefore = error_reporting();
        parent::setUp();
        if (Versions::phpVersionMatches('5.4') && self::isSandboxed()) {
            $this->markTestSkipped('Sandboxed tests are skipped on PHP 5.4.');
        }
        if (Versions::phpVersionMatches('5.6') && !self::isSandboxed()) {
            $this->markTestSkipped("PHP 5.6 does not support the legacy API");
        }
    }

    protected function tearDown()
    {
        parent::tearDown();
        error_reporting($this->errorReportingBefore);
        \PHPUnit_Framework_Error_Warning::$enabled = true;
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function disableTranslateWarningsIntoErrors()
    {
        \PHPUnit_Framework_Error_Warning::$enabled = false;
        error_reporting(E_ERROR | E_PARSE);
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
