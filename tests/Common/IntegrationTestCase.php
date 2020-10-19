<?php

namespace DDTrace\Tests\Common;

use DDTrace\Integrations\IntegrationsLoader;

/**
 * A basic class to be extended when testing integrations.
 */
abstract class IntegrationTestCase extends BaseTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    private $errorReportingBefore;

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        IntegrationsLoader::reload();
    }

    public static function ddTearDownAfterClass()
    {
        parent::ddTearDownAfterClass();
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function ddSetUp()
    {
        $this->errorReportingBefore = error_reporting();
        parent::ddSetUp();
    }

    protected function ddTearDown()
    {
        error_reporting($this->errorReportingBefore);
        if (PHPUNIT_MAJOR <= 5) {
            \PHPUnit_Framework_Error_Warning::$enabled = true;
        }
        \dd_trace_internal_fn('ddtrace_reload_config');
        parent::ddTearDown();
    }

    protected function disableTranslateWarningsIntoErrors()
    {
        if (PHPUNIT_MAJOR <= 5) {
            \PHPUnit_Framework_Error_Warning::$enabled = false;
        }
        error_reporting(E_ERROR | E_PARSE);
    }

    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param array[] $traces
     * @param SpanAssertion[] $expectedSpans
     */
    public function assertSpans($traces, $expectedSpans)
    {
        $this->assertExpectedSpans($traces, $expectedSpans);
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
