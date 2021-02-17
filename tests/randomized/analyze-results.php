<?php

const MINIMUM_ACCEPTABLE_REQUESTS = 1000;

function analyze($resultsFolder, $dockerComposeFile)
{
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
        if (array_keys($receivedStatusCodes) !== [ 200, 510, 511 ]) {
            $unexpectedCodes[$identifier] = $receivedStatusCodes;
        }
        if (($requestCount = array_sum($receivedStatusCodes)) < MINIMUM_ACCEPTABLE_REQUESTS) {
            $minimumRequestCount[$identifier] = $requestCount;
        }
    }

    // Reading expected identifiers from docker-compose file
    $dockerComposeContent = explode("\n", file_get_contents($dockerComposeFile));
    $composeRunners = [];
    foreach ($dockerComposeContent as $line) {
        $normalizedLine = trim($line, " :\t");
        if (strncmp($normalizedLine, 'randomized-', strlen('randomized-')) === 0) {
            $composeRunners[] = $normalizedLine;
        }
    }

    sort($analyzed);
    sort($composeRunners);

    if ($composeRunners != $analyzed) {
        echo sprintf(
            "Error: number of docker compose test runners (%d) and results found (%d) mismastch.\n",
            count($composeRunners),
            count($analyzed)
        );
        exit(1);
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

    echo "Success\n";
}

analyze(__DIR__ . '/.tmp.scenarios/.results', __DIR__ . '/.tmp.scenarios/docker-compose.yml');
