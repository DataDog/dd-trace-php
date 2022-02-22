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

    public function __construct(
        $snippetsConfiguration,
        $seed = null,
        $allowFatalAndUncaught = true,
        $logMethodExecution = false
    ) {
        $this->seed = $seed ?: \rand();
        $this->allowFatalAndUncaught = $allowFatalAndUncaught;
        $this->snippetsConfiguration = $snippetsConfiguration;
        $this->logMethodExecution = $logMethodExecution;
    }
}
