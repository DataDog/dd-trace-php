<?php

function dd_dump_spans($skipMeta = false)
{
    $roots = $children = [];
    foreach (dd_clean_spans() as $span) {
        if (isset($span["parent_id"])) {
            $children[$span["parent_id"]][] = $span;
        } else {
            $roots[] = $span;
        }
    }

    $printSpan = function($span, $indent = 2) use ($skipMeta) {
        $values = [];
        if (($span['service'] ?? "") != "") {
            $values[] = $span['service'];
        }
        if (($span['resource'] ?? "") != "") {
            $values[] = $span['resource'];
        }
        if (($span['type'] ?? "") != "") {
            $values[] = $span['type'];
        }

        echo str_repeat(' ', $indent);
        if (isset($span['name'])) {
             echo $span['name'];
        }
        if (!empty($values)) {
            echo ' (' . implode(', ', $values) . ')';
        }
        if (isset($span['attributes']['error.message'])) {
            echo ' (error: ' . $span['attributes']['error.message'] . ')';
        }
        if (isset($span['attributes'])) {
            if ($skipMeta) {
                unset($span['attributes']['_dd.p.dm']);
            }
            echo PHP_EOL;
            unset($span["attributes"]["runtime-id"]);
            unset($span["attributes"]["_dd.tags.process"]);
            unset($span["attributes"]["_dd.sdk.otlp_export"]);
            foreach (['process_id', '_dd.agent_psr', 'php.compilation.total_time_ms', 'php.memory.peak_usage_bytes', 'php.memory.peak_real_usage_bytes'] as $volatile) {
                unset($span['attributes'][$volatile]);
            }
            foreach ($span['attributes'] as $k => $v) {
                echo str_repeat(' ', $indent) . '  ' . $k . ' => ' . (is_string($v) ? $v : json_encode($v)) . PHP_EOL;
            }
        } else {
            echo PHP_EOL;
        }
    };

    $sorter = function($a, $b) {
        return $a["start"] <=> $b["start"];
    };

    echo 'spans(\\DDTrace\\SpanData) (' . count($roots) . ') {' . PHP_EOL;
    $recurse = function ($span, $indent = 2) use (&$recurse, &$children, $printSpan, $sorter) {
        $printSpan($span, $indent);
        $sortedChildren = $children[$span['span_id']] ?? [];
        uasort($sortedChildren, $sorter);
        foreach ($sortedChildren as $child) {
            $recurse($child, $indent + 2);
        }
        unset($children[$span["span_id"]]);

    };
    usort($roots, $sorter);
    foreach ($roots as $root) {
        $recurse($root);
    }
    foreach ($children as $spans) {
        foreach ($spans as $span) {
            echo '  #[no_parent]', PHP_EOL;
            $printSpan($span);
        }
    }
    echo '}' . PHP_EOL;
}

// The spans of all chunks, in order.
function dd_clean_spans() {
    return array_merge([], ...array_column(dd_trace_serialize_closed_spans()['chunks'], 'spans'));
}
