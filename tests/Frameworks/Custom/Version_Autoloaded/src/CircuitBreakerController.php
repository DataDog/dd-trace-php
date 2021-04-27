<?php

namespace App;

class CircuitBreakerController
{
    public function render()
    {
        for ($i = 0; $i < getenv('DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES') + 1; ++$i) {
            \dd_tracer_circuit_breaker_register_error();
        }
        header('Content-type: text/html; charset=utf-8');
        echo '<h1>Circuit Breaker</h1>';
        echo '<p>This endpoint will register circuit breaker errors before rendering the page.</p>';
    }
}
