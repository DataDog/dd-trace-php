<?php

function dd_dump_spans()
{
    $spans = dd_trace_serialize_closed_spans();
    echo 'spans(\\DDTrace\\SpanData) (' . count($spans) . ') {' . PHP_EOL;
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
            echo '  ' . $span['name'];
        }
        if (!empty($values)) {
            echo ' (' . implode(', ', $values) . ')';
        }
        if (isset($span['meta']['error.msg'])) {
            echo ' (error: ' . $span['meta']['error.msg'] . ')';
        }
        if (isset($span['meta'])) {
            echo PHP_EOL;
            foreach ($span['meta'] as $k => $v) {
                echo '    ' . $k . ' => ' . $v . PHP_EOL;
            }
        } else {
            echo PHP_EOL;
        }
    }, $spans);
    echo '}' . PHP_EOL;
}
