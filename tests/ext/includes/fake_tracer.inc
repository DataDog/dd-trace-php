<?php

namespace DDTrace;

class Tracer
{
    public function flush()
    {
        echo 'Flushing tracer...' . PHP_EOL;
        array_map(function($span) {
            $values = [];
            if (isset($span['service'])) {
                $values[] = $span['service'];
            }
            if (isset($span['resource'])) {
                $values[] = $span['resource'];
            }
            if (isset($span['type'])) {
                $values[] = $span['type'];
            }

            if (isset($span['name'])) {
                echo $span['name'];
            }
            if (!empty($values)) {
                echo ' (' . implode(', ', $values) . ')';
            }
            if (isset($span['meta']['error.msg'])) {
                echo ' (error: ' . $span['meta']['error.msg'] . ')';
            }
            echo PHP_EOL;
        }, dd_trace_serialize_closed_spans());

        // To test curl_exec calls are not instrumented in a flush
        fake_curl_exec();
    }

    public function startActiveSpan($name)
    {
        return new Scope(new Span());
    }

    public function reset()
    {
        echo 'Tracer reset' . PHP_EOL;
    }
}

function fake_curl_exec() {
    return true;
}
