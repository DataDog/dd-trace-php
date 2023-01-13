<?php

const MINIMUM_ACCEPTABLE_REQUESTS = 900;
const MINIMUM_ASAN_ACCEPTABLE_REQUESTS = 300;

function analyze_web($tmpScenariosFolder)
{
    $resultsFolder = $tmpScenariosFolder . DIRECTORY_SEPARATOR . '.results';
    $analyzed = [];
    $unexpectedCodes = [];
    $possibleSegfaults = [];
    $minimumRequestCount = [];

    foreach (scandir($resultsFolder) as $identifier) {
        if (in_array($identifier, ['.', '..'])) {
            continue;
        }
        $scenarioResultsRoot = $resultsFolder . DIRECTORY_SEPARATOR . $identifier;
        $absFilePath = $scenarioResultsRoot . DIRECTORY_SEPARATOR . 'results.json';
        $analyzed[] = $identifier;

        $jsonResult = json_decode(file_get_contents($absFilePath), 1);

        // For now ERROR means that we receives status code different than:
        //   - 200: OK
        //   - 510: Uncaught new style exception (status code forced by us)
        //   - 511: Unhandled legacy error (status code forced by us)
        // Note: not all the 5** can be set in PHP/Apache via http_response_code()
        // See: https://www.php.net/manual/en/function.http-response-code.php#114996
        $receivedStatusCodes = $jsonResult['status_codes'];
        $numberOfCICurlErrors = count_circleci_curl_error_7_failures($scenarioResultsRoot);
        if (getenv('CIRCLECI') === 'true' && isset($receivedStatusCodes['500'])) {
            error_log('Number: ' . var_export($numberOfCICurlErrors, true));
            $receivedStatusCodes['500'] -= $numberOfCICurlErrors;
            if ($receivedStatusCodes['500'] === 0) {
                unset($receivedStatusCodes['500']);
            }
        }

        // We have to ignore 0 status codes as in CirceCI they are over reported. Instead we check error log for
        // Apache's segfaults.
        unset($receivedStatusCodes[0]);
        if (array_keys($receivedStatusCodes) !== [200, 510, 511]) {
            $unexpectedCodes[$identifier] = $receivedStatusCodes;
        }

        if (count_possible_segfaults($scenarioResultsRoot)) {
            $possibleSegfaults[$identifier] = true;
        }

        // ASAN has more overhead
        $minimum = strpos($identifier, "buster") ? MINIMUM_ASAN_ACCEPTABLE_REQUESTS : MINIMUM_ACCEPTABLE_REQUESTS;
        if (($requestCount = array_sum($receivedStatusCodes)) < $minimum) {
            $minimumRequestCount[$identifier] = $requestCount;
        }
    }

    // Reporting errors
    echo "Analyzed " . count($analyzed) . " web scenarios.\n";

    $isError = false;
    if (count($unexpectedCodes) > 0) {
        echo "Unexpected status codes found: " . var_export($unexpectedCodes, 1) . "\n";
        $isError = true;
    }
    if (count($possibleSegfaults) > 0) {
        echo "Possible seg faults: " . var_export(array_keys($possibleSegfaults), 1) . "\n";
        $isError = true;
    }
    if (count($minimumRequestCount)) {
        echo "Minimum request not matched: " . var_export($minimumRequestCount, 1) . "\n";
        $isError = true;
    }

    if ($isError) {
        return false;
    }

    // Reading expected identifiers
    $foundScenarios = [];
    foreach (scandir($tmpScenariosFolder) as $identifier) {
        if (substr($identifier, 0, strlen('randomized-')) !== 'randomized-') {
            continue;
        }
        $foundScenarios[] = $identifier;
    }

    sort($analyzed);
    sort($foundScenarios);

    if ($foundScenarios != $analyzed) {
        echo sprintf(
            "Error: number of scenarios found (%d) and results found (%d) mismatch.\n",
            count($foundScenarios),
            count($analyzed)
        );
        return false;
    }

    return true;
}

function analyze_cli($tmpScenariosFolder)
{
    $resultsFolder = $tmpScenariosFolder . DIRECTORY_SEPARATOR . '.results';
    $analyzed = [];
    $notEnoughResults = [];
    $largeInterceptResults = [];
    $leaksResults = [];
    $leaksResultsPhp54 = [];

    foreach (scandir($resultsFolder) as $identifier) {
        if (in_array($identifier, ['.', '..'])) {
            continue;
        }

        $analyzed[] = $identifier;

        $absFilePath = $resultsFolder . DIRECTORY_SEPARATOR . $identifier . DIRECTORY_SEPARATOR . 'memory.out';

        $values = array_map('intval', array_filter(
            explode("\n", file_get_contents($absFilePath)),
            function ($l) {
                // explicitly allow 0, as these occur when Zend MM is off
                return $l != "";
            }
        ));

        if (count($values) < 50) {
            $notEnoughResults[] = $identifier;
            continue;
        }

        list($slope, $intercept) = calculate_trend_line($values);

        if ($intercept > 6.5 * 1000 * 1000) {
            // Heuristic 6.5MB limit. It might have to be increased as we add integrations
            $largeInterceptResults[] = $identifier;
            continue;
        }

        // We must accept a 0.1% slope as elastic search has small increases even when tracer is not loaded.
        if (abs($slope) > ($intercept * 0.001)) {
            if (substr($identifier, -3) === '5.4') {
                // PHP 5.4 has leaks in long running CLI scripts regardless of the tracer loaded
                $leaksResultsPhp54[] = $identifier;
            } else {
                $leaksResults[] = $identifier;
            }
            continue;
        }
    }

    echo "Analyzed " . count($analyzed) . " CLI scenarios.\n";

    // Reporting errors
    if (count($leaksResults)) {
        echo "The following scenarios might have memory leaks in CLI. Check out their respective memory.out file:\n ";
        foreach ($leaksResults as $result) {
            echo "    $result\n";
        }
    }
    if (count($leaksResultsPhp54)) {
        echo "The following scenarios leak memory on PHP 5.4 (expected, it happens also without tracing):\n ";
        foreach ($leaksResultsPhp54 as $result) {
            echo "    $result\n";
        }
    }
    if (count($largeInterceptResults)) {
        echo "The following scenarios consume an unexpected amount of memory. "
            . "Check out their respective memory.out file:\n ";
        foreach ($largeInterceptResults as $result) {
            echo "    $result\n";
        }
    }
    if (count($notEnoughResults)) {
        echo "The following scenarios have not the minimum number of data points. "
            . "Check out their respective memory.out file:\n ";
        foreach ($notEnoughResults as $result) {
            echo "    $result\n";
        }
    }

    // Leaks on PHP 5.4 do not cause the analysis to fail, as leaks are also present without the tracer loaded.
    if ((count($largeInterceptResults) + count($notEnoughResults) + count($leaksResults)) === 0) {
        return true;
    }

    return false;
}

