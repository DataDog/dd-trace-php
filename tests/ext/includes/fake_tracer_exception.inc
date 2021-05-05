<?php

namespace DDTrace;

class Tracer
{
    public function flush()
    {
        echo 'Flushing tracer with exception...' . PHP_EOL;
        throw new \Exception('You should not see this');
    }

    public function reset()
    {
        echo 'Tracer reset' . PHP_EOL;
    }
}
