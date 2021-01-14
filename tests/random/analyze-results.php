<?php

function analyze()
{
    global $argv;
    $resultsFolder = $argv[1];
    // error_log('Results: ' . var_export($resultsFolder, 1));

    $errors = [];

    foreach (scandir($resultsFolder) as $file) {
        if (in_array($file, ['.', '..'])) {
            continue;
        }
        $identifier = explode('.', $file)[0];
        $absFilePath = implode(DIRECTORY_SEPARATOR, [$resultsFolder, $file]);
        // error_log('File: ' . var_export($absFilePath, 1));

        $jsonResult = json_decode(file_get_contents($absFilePath), 1);

        // For now ERROR means that we receives status code different than:
        //   - 200: OK
        //   - 530: Uncaught new style exception (status code forced by us)
        //   - 531: Unhandled legacy error (status code forced by us)
        // error_log("result: " . var_export($jsonResult, 1));
        $receivedStatusCodes = $jsonResult['status_codes'];
        if (array_keys($receivedStatusCodes) !== [200, 530, 531]) {
            $errors[$identifier] = $receivedStatusCodes;
        }
    }

    // Reporting errors
    if (count($errors)) {
        echo "Errors found: " . var_export($errors, 1) . "\n";
        exit(1);
    } else {
        echo "Success\n";
    }
}

analyze();
