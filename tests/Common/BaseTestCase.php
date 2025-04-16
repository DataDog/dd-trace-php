<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tests\Common\MultiPHPUnitVersionAdapter;
use DDTrace\Log\Logger;
use DDTrace\Tests\DebugLogger;
use DDTrace\Util\Versions;

/**
 * @method void assertArrayHasKey(mixed $key, array $arr)
 * @method void assertContains(mixed $needle, iterable $haystack)
 * @method void assertEmpty(array $arr)
 * @method void assertFalse(boolean $value)
 * @method void assertNotEmpty(array $arr)
 * @method void assertSame(mixed $expected, mixed $value)
 * @method void assertTrue(boolean $value)
 */
abstract class BaseTestCase extends MultiPHPUnitVersionAdapter
{
    public static $activeResourceLock;

    public static function ddSetUpBeforeClass()
    {
        if (isset(static::$lockedResource)) {
            $lock = fopen("/tmp/ddtrace-phpunit/lock-" . static::$lockedResource, "c+");
            flock($lock, LOCK_EX);
            self::$activeResourceLock = $lock;
        }
    }

    public static function ddTearDownAfterClass()
    {
        self::$activeResourceLock = null;
    }

    protected function ddSetUp()
    {
    }

    protected function ddTearDown()
    {
        \Mockery::close();
        if (\class_exists('DDTrace\Log\Logger')) {
            Logger::reset();
        }
        foreach ($this->envsToCleanUpAtTearDown() as $env) {
            self::putEnv($env);
        }
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    /**
     * Extend this method in test classes to have those envs automatically cleaned
     *
     * @return string[]
     */
    protected function envsToCleanUpAtTearDown()
    {
        return [];
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

    public static function putEnv($putenv)
    {
        // cleanup: properly replace this function by ini_set() in test code ...
        if (strpos($putenv, "DD_") === 0) {
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
    public static function putEnvAndReloadConfig($putenvs = [])
    {
        foreach ($putenvs as $putenv) {
            self::putEnv($putenv);
        }
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function assertStringContains($needle, $haystack, $message = '')
    {
        if (PHPUNIT_MAJOR >= 8) {
            parent::assertStringContainsString($needle, $haystack, $message);
        } else {
            parent::assertContains($needle, $haystack, $message);
        }
    }

    protected function assertStringNotContains($needle, $haystack, $message = '')
    {
        if (PHPUNIT_MAJOR >= 8) {
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

    protected function assertRegularExpression($pattern, $string, $message = '')
    {
        if (PHPUNIT_MAJOR >= 9) {
            parent::assertMatchesRegularExpression($pattern, $string, $message);
        } else {
            parent::assertRegExp($pattern, $string, $message);
        }
    }

    protected function assertFileNotExist($filename, $message = '')
    {
        if (PHPUNIT_MAJOR >= 9) {
            parent::assertFileDoesNotExist($filename, $message);
        } else {
            parent::assertFileNotExists($filename, $message);
        }
    }

    /**
     * Tells whether or not an array is associative.
     *
     * @param array $input
     * @return bool
     */
    protected static function isListArray(array $input)
    {
        return $input === array_values($input);
    }
}
