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
            echo PHP_EOL;
        }, dd_trace_serialize_closed_spans());

        // To test curl_exec calls are not instrumented in a flush
        fake_curl_exec();
    }

    public function reset()
    {
        echo 'Tracer reset' . PHP_EOL;
    }
}

class GlobalTracer
{
    private static $instance;

    public static function set(Tracer $tracer)
    {
        self::$instance = $tracer;
    }

    public static function get()
    {
        if (null !== self::$instance) {
            return self::$instance;
        }
        return self::$instance = new Tracer();
    }
}

function fake_curl_exec() {
    return true;
}
