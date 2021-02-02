<?php

namespace RandomizedTests;

class RandomExecutionPath
{
    /** @var boolean */
    private $traceMethodExecution;
    private $currentExecutionPathDepth = 0;

    private $snippets;

    private $allowFatalAndUncaught = false;

    private $generatorSnippets;

    public function __construct($allowFatalAndUncaught = true)
    {
        // Seeding to allow reproducible requests via <url>/?seed=123
        $queries = array();
        if (isset($_SERVER['QUERY_STRING'])) {
            parse_str($_SERVER['QUERY_STRING'], $queries);
        }
        $this->traceMethodExecution = isset($queries['execution_path']);

        if (isset($queries['seed'])) {
            $seed = intval($queries['seed']);
        } else {
            $seed = rand();
        }
        error_log(sprintf('Current PID: %d. Current seed %d', getmypid(), $seed));
        srand($seed);

        $this->snippets = new Snippets();
        $this->allowFatalAndUncaught = $allowFatalAndUncaught;

        if (!Utils::isPhpVersion(5, 4)) {
            $this->generatorSnippets = new GeneratorSnippets($this);
        }

        // Do not use function_exists('DDTrace\...') because if DD_TRACE_ENABLED is not false and the function does not
        // exist then we MUST generate an error
        if (getenv('DD_TRACE_ENABLED') !== 'false' && extension_loaded('ddtrace')) {
            // Tracing manual functions
            $callback = function (\DDTrace\SpanData $span) {
                $span->service = \ddtrace_config_app_name();
            };
            \dd_trace_method('RandomizedTests\RandomExecutionPath', 'doSomethingTraced', $callback);
            \dd_trace_method('RandomizedTests\RandomExecutionPath', 'doSomethingTraced1', $callback);
            \dd_trace_method('RandomizedTests\RandomExecutionPath', 'doSomethingTraced2', $callback);
            \dd_trace_method('RandomizedTests\GeneratorSnippets', 'generator', $callback);
        }
    }

    public function randomPath()
    {
        $this->logEnter(__FUNCTION__);
        if ($this->percentOfCases(70)) {
            $this->doSomethingTraced();
        } else {
            $this->doSomethingUntraced();
        }
        $this->logLeave(__FUNCTION__);
        return "OK";
    }

