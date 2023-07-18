--TEST--
Retrieve the filename where the function/method was executed from
--FILE--
<?php

include_once __DIR__ . '/includes/fake_span.inc';
include_once __DIR__ . '/includes/intermediary_call.inc';

DDTrace\trace_method('DDTrace\Span', 'setTag', function (\DDTrace\SpanData $span) {
    echo basename($span->sourceFile) . PHP_EOL;
});

function foo() { }

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    echo basename($span->sourceFile) . PHP_EOL;
});

// Note: Used 'basename' to avoid the inconsistencies between the CI and local environments
// /home/circleci/datadog/... vs /home/circleci/app

$span = new DDTrace\Span();
$span->setTag('foo', 'bar');

foo();

intermediarySetTag();

--EXPECTF--
get_source_filename.php
get_source_filename.php
intermediary_call.inc