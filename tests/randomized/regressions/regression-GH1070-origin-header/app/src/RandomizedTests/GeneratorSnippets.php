<?php

namespace RandomizedTests;

/**
 * PHP Generator syntax support (https://www.php.net/manual/en/language.generators.syntax.php)
 * It is moved to its own class and imported in PHP 5.5+ where yield keyword is supported.
 *
 * @package RandomizedTests
 */
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