    public function doSomethingUntraced()
    {
        $this->logEnter(__FUNCTION__);
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        if ($this->percentOfCases(80)) {
            if ($this->percentOfCases(70)) {
                $this->doSomethingUntraced1();
            } else {
                $this->doSomethingTraced1();
            }
        } else {
            $this->logLeave(__FUNCTION__);
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        $this->logLeave(__FUNCTION__);
    }

    public function doSomethingTraced()
    {
        $this->logEnter(__FUNCTION__);
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        if ($this->percentOfCases(80)) {
            if ($this->percentOfCases(70)) {
                $this->doSomethingUntraced1();
            } else {
                $this->doSomethingTraced1();
            }
        } else {
            $this->logLeave(__FUNCTION__);
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        $this->logLeave(__FUNCTION__);
    }

    public function doSomethingUntraced1()
    {
        $this->logEnter(__FUNCTION__);
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        if ($this->percentOfCases(80)) {
            if ($this->percentOfCases(70)) {
                $this->doSomethingUntraced2();
            } else {
                $this->doSomethingTraced2();
            }
        } else {
            $this->logLeave(__FUNCTION__);
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        $this->logLeave(__FUNCTION__);
    }

    public function doSomethingTraced1()
    {
        $this->logEnter(__FUNCTION__);
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        if ($this->percentOfCases(80)) {
            if ($this->percentOfCases(70)) {
                $this->doSomethingUntraced2();
            } else {
                $this->doSomethingTraced2();
            }
        } else {
            $this->logLeave(__FUNCTION__);
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        $this->logLeave(__FUNCTION__);
    }

    public function doSomethingUntraced2()
    {
        $this->logEnter(__FUNCTION__);
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        $this->logLeave(__FUNCTION__);
    }

    public function doSomethingTraced2()
    {
        $this->logEnter(__FUNCTION__);
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->logLeave(__FUNCTION__);
    }

    private function maybeRunSomeIntegrations()
    {
        $this->logEnter(__FUNCTION__);
        if ($this->percentOfCases(70)) {
            $this->runSomeIntegrations();
        }
        $this->logLeave(__FUNCTION__);
    }

    public function runSomeIntegrations()
    {
        $this->logEnter(__FUNCTION__);
        $availableIntegrations = $this->availableIntegrations();
        $availableIntegrationsNames = \array_keys($availableIntegrations);
        $numberOfIntegrationsToRun = \rand(0, \count($availableIntegrations));
        for ($integrationIndex = 0; $integrationIndex < $numberOfIntegrationsToRun; $integrationIndex++) {
            $pickAnIntegration = \rand(0, count($availableIntegrationsNames) - 1);
            $integrationName = $availableIntegrationsNames[$pickAnIntegration];
            $pickAVariant = \rand(1, $availableIntegrations[$integrationName]);

            $functionName = $integrationName . 'Variant' . $pickAVariant;
            $this->snippets->$functionName();
        }
        $this->logLeave(__FUNCTION__);
    }

    private function availableIntegrations()
    {
        $all = [
            'elasticsearch' => 1,
            'guzzle' => 1,
            'memcached' => 1,
            'mysqli' => 1,
            'curl' => 1,
            'pdo' => 1,
            'phpredis' => 1,
        ];

        if (Utils::isPhpVersion(5, 4) || Utils::isPhpVersion(5, 5) || Utils::isPhpVersion(8, 0)) {
            unset($all['elasticsearch']);
        }

        return $all;
    }

    private function maybeSomethingHappens()
    {
        $this->logEnter(__FUNCTION__);
        $this->maybeUseAGenerator();
        $this->maybeEmitAWarning();
        $this->maybeEmitACaughtException();
        $this->maybeEmitAnUncaughtException();
        $this->maybeGenerateAFatal();
        $this->logLeave(__FUNCTION__);
    }

    private function maybeUseAGenerator()
    {
        $this->logEnter(__FUNCTION__);
        if (!Utils::isPhpVersion(5, 4) && $this->percentOfCases(5)) {
            $this->useAGenerator();
        }
        $this->logLeave(__FUNCTION__);
    }

    protected function useAGenerator()
    {
        $this->logEnter(__FUNCTION__);
        $generator = $this->generatorSnippets->generator();
        foreach ($generator as $value) {
            // doing nothing
        }
        $this->logLeave(__FUNCTION__);
    }

    private function maybeEmitAWarning()
    {
        $this->logEnter(__FUNCTION__);
        // #1021 caused by DD_TRACE_ENABLED=true + warning emitted
        if ($this->percentOfCases(5)) {
            \trigger_error("Some warning triggered", \E_USER_WARNING);
        }
        $this->logLeave(__FUNCTION__);
    }

    private function maybeEmitACaughtException()
    {
        $this->logEnter(__FUNCTION__);
        if ($this->percentOfCases(20)) {
            try {
                $this->alwaysThrowException('caught exception from randomized tests');
            } catch (\Exception $e) {
            }
        }
        $this->logLeave(__FUNCTION__);
    }

    private function maybeEmitAnUncaughtException()
    {
        if ($this->allowFatalAndUncaught && $this->percentOfCases(2)) {
            $this->alwaysThrowException('uncaught exception from randomized tests');
        }
    }

    private function maybeGenerateAFatal()
    {
        $this->logEnter(__FUNCTION__);
        if ($this->allowFatalAndUncaught && $this->percentOfCases(2)) {
            $this->alwaysGenerateAFatal();
        }
        $this->logLeave(__FUNCTION__);
    }

    private function percentOfCases($percent)
    {
        return \rand(0, 99) < $percent;
    }

    private function alwaysThrowException($message)
    {
        $this->logEnter(__FUNCTION__);
        $this->logLeave(__FUNCTION__);
        // We return a status code that is not 'expected'. Expected are 510/511.
        throw new \Exception($message, 508);
    }

    private function alwaysGenerateAFatal()
    {
        $this->logEnter(__FUNCTION__);
        $this->logLeave(__FUNCTION__);
        trigger_error('triggering a user errror', \E_USER_ERROR);
    }

    public function handleException($ex)
    {
        $this->logEnter(__FUNCTION__);

        if ($ex->getMessage() === 'uncaught exception from randomized tests') {
            error_log("Handling expected Exception: " . $ex->getMessage());
            // When we have an expected uncaught exception, we return 510 that is one of the three status codes we
            // accept (200, 510 - expected exceptions, 511 - expected user errors)
            http_response_code(510);
        } else {
            error_log("Unexpected Exception: " . $ex->getMessage());
            http_response_code(500);
        }
        $this->logLeave(__FUNCTION__);
        exit(1);
    }

    public function handleError($errno, $errstr)
    {
        $this->logEnter(__FUNCTION__);
        $errorName = $errno;
        if ($errno === \E_USER_ERROR) {
            $errorName = 'E_USER_ERROR';
        } elseif ($errno === \E_USER_WARNING) {
            $errorName = 'E_USER_WARNING';
        } elseif ($errno === \E_USER_NOTICE) {
            $errorName = 'E_USER_NOTICE';
        } elseif ($errno === \E_USER_DEPRECATED) {
            $errorName = 'E_USER_DEPRECATED';
        }
        error_log("Handling Error: $errorName - $errstr");

        if ($errno === \E_USER_ERROR) {
            // When we have a user error, we return 511 that is one of the three status codes we
            // accept (200, 510 - expected exceptions, 511 - expected user errors)
            http_response_code(511);
            $this->logLeave(__FUNCTION__);
            exit(1);
        }
        $this->logLeave(__FUNCTION__);
    }

    public function logEnter($subject)
    {
        if ($this->traceMethodExecution) {
            error_log(\sprintf("%s↘ %s", \str_repeat(' ', $this->currentExecutionPathDepth), $subject));
            $this->currentExecutionPathDepth += 2;
        }
    }

    public function logLeave($subject)
    {
        if ($this->traceMethodExecution) {
            $this->currentExecutionPathDepth -= 2;
            error_log(\sprintf("%s↙ %s", \str_repeat(' ', $this->currentExecutionPathDepth), $subject));
        }
    }
}
