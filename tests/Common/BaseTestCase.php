<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tests\Common\MultiPHPUnitVersionAdapter;
use DDTrace\Log\Logger;
use DDTrace\Tests\DebugLogger;
use DDTrace\Util\Versions;

/**
 * @method void assertFileDoesNotExist(string $file)
 * @method void assertEmpty(array $arr)
 * @method void assertNotEmpty(array $arr)
 */
abstract class BaseTestCase extends MultiPHPUnitVersionAdapter
{
    public static function ddSetUpBeforeClass()
    {
    }

    public static function ddTearDownAfterClass()
    {
    }

    protected function ddSetUp()
    {
    }

    protected function ddTearDown()
    {
        \Mockery::close();
        Logger::reset();
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function matchesPhpVersion($version)
    {
        return Versions::phpVersionMatches($version);
    }

    /**
     * Sets and return a debug logger which accumulates log messages.
     * @return DebugLogger
     */
    protected function withDebugLogger()
    {
        $logger = new DebugLogger();
        Logger::set($logger);
        return $logger;
    }

    protected static function putEnv($putenv)
    {
        if (strpos($putenv, "DD_") === 0 && PHP_VERSION_ID >= 80000) {
            $val = explode("=", $putenv, 2);
            $name = strtolower(strtr($val[0], [
                "DD_TRACE_" => "datadog.trace.",
                "DD_" => "datadog.",
            ]));
            if (count($val) > 1) {
                \ini_set($name, $val[1]);
            } else {
                \ini_restore($name);
            }
        }
        \putenv($putenv);
    }

    /**
     * Reloads configuration setting first the envs in $putenvs
     *
     * @param array $putenvs In the format ['ENV_1=value1', 'ENV_2=value2']
     * @return void
     */
    protected function putEnvAndReloadConfig($putenvs = [])
    {
        foreach ($putenvs as $putenv) {
            self::putEnv($putenv);
        }
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function assertStringContains($needle, $haystack, $message = '')
    {
        if (PHPUNIT_MAJOR >= 9) {
            parent::assertStringContainsString($needle, $haystack, $message);
        } else {
            parent::assertContains($needle, $haystack, $message);
        }
    }

    protected function assertStringNotContains($needle, $haystack, $message = '')
    {
        if (PHPUNIT_MAJOR >= 9) {
            parent::assertStringNotContainsString($needle, $haystack, $message);
        } else {
            parent::assertNotContains($needle, $haystack, $message);
        }
    }

    public function setExpectedException($class, $exceptionMessage = '', $exceptionCode = null)
    {
        if (PHPUNIT_MAJOR >= 5) {
            parent::expectException($class, $exceptionMessage);
        } else {
            parent::setExpectedException($class, $exceptionMessage, $exceptionCode);
        }
    }
}
