<?php

namespace DDTrace;

class Tracer
{
    public function flush()
    {
        $valuesStrings = [];
        array_map(function($span) use (&$valuesStrings) {
            $values = [];
            $valuesString = "";
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
                $valuesString .= $span['name'];
            }
            if (!empty($values)) {
                $valuesString .= ' (' . implode(', ', $values) . ')';
            }
            if (isset($span['meta']['error.msg'])) {
                $valuesString .= ' (error: ' . $span['meta']['error.msg'] . ')';
            }
            $valuesString .= PHP_EOL;
            if (strlen($valuesString) > 0) {
                $valuesStrings[] = $valuesString;
            }
        }, dd_trace_serialize_closed_spans());

        // Reporting
        $output = 'Flushing tracer...' . PHP_EOL;
        foreach ($valuesStrings as $value) {
            $output .= $value;
        }
        echo $output;

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
