<?php

const MINIMUM_ACCEPTABLE_REQUESTS = 1000;

function analyze_web($tmpScenariosFolder)
{
    $resultsFolder = $tmpScenariosFolder . DIRECTORY_SEPARATOR . '.results';
    $analyzed = [];
    $unexpectedCodes = [];
    $minimumRequestCount = [];

    foreach (scandir($resultsFolder) as $identifier) {
        if (in_array($identifier, ['.', '..'])) {
            continue;
        }
        $absFilePath = $resultsFolder . DIRECTORY_SEPARATOR . $identifier . DIRECTORY_SEPARATOR . 'results.json';
        $analyzed[] = $identifier;

        $jsonResult = json_decode(file_get_contents($absFilePath), 1);

        // For now ERROR means that we receives status code different than:
        //   - 200: OK
        //   - 510: Uncaught new style exception (status code forced by us)
        //   - 511: Unhandled legacy error (status code forced by us)
        // Note: not all the 5** can be set in PHP/Apache via http_response_code()
        // See: https://www.php.net/manual/en/function.http-response-code.php#114996
        $receivedStatusCodes = $jsonResult['status_codes'];
        if (array_keys($receivedStatusCodes) !== [200, 510, 511]) {
            $unexpectedCodes[$identifier] = $receivedStatusCodes;
        }
        if (($requestCount = array_sum($receivedStatusCodes)) < MINIMUM_ACCEPTABLE_REQUESTS) {
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
        if (
            substr($identifier, 0, strlen('randomized-')) !== 'randomized-'
            && substr($identifier, 0, strlen('regression-')) !== 'regression-'
        ) {
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

        // Old regressions do not have CLI tests
        if (!file_exists($absFilePath) && strpos($absFilePath, "regression-") !== false) {
            continue;
        }

        $values = array_map('intval', array_filter(explode("\n", file_get_contents($absFilePath))));

        if (count($values) < 50) {
            $notEnoughResults[] = $identifier;
            continue;
        }

        list($slope, $intercept) = calculate_trend_line($values);

        if ($intercept > 5 * 1000 * 1000) {
            // Heuristic 5MB limit. It might have to be increased as we add integrations
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
