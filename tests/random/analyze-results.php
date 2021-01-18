<?php

const MINIMUM_ACCEPTABLE_REQUESTS = 1000;

function analyze($resultsFolder, $dockerComposeFile)
{
    $analyzed = [];
    $errors = [];

    foreach (scandir($resultsFolder) as $identifier) {
        if (in_array($identifier, ['.', '..'])) {
            continue;
        }
        $absFilePath = $resultsFolder . DIRECTORY_SEPARATOR . $identifier . DIRECTORY_SEPARATOR . 'results.json';
        $analyzed[] = $identifier;

        $jsonResult = json_decode(file_get_contents($absFilePath), 1);

        // For now ERROR means that we receives status code different than:
        //   - 200: OK
        //   - 530: Uncaught new style exception (status code forced by us)
        //   - 531: Unhandled legacy error (status code forced by us)
        $receivedStatusCodes = $jsonResult['status_codes'];
        if (array_keys($receivedStatusCodes) !== [ 200, 530, 531 ]) {
            $errors[$identifier] = $receivedStatusCodes;
        }
        if (array_sum($receivedStatusCodes) < MINIMUM_ACCEPTABLE_REQUESTS) {
            echo sprintf(
                "Scenario '%s' did not reaced the minimum amount of requests: %d.\n",
                $identifier,
                MINIMUM_ACCEPTABLE_REQUESTS
            );
            exit(1);
        }
    }

    // Reading expected identifiers from docker-compose file
    $dockerComposeContent = explode("\n", file_get_contents($dockerComposeFile));
    $composeRunners = [];
    foreach ($dockerComposeContent as $line) {
        $normalizedLine = trim($line, " :\t");
        if (strncmp($normalizedLine, 'random-', strlen('random-')) === 0) {
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
    if (count($errors) > 0) {
        echo "Errors found: " . var_export($errors, 1) . "\n";
        exit(1);
    } else {
        echo "Success\n";
    }
}

analyze(__DIR__ . '/.tmp.scenarios/.results', __DIR__ . '/.tmp.scenarios/docker-compose.yml');
