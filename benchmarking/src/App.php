<?php

namespace DDTrace\Benchmark;

use Symfony\Component\Console\Application;

final class App extends Application
{
    public function __construct(string $name = 'UNKNOWN', string $version = 'UNKNOWN')
    {
        set_time_limit(0);
        parent::__construct($name, $version);
    }
}
