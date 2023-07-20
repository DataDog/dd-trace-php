--TEST--
Retrieve the filename where the function/method originated from
--FILE--
<?php

include_once __DIR__ . '/../../includes/fake_span.inc';
include_once __DIR__ . '/../../includes/intermediary_call.inc';

DDTrace\install_hook('DDTrace\Span::setTag', function (\DDTrace\HookData $hook) {
    echo basename($hook->getSourceFile()) . PHP_EOL;
});

function foo() { }

DDTrace\install_hook('foo', function (\DDTrace\HookData $hook) {
    echo basename($hook->getSourceFile()) . PHP_EOL;
});

// Note: Used 'basename' to avoid the inconsistencies between the CI and local environments
// /home/circleci/datadog/... vs /home/circleci/app

$span = new DDTrace\Span();
$span->setTag('foo', 'bar');

foo();

intermediarySetTag();

--EXPECTF--
get_source_file.php
get_source_file.php
intermediary_call.inc