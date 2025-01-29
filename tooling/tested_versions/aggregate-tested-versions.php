<?php

$TESTED_VERSIONS_DIR = implode('/', array_slice(explode('/', __DIR__), 0, 4)) . '/tests/tested_versions';
$OUTPUT_FILE = 'tested_versions.json';
$OUTPUT_FILE_PATH = "$TESTED_VERSIONS_DIR/tested_versions.json";
$aggregatedData = [];

foreach (scandir($TESTED_VERSIONS_DIR) as $file) {
    $filePath = "$TESTED_VERSIONS_DIR/$file";

    if (is_file($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'json' && basename($filePath) !== $OUTPUT_FILE) {
        $jsonData = json_decode(file_get_contents($filePath), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
            foreach ($jsonData as $libraryName => $libraryVersion) {
                if (!isset($aggregatedData[$libraryName])) {
                    $aggregatedData[$libraryName] = [];
                }

                if (!in_array($libraryVersion, $aggregatedData[$libraryName])) {
                    $aggregatedData[$libraryName][] = $libraryVersion;
                }
            }
        } else {
            echo "Error decoding JSON file: $filePath\n";
        }
    }
}

// mkdir $TESTED_VERSIONS_DIR
if (!file_exists($TESTED_VERSIONS_DIR)) {
    mkdir($TESTED_VERSIONS_DIR, 0777, true);
}

file_put_contents($OUTPUT_FILE_PATH, json_encode($aggregatedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Aggregated data written to: $OUTPUT_FILE_PATH\n";
