<?php

namespace RandomTests;

class RandomExecutionPath
{
    private $snippets;

    private $allowFatalAndUncaught = false;

    private $generatorSnippets;

    public function __construct($allowFatalAndUncaught = false)
    {
        $this->snippets = new Snippets();
        $this->allowFatalAndUncaught = $allowFatalAndUncaught;
        if (!Utils::isPhpVersion(5, 4)) {
            $this->generatorSnippets = new GeneratorSnippets($this);
        }
    }

    public function randomPath()
    {
        if ($this->percentOfCases(70)) {
            $this->doSomethingTraced();
        } else {
            $this->doSomethingUntraced();
        }
        return "OK";
    }

    public function doSomethingUntraced()
    {
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
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingTraced()
    {
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
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingUntraced1()
    {
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
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingTraced1()
    {
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        if ($this->percentOfCases(80)) {
            if ($this->percentOfCases(70)) {
                $this->doSomethingUntraced2();
            } else {
                $this->doSomethingTraced2();
            }
        } else {
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingUntraced2()
    {
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingTraced2()
    {
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
    }

    private function maybeRunSomeIntegrations()
    {
        if ($this->percentOfCases(70)) {
            $this->runSomeIntegrations();
        }
    }

    public function runSomeIntegrations()
    {
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
    }

    private function availableIntegrations()
    {
        return [
            'elasticsearch' => 1,
            'guzzle' => 1,
            'memcached' => 1,
            'mysqli' => 1,
            'curl' => 1,
            'pdo' => 1,
            'phpredis' => 1,
        ];
    }

    private function maybeSomethingHappens()
    {
        $this->maybeUseAGenerator();
        $this->maybeEmitAWarning();
        $this->maybeEmitACaughtException();
        $this->maybeEmitAnUncaughtException();
        $this->maybeGenerateAFatal();
    }

    private function maybeUseAGenerator()
    {
        if (!Utils::isPhpVersion(5, 4) && $this->percentOfCases(5)) {
            $this->useAGenerator();
        }
    }

    protected function useAGenerator()
    {
        $generator = $this->generatorSnippets->generator();
        foreach ($generator as $value) {
            // doing nothing
        }
    }

    private function maybeEmitAWarning()
    {
        // #1021 caused by DD_TRACE_ENABLED=true + warning emitted
        if ($this->percentOfCases(5)) {
            \trigger_error("Some warning triggered", \E_USER_WARNING);
        }
    }

    private function maybeEmitACaughtException()
    {
        if ($this->percentOfCases(20)) {
            try {
                $this->alwaysThrowException('caught exception from chaos');
            } catch (\Exception $e) {
            }
        }
    }

    private function maybeEmitAnUncaughtException()
    {
        if ($this->allowFatalAndUncaught && $this->percentOfCases(2)) {
            $this->alwaysThrowException('uncaught exception from chaos');
        }
    }

    private function maybeGenerateAFatal()
    {
        if ($this->allowFatalAndUncaught && $this->percentOfCases(2)) {
            $this->alwaysGenerateAFatal();
        }
    }

    private function percentOfCases($percent)
    {
        return \rand(0, 99) < $percent;
    }

    private function alwaysThrowException($message)
    {
        throw new \Exception($message, 510);
    }

    private function alwaysGenerateAFatal()
    {
        trigger_error('triggering a user errror', \E_USER_ERROR);
    }

    public function handleException($ex)
    {
        error_log("Handling Exception: " . $ex->getMessage());
        http_response_code(530);
        exit(1);
    }

    public function handleError($errno, $errstr)
    {
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
            http_response_code(531);
            exit(1);
        }
    }
}
