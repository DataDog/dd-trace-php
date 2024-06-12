<?php

require_once __DIR__.'/assert.php';

set_exception_handler(function (\Exception $ex) {
    $trace = $ex->getTrace();
    $file = $trace[0]['file'] ?: '';
    $line = $trace[0]['line'] ?: '';

    echo "------------------------------------\n";
    if ($file) {
        echo 'Error in test '.basename($file).':'.$line."\n";
        echo "------------------------------------\n";
    }
    echo $ex->getMessage()."\n";
    echo "------------------------------------\n";

    exit(1);
});

function runCLI($args, $useLoader = true, $env = []) {
    $cmd = implode(' ', $env).' ';

    $cmd .= 'php -n';
    if ($useLoader) {
        $cmd .= ' -dzend_extension='.getLoaderAbsolutePath();
    }
    $cmd .= ' '.$args;
    $cmd .= ' 2>&1';

    $res = exec($cmd, $output, $result_code);
    if (!is_string($res) || $result_code !== 0) {
        throw new \Exception(sprintf('Error while executing "%s" (exit code: %d)', $cmd, $result_code));
    }

    return implode("\n", $output);
}

function getLoaderAbsolutePath() {
    return __DIR__.'/../../../modules/dd_library_loader.so';
}

function skip_if_php5() {
    if (PHP_MAJOR_VERSION <= 5) {
        echo "Skip: test is not compatible with PHP 5\n";
        exit(0);
    }
}

function skip_if_not_php5() {
    if (PHP_MAJOR_VERSION > 5) {
        echo "Skip: test requires PHP 5\n";
        exit(0);
    }
}

function skip_if_opcache_missing() {
    $output = runCLI('-dzend_extension=opcache -m');
    if (strpos($output, 'Zend OPcache') === false) {
        echo "Skip: test requires opcache\n";
        exit(0);
    }
}
