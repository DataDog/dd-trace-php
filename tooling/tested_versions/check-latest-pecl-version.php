<?php

$extensionName = $argv[1] ?? null;

if (!$extensionName) {
    echo "Please provide an extension name.\n";
    exit(1);
}

// Run the `pecl search` command
exec("pecl search $extensionName", $output, $returnCode);

if ($returnCode !== 0) {
    echo "Error: Unable to search for PECL extension {$extensionName}.\n";
    exit($returnCode);
}

// Process the output to find the matching extension
$latestVersion = null;

foreach ($output as $line) {
    // Match lines containing the extension name and a version number
    if (preg_match('/^' . preg_quote($extensionName, '/') . '\s+([\d\.]+)\s+\(([\w\/]+)\)/', $line, $matches)) {
        $latestVersion = $matches[1];
        break; // Stop after finding the first match for the extension name
    }
}

if ($latestVersion) {
    echo $latestVersion . "\n";
} else {
    echo "Error: Extension {$extensionName} not found or no stable version available.\n";
    exit(1);
}
