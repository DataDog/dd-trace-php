<?php

declare(strict_types=1);

namespace DDTrace\Benchmarks;

use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tracer;
use Monolog\Logger;
use PhpBench\Benchmark\Metadata\Annotations\AfterClassMethods;
use Psr\Log\NullLogger;

/**
 * @BeforeClassMethods("setUpBeforeClass")
 * @AfterClassMethods("tearDownAfterClass")
 */
class LogsInjectionBench
{
    use TracerTestTrait;

    public $logger;
    public $tracer;

    public static function setUpBeforeClass()
    {
        self::putEnvAndReloadConfig([
        ]);
        \dd_trace_serialize_closed_spans();
    }

    public static function tearDownAfterClass()
    {
        self::putEnvAndReloadConfig([
        ]);
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

    public static function putEnvAndReloadConfig($putenvs = [])
    {
        foreach ($putenvs as $putenv) {
            self::putEnv($putenv);
        }
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    public function enableLogsInjection()
    {
        \dd_trace_serialize_closed_spans();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=1',
            'DD_LOGS_INJECTION=1',
        ]);
        $tracer = new Tracer();
        $this->tracer = $tracer;

        \dd_trace_serialize_closed_spans();
        \DDTrace\start_span();
        $logger = new Logger('test');
        $noopHandler = new \Monolog\Handler\NoopHandler();
        $logger->pushHandler($noopHandler);
        $this->logger = $logger;
    }

    public function enableLogsInjectionNullLogger()
    {
        $this->enableLogsInjection();
        $this->logger = new NullLogger();
    }

    public function disableLogsInjectionNullLogger()
    {
        $this->disableLogsInjection();
        $this->logger = new NullLogger();
    }

    public function disableLogsInjection()
    {
        \dd_trace_serialize_closed_spans();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=1',
            'DD_LOGS_INJECTION=0',
        ]);
        $logger = new Logger('test');
        $noopHandler = new \Monolog\Handler\NoopHandler();
        $logger->pushHandler($noopHandler);
        $this->logger = $logger;
    }

    /**
     * @BeforeMethods("disableLogsInjection")
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     */
    public function benchLogsInfoBaseline()
    {
        $this->logger->info('not injecting logs');
    }

    /**
     * @BeforeMethods({"enableLogsInjection"})
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     */
    public function benchLogsInfoInjection()
    {
        $this->logger->info('injecting logs');
    }

    /**
     * @BeforeMethods("disableLogsInjectionNullLogger")
     * @Revs(100)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     */
    public function benchLogsNullBaseline()
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->logger->log("debug", 'not injecting logs');
        }
    }

    /**
     * @BeforeMethods({"enableLogsInjectionNullLogger"})
     * @Revs(100)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     */
    public function benchLogsNullInjection()
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->logger->log("debug", 'injecting logs');
        }
    }
}
