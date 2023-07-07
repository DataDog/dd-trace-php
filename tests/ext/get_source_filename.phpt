--TEST--
Retrieve the source filename of a function defined in an included file
--FILE--
<?php

include_once __DIR__ . '/includes/fake_span.inc';

DDTrace\trace_method('DDTrace\Span', 'setTag', function (\DDTrace\SpanData $span) {
    echo basename($span->sourceFile) . PHP_EOL;
});

function foo() { }

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    echo basename($span->sourceFile);
});

// Note: Used 'basename' to avoid the inconsistencies between the CI and local environments
// /home/circleci/datadog/... vs /home/circleci/app

$span = new DDTrace\Span();
$span->setTag('foo', 'bar');

foo();

--EXPECTF--
fake_span.inc
get_source_filename.php