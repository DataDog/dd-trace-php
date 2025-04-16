<?php

require 'vendor/autoload.php';
require 'CustomPrinter.php';

use StubsGenerator\{StubsGenerator, Finder};

$SRC_DIR = implode('/', array_slice(explode('/', __DIR__), 0, 4)) . '/src/';
const FILES_TO_LOAD = [
    "../../src/bridge/_files_api.php",
    "../../src/bridge/_files_tracer.php",
    "../../src/bridge/_files_opentelemetry.php",
];

$files = array_merge(...array_map(fn($file) => require $file, FILES_TO_LOAD));

$files = array_map(fn($file) => str_replace($SRC_DIR . "bridge/../", "", $file), $files);

$generator = new StubsGenerator();
$finder = Finder::create()->in($SRC_DIR)->path($files)->sortByName();

$stubs = $generator->generate($finder)->prettyPrint(new CustomPrinter());

$outputFile = $SRC_DIR . "ddtrace_php_api.stubs.php";
file_put_contents($outputFile, $stubs);

echo sprintf("Stubs generated at %s\n", $outputFile);
