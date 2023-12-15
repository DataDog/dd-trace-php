<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\Utils;
use DDTrace\Tracer;
use Monolog\Logger;
use Psr\Log\NullLogger;

class LogsInjectionBench
{
    use TracerTestTrait;

    public $logger;
    public $tracer;

    public function enableLogsInjection()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
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
        Utils::putEnvAndReloadConfig([
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
