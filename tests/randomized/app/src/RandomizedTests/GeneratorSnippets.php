<?php

namespace RandomizedTests;

class GeneratorSnippets
{
    private $randomExecutionPath;

    public function __construct(RandomExecutionPath $randomExecutionPath)
    {
        $this->randomExecutionPath = $randomExecutionPath;
    }

    public function generator()
    {
        $number = rand(1, 10);
        for ($i = 1; $i < $number; $i++) {
            $this->randomExecutionPath->runSomeIntegrations();
            yield $i;
        }
    }
}
