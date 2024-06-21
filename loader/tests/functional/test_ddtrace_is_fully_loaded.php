<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$output = runCLI(__DIR__.'/fixtures/ddtrace.php');
assertEquals($output, <<<EOS
foo
using passthru
Array
(
)
EOS
);

$output = runCLI('-ddatadog.trace.cli_enabled=1 '.__DIR__.'/fixtures/ddtrace.php');
assertMatchesFormat($output, <<<EOS
foo
using passthru
Array
(
    [0] => Array
        (
%A
            [name] => foo
            [resource] => foo
            [service] => ddtrace.php
            [type] => cli
%A
    [1] => Array
        (
%A
            [name] => command_execution
            [resource] => sh
            [service] => ddtrace.php
            [type] => system
            [meta] => Array
                (
                    [cmd.shell] => echo using passthru
                    [component] => subprocess
                    [cmd.exit_code] => 0
                )
%A
EOS
);
