<?php

declare(strict_types=1);

namespace DDTrace\Tests;

use DDTrace\NoopTracer;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tracer;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PhpBench\Benchmark\Metadata\Annotations\AfterClassMethods;
use function DDTrace\active_span;

/**
 * @BeforeClassMethods("setUpBeforeClass")
 * @AfterClassMethods("tearDownAfterClass")
 */
class LogsInjectionBench extends BaseTestCase
{
    use TracerTestTrait;

    public $logger;
    public $tracer;

    public static function setUpBeforeClass()
    {
        print("setUpBeforeClass\n");
        self::putEnvAndReloadConfig([
            'DD_TRACE_GENERATE_ROOT_SPAN=0'
        ]);
        \dd_trace_serialize_closed_spans();
    }

    public static function tearDownAfterClass()
    {
        print("tearDownAfterClass\n");
        self::putEnvAndReloadConfig([
            'DD_TRACE_GENERATE_ROOT_SPAN='
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
                print("setting $name to $val[1]\n");
                \ini_set($name, $val[1]);
            } else {
                \ini_restore($name);
            }
        }
        print(">putenv $putenv\n");
        \putenv($putenv);
    }

    public static function putEnvAndReloadConfig($putenvs = [])
    {
        foreach ($putenvs as $putenv) {
            print("putenv $putenv\n");
            self::putEnv($putenv);
        }
        var_dump(\dd_trace_internal_fn('ddtrace_reload_config'));
    }

    public function activateLogsInjection()
    {
        \dd_trace_serialize_closed_spans();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'DD_TRACE_GENERATE_ROOT_SPAN=0',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=1',
            'DD_LOGS_INJECTION=1',
            'DD_TRACE_DEBUG=1'
        ]);
        $tracer = new Tracer();
        $this->tracer = $tracer;

        \dd_trace_serialize_closed_spans();
        var_dump(active_span());
        \DDTrace\start_span();
        $logger = new Logger('test');
        print(ini_get("datadog.trace.enabled") . PHP_EOL);
        print("logger created\n");
        print("DD_LOGS_INJECTION=" . getenv('DD_LOGS_INJECTION') . "\n");
        print(dd_trace_env_config('DD_LOGS_INJECTION') . "\n");
        $streamHandler = new StreamHandler('/tmp/test.log');
        $streamHandler->setFormatter(new JsonFormatter());
        $logger->pushHandler($streamHandler);
        $this->logger = $logger;
        $this->logger->info('blabla');
    }

    public function deactivateLogsInjection()
    {
        \dd_trace_serialize_closed_spans();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=1',
            'DD_LOGS_INJECTION=0',
            'DD_TRACE_DEBUG=1'
        ]);
        $logger = new Logger('test');
        $streamHandler = new StreamHandler('/tmp/test.log');
        $streamHandler->setFormatter(new JsonFormatter());
        $logger->pushHandler($streamHandler);
        $this->logger = $logger;
    }

    /**
     * @BeforeMethods("deactivateLogsInjection")
     */
    public function benchLogsBaseline()
    {
        \DDTrace\start_span();
        $this->logger->info('not injecting logs');
        \DDTrace\close_span();
    }

    /**
     * @BeforeMethods({"activateLogsInjection"})
     */
    public function benchLogsInjection()
    {
        $tracer = $this->tracer;
        $tracer(function () {
            $this->logger->info('injecting logs');
        });
    }

}