function count_circleci_curl_error_7_failures($scenarioResultsRoot)
{
    $count = 0;

    $phpFpmLogs = $scenarioResultsRoot . DIRECTORY_SEPARATOR . 'php-fpm' . DIRECTORY_SEPARATOR . 'error.log';
    $count += substr_count(file_get_contents($phpFpmLogs), 'cURL error 7');

    $apacheLogs = $scenarioResultsRoot . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'error_log';
    if (file_exists($apacheLogs)) {
        $count += substr_count(file_get_contents($apacheLogs), 'cURL error 7');
    }

    return $count;
}

function count_possible_segfaults($scenarioResultsRoot)
{
    $count = 0;

    $phpFpmLogs = $scenarioResultsRoot . DIRECTORY_SEPARATOR . 'php-fpm' . DIRECTORY_SEPARATOR . 'error.log';
    $phpFpmLogsContent = file_get_contents($phpFpmLogs);
    if (!$phpFpmLogsContent) {
        throw new Exception("Error while reading file $phpFpmLogs");
    }
    $count += substr_count($phpFpmLogsContent, ' signal ');

    // phpcs:disable Generic.Files.LineLength.TooLong
    /* PHP-FPM 5.6 at times segfaults during module shutdown for reasons that almost certainly not related to the tracer
     * #0  0x00007ff9836a2387 in __GI_raise (sig=sig@entry=6) at ../nptl/sysdeps/unix/sysv/linux/raise.c:55
     * #1  0x00007ff9836a3a78 in __GI_abort () at abort.c:90
     * #2  0x00007ff9836e4ed7 in __libc_message (do_abort=do_abort@entry=2, fmt=fmt@entry=0x7ff9837f73f0 "*** Error in `%s': %s: 0x%s ***\n") at ../sysdeps/unix/sysv/linux/libc_fatal.c:196
     * #3  0x00007ff9836ed299 in malloc_printerr (ar_ptr=0x7ff983a33760 <main_arena>, ptr=<optimized out>, str=0x7ff9837f4bf0 "free(): invalid pointer", action=3) at malloc.c:4967
     * #4  _int_free (av=0x7ff983a33760 <main_arena>, p=<optimized out>, have_lock=0) at malloc.c:3843
     * #5  0x0000557565eb006b in php_module_shutdown () at /usr/src/debug/php-5.6.40/main/main.c:2477
     * #6  0x0000557565d8ecb8 in main (argc=<optimized out>, argv=<optimized out>) at /usr/src/debug/php-5.6.40/sapi/fpm/fpm/fpm_main.c:2041
     */
    // phpcs:enable Generic.Files.LineLength.TooLong
    $count -= substr_count($phpFpmLogsContent, ' signal 6 (SIGABRT');
    // With asan shutdown timeouts may be exceeded
    $count -= substr_count($phpFpmLogsContent, ' signal 9 (SIGKILL');

    $apacheLogs = $scenarioResultsRoot . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'error_log';
    if (file_exists($apacheLogs)) {
        $apacheLogsContent = file_get_contents($apacheLogs);
        if (!$apacheLogsContent) {
            throw new Exception("Error while reading file $apacheLogs");
        }
        $count += substr_count($apacheLogsContent, ' signal ');
    }

    return $count;
}

/**
 * Calculates the trend line using the "Least Squares Regression" approach.
 *
 * Credits:
 *   - https://www.mathsisfun.com/data/least-squares-regression.html
 *
 * @return [float, float] slope, intercept
 */
function calculate_trend_line(array $values)
{
    $count = count($values);
    $xs = [];
    $ys = [];
    $xys = [];
    $x2s = [];
    for ($i = 0; $i < count($values); $i++) {
        $x = (float)($i + 1);
        $y = (float)$values[$i];
        $xs[] = $x;
        $ys[] = $y;
        $xys[] = $x * $y;
        $x2s[] = pow($x, 2);
    }

    $sumXs = array_sum($xs);
    $sumYs = array_sum($ys);
    $sumXYs = array_sum($xys);
    $sumX2s = array_sum($x2s);

    $slope = ($count * $sumXYs - $sumXs * $sumYs) / ($count * $sumX2s - pow($sumXs, 2));
    $intercept = ($sumYs - $slope * $sumXs) / $count;

    return [$slope, $intercept];
}

$webResult = analyze_web(__DIR__ . '/.tmp.scenarios');
$cliResult = analyze_cli(__DIR__ . '/.tmp.scenarios');

if (!$webResult || !$cliResult) {
    exit(1);
}

echo "Success\n";
