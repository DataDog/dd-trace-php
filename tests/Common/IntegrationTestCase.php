<?php

namespace DDTrace\Tests\Common;

use DDTrace\NoopTracer;

/**
 * A basic class to be extended when testing integrations.
 * @retryAttempts 3
 */
abstract class IntegrationTestCase extends BaseTestCase
{
    use TracerTestTrait;
    use SnapshotTestTrait;
    use SpanAssertionTrait;
    use RetryTrait;

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
            $pdo = new \PDO('mysql:host=mysql-integration', 'test', 'test');
            $pdo->exec("CREATE DATABASE IF NOT EXISTS " . static::$database);
            self::$createdDatabases[static::$database] = true;
        }
    }

    public static function ddTearDownAfterClass()
    {
        parent::ddTearDownAfterClass();
        static::recordTestedVersion();
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

    public static function getTestedLibrary()
    {
        $file = (new \ReflectionClass(get_called_class()))->getFileName();
        $composer = null;

        if (file_exists(dirname($file) . '/composer.json')) {
            $composer = dirname($file) . '/composer.json';
        }

        if (!$composer) {
            return null;
        }

        $composerData = json_decode(file_get_contents($composer), true);
        return key($composerData['require']);
    }

    protected static function recordTestedVersion()
    {
        $testedLibrary = static::getTestedLibrary();
        if (empty($testedLibrary)) {
            return;
        }

        $version = static::getTestedVersion($testedLibrary);

        if (empty($version)) {
            return;
        }

        static::recordVersion($testedLibrary, $version);
    }

    protected static function getTestedVersion($testedLibrary)
    {
        $version = null;

        if (strpos($testedLibrary, "ext-") === 0) {
            $testedLibrary = substr($testedLibrary, 4); // strlen("ext-") => 4
            if (extension_loaded($testedLibrary)) {
                $version = phpversion($testedLibrary);
            } else {
                $output = [];
                $command = "php -dextension=$testedLibrary.so -r \"echo phpversion('$testedLibrary');\";";
                exec($command, $output, $returnVar);

                if ($returnVar === 0) {
                    $version = trim($output[0]);
                }
            }
        } elseif (($workingDir = static::getAppIndexScript()) || ($workingDir = static::getConsoleScript())) {
            do {
                $workingDir = dirname($workingDir);
                $composer = $workingDir . '/composer.json';
            } while (!file_exists($composer) && basename($workingDir) !== 'tests'); // there is no reason to go further up

            if (!file_exists($composer)) {
                return null;
            }

            $output = [];
            $command = "composer show $testedLibrary --working-dir=$workingDir | sed -n '/versions/s/^[^0-9]\+\([^,]\+\).*$/\\1/p'";
            exec($command, $output, $returnVar);

            if ($returnVar === 0) {
                $version = trim($output[0]);
            }
        } elseif (\Composer\InstalledVersions::isInstalled($testedLibrary)) {
            $version = \Composer\InstalledVersions::getVersion($testedLibrary);
            $version = preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version);
        }

        return $version ? preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version) : null;
    }

    protected static function recordVersion($testedLibrary, $version)
    {
        $projectRoot = __DIR__ . "/../..";
        $testsRoot = "$projectRoot/tests";
        $class = \get_called_class();
        echo "Recording tested version $version of $testedLibrary for $class\n";
        $class = preg_replace('/\\\/', '_', $class);
        $testedVersionFile = "$testsRoot/tested_versions/$class.json";
        if (!file_exists(dirname($testedVersionFile))) {
            mkdir(dirname($testedVersionFile), 0777, true);
        }
        $testedVersionJson = json_encode([$testedLibrary => $version], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($testedVersionFile, $testedVersionJson);
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

    public static function getConsoleScript()
    {
        return null;
    }

    /**
     * Returns the application index.php file full path.
     *
     * @return string|null
     */
    public static function getAppIndexScript()
    {
        return null;
    }
}
