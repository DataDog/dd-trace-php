#!/usr/bin/env php

<?php

function generateTrace()
{
    $traceId = randomId();
    $conf = configuration();
    $durationResolutionMs = 5;
    $spanDurationMs = rand(4, 10) * $durationResolutionMs;
    $nowMs = microtime(true) * 1000;
    $trace = [
        [
            [
                'trace_id' => (int) $traceId,
                'span_id' => (int) $traceId,
                'name' => 'operation_name',
                'resource' => 'resource_name',
                'service' => $conf['DD_SERVICE'],
                'start' => (int)(($nowMs - $spanDurationMs) * 1000 * 1000),
                'duration' => (int)($spanDurationMs * 1000 * 1000),
                'error' => 0,
                'meta' => [
                    'some.tag' => 'a value',
                ],
                'metrics' => [
                    '_sampling_priority_v1' => 1,
                ],
            ],
        ],
    ];

    return $trace;
}

function configuration()
{
    return [
        'DD_AGENT_HOST' => getenv('DD_AGENT_HOST') ?: 'localhost',
        'DD_TRACE_AGENT_PORT' => getenv('DD_TRACE_AGENT_PORT') ?: '8126',
        'DD_ENV' => getenv('DD_ENV') ?: null,
        'DD_SERVICE' => getenv('DD_SERVICE') ?: 'manual-trace-generator',
        'DD_VERSION' => getenv('DD_VERSION') ?: '0.0.1',
    ];
}

function send($number)
{
    for ($i = 0; $i < $number; $i++) {
        sendOne(generateTrace());
    }
}

function randomId()
{
    return rand(0, PHP_INT_MAX);
}

function sendOne($trace)
{
    $config = configuration();
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config['DD_AGENT_HOST'] . ':' . $config['DD_TRACE_AGENT_PORT'] . '/v0.4/traces');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        [
            'Content-Type: application/json',
            'Datadog-Meta-Lang: php',
            'Datadog-Meta-Lang-Version: ' . \PHP_VERSION,
            'Datadog-Meta-Lang-Interpreter: ' . \PHP_SAPI,
            'Datadog-Meta-Tracer-Version: 0.0.1',
        ]
    );
    curl_setopt($ch, CURLOPT_POST, true);
    $payload = json_encode($trace);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    if ((curl_exec($ch)) === false) {
        error_log('Reporting of spans failed: ' . print_r([
            'error' => curl_error($ch),
            'num' => \curl_errno($ch)
        ], 1));
    }
    curl_close($ch);
}

send(10000);
