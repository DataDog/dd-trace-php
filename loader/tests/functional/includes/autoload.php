<?php

require_once __DIR__.'/assert.php';

set_exception_handler(function ($ex) {
    $trace = $ex->getTrace();
    $file = $trace[0]['file'] ?: '';
    $line = $trace[0]['line'] ?: '';
    $stackTrace = basename($file).':'.$line;

    if (basename($file) === 'assert.php') {
        $file2 = $trace[1]['file'] ?: '';
        $line2 = $trace[1]['line'] ?: '';
        $stackTrace = basename($file2).':'.$line2.' > '.$stackTrace;
    }

    echo "------------------------------------\n";
    if ($file) {
        echo 'Error in test '.$stackTrace."\n";
        echo "------------------------------------\n";
    }
    echo $ex->getMessage()."\n";
    echo "------------------------------------\n";

    exit(1);
});

function runCLI($args, $useLoader = true, $env = [], $noIni = true) {
    if (!isset($_SERVER['DD_LOADER_PACKAGE_PATH'])) {
        $env[] = "DD_LOADER_PACKAGE_PATH=/home/circleci/app/dd-library-php";
    }

    $valgrind = use_valgrind();
    if ($valgrind) {
        $env[] = 'USE_ZEND_ALLOC=0';
        $env[] = 'ZEND_DONT_UNLOAD_MODULES=1';
    }

    $cmd = implode(' ', $env).' ';

    $valgrindLogFile = tempnam(sys_get_temp_dir(), 'valgrind_loader_test_');;
    if ($valgrind) {
        $cmd .= "valgrind  -q --log-file=$valgrindLogFile --suppressions=./valgrind.supp --gen-suppressions=all --tool=memcheck --trace-children=no --undef-value-errors=no --vex-iropt-register-updates=allregs-at-mem-access --leak-check=full ";
    }

    $cmd .= 'php';
    if ($noIni) {
        $cmd .= ' -n';
    }
    if ($useLoader) {
        $cmd .= ' -dzend_extension='.getLoaderAbsolutePath();
    }
    $cmd .= ' '.$args;
    $cmd .= ' 2>&1';
    $cmd = trim($cmd);

    if (debug()) {
        echo '[debug] Executing command: '.$cmd."\n";
    }

    $res = exec($cmd, $output, $result_code);

    if ($valgrind) {
        $valgrindOutput = file_get_contents($valgrindLogFile);
        @unlink($valgrindLogFile);
    }

    if (!is_string($res) || $result_code !== 0) {
        throw new \Exception(sprintf('Error while executing "%s" (exit code: %d): \n\n', $cmd, $result_code, $output));
    }

    if ($valgrind) {
        if (strlen($valgrindOutput)) {
            throw new \Exception("Memory leak detected:\n".$valgrindOutput);
        }
    }

    return implode("\n", $output);
}

function getLoaderAbsolutePath() {
    return __DIR__.'/../../../modules/dd_library_loader.so';
}

function debug() {
    return (bool) (isset($_SERVER['DEBUG']) ? $_SERVER['DEBUG'] : false);
}

function use_valgrind() {
    return (bool) (isset($_SERVER['TEST_USE_VALGRIND']) ? $_SERVER['TEST_USE_VALGRIND'] : false);
}

function php_minor_version() {
    return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
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

function skip_if_not_php8() {
    if (PHP_MAJOR_VERSION < 8) {
        echo "Skip: test requires PHP 8\n";
        exit(0);
    }
}

function skip_if_not_at_least_php71() {
    if (PHP_MAJOR_VERSION < 7 || (PHP_MAJOR_VERSION === 7 && PHP_MINOR_VERSION === 0)) {
        echo "Skip: test requires PHP 7.1+\n";
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
