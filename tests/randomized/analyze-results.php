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
    echo "Analyzed " . count($analyzed) . " scenarios.\n";

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
        exit(1);
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
            "Error: number of scenarios found (%d) and results found (%d) mismastch.\n",
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
    $unexpectedResults = [];

    foreach (scandir($resultsFolder) as $identifier) {
        if (in_array($identifier, ['.', '..'])) {
            continue;
        }

        $analyzed[] = $identifier;

        $absFilePath = $resultsFolder . DIRECTORY_SEPARATOR . $identifier . DIRECTORY_SEPARATOR . 'memory.out';
        $values = array_map('intval', explode("\n", file_get_contents($absFilePath)));

        // removing first and last 5% of samples
        $count = count($values);
        $selectedValues = array_slice($values, $count * 0.05, $count * 0.9);

        // we start being strict, we can be more lenient in the future if that makes sense and to reduce flakiness
        if (min($selectedValues) !== max($selectedValues)) {
            $unexpectedResults[] = $identifier;
        }
    }

    // Reporting errors
    echo "Analyzed " . count($analyzed) . " CLI scenarios.\n";
    if (count($unexpectedResults) > 0) {
        echo "The following scenarios might have memory leaks in CLI. Check out their respective memory.out file:\n ";
        foreach ($unexpectedResults as $unexpectedResult) {
            echo "    $unexpectedResult\n";
        }
        return false;
    }

    return true;
}

$webResult = analyze_web(__DIR__ . '/.tmp.scenarios');
$cliResult = analyze_cli(__DIR__ . '/.tmp.scenarios');

if (!$webResult || !$cliResult) {
    exit(1);
}

echo "Success\n";
