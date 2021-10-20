<?php

namespace RandomizedTests;

class RandomExecutionPathConfiguration
{
    /** @var int */
    public $seed;

    /** @var SnippetsConfiguration */
    public $snippetsConfiguration;

    /** @var bool */
    public $allowFatalAndUncaught = true;

    /** @var bool */
    public $logMethodExecution = false;

    /** @var bool */
    public $exitOnHandledException = true;

    public function __construct(
        $snippetsConfiguration,
        $seed = null,
        $allowFatalAndUncaught = true,
        $exitOnHandledException = true,
        $logMethodExecution = false,
    ) {
        $this->sed = $seed ?: \rand();
        $this->allowFatalAndUncaught = $allowFatalAndUncaught;
        $this->snippetsConfiguration = $snippetsConfiguration;
        $this->logMethodExecution = $logMethodExecution;
        $this->exitOnHandledException = $exitOnHandledException;
    }
}
