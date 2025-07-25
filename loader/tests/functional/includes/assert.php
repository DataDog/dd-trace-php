<?php

function assertEquals($actual, $expected, $msg = null) {
    if ($actual !== $expected) {
        if (!$msg) {
            $msg = sprintf('Cannot assert "%s" equals "%s"', $actual, $expected);
        }
        throw new \Exception($msg);
    }
}

function assertContains($haystack, $needle) {
    if (strpos($haystack, $needle) === false) {
        throw new \Exception(sprintf('Cannot assert "%s" contains "%s"', $haystack, $needle));
    }
}

function assertNotContains($haystack, $needle) {
    if (strpos($haystack, $needle) !== false) {
        throw new \Exception(sprintf('Cannot assert "%s" does not contain "%s"', $haystack, $needle));
    }
}

// Copied from PHP's run-tests.php
function assertMatchesFormat($output, $wanted_re) {
    // do preg_quote, but miss out any %r delimited sections
    $temp = "";
    $r = "%r";
    $startOffset = 0;
    $length = strlen($wanted_re);
    while ($startOffset < $length) {
        $start = strpos($wanted_re, $r, $startOffset);
        if ($start !== false) {
            // we have found a start tag
            $end = strpos($wanted_re, $r, $start + 2);
            if ($end === false) {
                // unbalanced tag, ignore it.
                $end = $start = $length;
            }
        } else {
            // no more %r sections
            $start = $end = $length;
        }
        // quote a non re portion of the string
        $temp .= preg_quote(substr($wanted_re, $startOffset, $start - $startOffset), '/');
        // add the re unquoted.
        if ($end > $start) {
            $temp .= '(' . substr($wanted_re, $start + 2, $end - $start - 2) . ')';
        }
        $startOffset = $end + 2;
    }
    $wanted_re = $temp;

    // Stick to basics
    $wanted_re = strtr($wanted_re, [
        '%e' => preg_quote(DIRECTORY_SEPARATOR, '/'),
        '%s' => '[^\r\n]+',
        '%S' => '[^\r\n]*',
        '%a' => '.+',
        '%A' => '.*',
        '%w' => '\s*',
        '%i' => '[+-]?\d+',
        '%d' => '\d+',
        '%x' => '[0-9a-fA-F]+',
        '%f' => '[+-]?(?:\d+|(?=\.\d))(?:\.\d+)?(?:[Ee][+-]?\d+)?',
        '%c' => '.',
        '%0' => '\x00',
    ]);

    if (!preg_match('/^' . $wanted_re . '$/s', $output)) {
        throw new \Exception("Output does not match the format\n".$output);
    }
}

function assertTelemetry($telemetryLogPath, $metrics) {
    $metrics = (array) $metrics;
    $metrics[] = <<<EOS
{
    "metadata": {
        "runtime_name": "php",
        "runtime_version": "unknown",
        "language_name": "php",
        "language_version": "unknown",
        "tracer_version": "unknown",
        "pid": %d,
        "result_class": "unknown",
        "result_reason": "unknown",
        "result": "unknown"
    },
    "points": [
        {
            "name": "library_entrypoint.start",
            "tags": []
        }
    ]
}
EOS;

    $lines = file($telemetryLogPath);
    foreach ($lines as $line) {
        $matched = false;
        $pretty = json_encode(json_decode($line, true), JSON_PRETTY_PRINT);
        foreach ($metrics as $k => $m) {
            try {
                assertMatchesFormat($pretty, $m);
                unset($metrics[$k]);
                $matched = true;
                break;
            } catch (\Exception $e) {
                continue;
            }
        }
        if (!$matched) {
            throw new \Exception("Received an unexpected metric\n".$pretty);
        }
    }

    if (count($metrics)) {
        throw new \Exception("Missing metric(s)\n".print_r($metrics, true));
    }
}
