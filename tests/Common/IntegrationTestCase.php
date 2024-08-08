<?php

namespace DDTrace\Tests\Common;

use DDTrace\NoopTracer;

/**
 * A basic class to be extended when testing integrations.
 */
abstract class IntegrationTestCase extends BaseTestCase
{
    use TracerTestTrait;
    use SnapshotTestTrait;
    use SpanAssertionTrait;

    private $errorReportingBefore;
    public static $autoloadPath = null;

    public static $database = "test";
    private static $createdDatabases = ["test" => true];

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();

        $exts = get_loaded_extensions(false);
        $csv = '';
        foreach ($exts as $ext) {
            $csv = $csv . "ext-" . $ext . ";" . phpversion($ext) . "\n";
        }

        $zendExts = get_loaded_extensions(true);
        foreach ($zendExts as $ext) {
            $csv = $csv . "ext-" . $ext . ";" . phpversion($ext) . "\n";
        }

        $artifactsDir = "/tmp/artifacts";
        if ( !file_exists( $artifactsDir ) && !is_dir( $artifactsDir ) ) {
            mkdir($artifactsDir, 0777, true);
        }

        file_put_contents($artifactsDir . "/extension_versions.csv", $csv, FILE_APPEND);

        $csv = '';

        if (self::$autoloadPath && file_exists(dirname(self::$autoloadPath). "/composer/installed.json")) {
            $data = json_decode(file_get_contents(dirname(self::$autoloadPath). "/composer/installed.json"), true);
            foreach ($data['packages'] as $package) {
                $csv = $csv . $package['name'] . ";" . $package['version'] . "\n";
            }
        }

        file_put_contents($artifactsDir . "/composer_versions.csv", $csv, FILE_APPEND);

        if (isset(static::$database) && !isset(self::$createdDatabases[static::$database])) {
            $pdo = new \PDO('mysql:host=mysql_integration', 'test', 'test');
            $pdo->exec("CREATE DATABASE IF NOT EXISTS " . static::$database);
            self::$createdDatabases[static::$database] = true;
        }
    }

    public static function ddTearDownAfterClass()
    {
        parent::ddTearDownAfterClass();
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function ddSetUp()
    {
        $this->errorReportingBefore = error_reporting();
        $this->resetTracer(); // Needs reset so we can remove root span
        $this->putEnv("DD_TRACE_GENERATE_ROOT_SPAN=0");
        parent::ddSetUp();
    }

    protected function ddTearDown()
    {
        error_reporting($this->errorReportingBefore);
        if (PHPUNIT_MAJOR <= 5) {
            \PHPUnit_Framework_Error_Warning::$enabled = true;
        }
        \dd_trace_internal_fn('ddtrace_reload_config');
        \DDTrace\GlobalTracer::set(new NoopTracer());
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
    public function assertSpans($traces, $expectedSpans, $applyDefaults = true)
    {
        $this->assertExpectedSpans($traces, $expectedSpans, $applyDefaults);
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
