<?php

$INPUT = $argv[1] ?? 'aggregated_tested_versions.json';
$OUTPUT = $argv[2] ?? 'integration_versions.md';

$data = json_decode(file_get_contents($INPUT), true);

$markdownTable = "| Library                     | Min. Supported Version | Max. Supported Version |\n";
$markdownTable .= "|-----------------------------|------------------------|------------------------|\n";

ksort($data);

foreach ($data as $library => $versions) {
    $minVersion = $versions[0];
    $maxVersion = $versions[0];
    foreach ($versions as $version) {
        if (version_compare($version, $minVersion, '<')) {
            $minVersion = $version;
        }
        if (version_compare($version, $maxVersion, '>')) {
            $maxVersion = $version;
        }
    }

    $markdownTable .= sprintf(
        "| %-27s | %-22s | %-22s |\n",
        $library,
        $minVersion,
        $maxVersion
    );
}

file_put_contents($OUTPUT, $markdownTable);

echo "Markdown table has been generated and saved to $OUTPUT.\n";

?>
