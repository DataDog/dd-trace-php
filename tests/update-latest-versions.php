<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap_phpunit.php';

$INTEGRATIONS_DIR = './Integrations';

$testFiles = [];
$directory = new RecursiveDirectoryIterator($INTEGRATIONS_DIR);
$iterator = new RecursiveIteratorIterator($directory);
$regex = new RegexIterator($iterator, '/^.+Test\.php$/i', RecursiveRegexIterator::GET_MATCH);
foreach ($regex as $file) {
    $testFiles[] = $file[0];
}

sort($testFiles);
//var_dump($testFiles);

foreach ($testFiles as $file) {
    try {
        $content = file_get_contents($file);
        $matches = [];
        preg_match('/namespace (.+);/', $content, $matches);
        $namespace = $matches[1];
        preg_match('/class (.+) extends/', $content, $matches);
        $class = $matches[1];
        $class = $namespace . '\\' . $class;
        if (!class_exists($class)) {
            require_once $file;
        }
        if (!class_exists($class) || !method_exists($class, 'getTestedLibrary')) {
            continue;
        }
        $library = call_user_func([$class, 'getTestedLibrary']);
        if (empty($library)) {
            continue;
        }


        if (basename(dirname($file)) !== 'Latest') {
            continue;
        }

        echo "File: $file\n";

        if (method_exists($class, 'getAppIndexScript')) {
            $workingDir = call_user_func([$class, 'getAppIndexScript']);
            do {
                $workingDir = dirname($workingDir);
                $composer = $workingDir . '/composer.json';
            } while (!file_exists($composer) && basename($workingDir) !== 'Frameworks'); // there is no reason to go further up

            if (!file_exists($composer)) {
                continue;
            }

            echo "Library: $library\n";
            echo "Composer: $composer\n";
        } elseif (method_exists($class, 'getConsoleScript')) {
            $workingDir = call_user_func([$class, 'getConsoleScript']);
            do {
                $workingDir = dirname($workingDir);
                $composer = $workingDir . '/composer.json';
            } while (!file_exists($composer) && basename($workingDir) !== 'Frameworks'); // there is no reason to go further up

            if (!file_exists($composer)) {
                continue;
            }

            echo "Library: $library\n";
            echo "Composer: $composer\n";
        } elseif (file_exists(dirname($file) . '/composer.json')) {
            $composer = dirname($file) . '/composer.json';
            echo "Library: $library\n";
            echo "Composer: $composer\n";
        } else {
            continue;
        }

        // Get the latest version of the library
        $latestVersion = getLatestComposerVersion($library);
        if (!$latestVersion) {
            throw new Exception("Unable to determine the latest version for {$library}.");
        }
        echo "Latest version: $latestVersion\n";

        // Compare with the version in composer.json
        $composerData = json_decode(file_get_contents($composer), true);
        $composerVersion = $composerData['require'][$library] ?? null;
        echo "Composer version: $composerVersion\n";

        // If it's different, update the composer.json file
        if ($latestVersion && $latestVersion !== $composerVersion) {
            $composerData['require'][$library] = $latestVersion;
            file_put_contents($composer, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo "Updated composer.json\n";
        }
    } catch (Throwable $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
}

function getLatestComposerVersion($packageName) {
    $packagistUrl = "https://repo.packagist.org/p2/{$packageName}.json";
    $ch = curl_init($packagistUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        echo "Error: Unable to fetch package information for {$packageName}.\n";
        return "";
    }

    $data = json_decode($response, true);

    if (!isset($data['packages'][$packageName])) {
        echo "Error: Package {$packageName} not found on Packagist.\n";
        return "";
    }

    $latestVersion = null;

    foreach ($data['packages'][$packageName] as $package) {
        $currentVersion = $package['version_normalized'] ?? null;
        if ($currentVersion && (is_null($latestVersion) || version_compare($currentVersion, $latestVersion, '>'))) {
            $latestVersion = $currentVersion;
        }
    }

    if ($latestVersion) {
        return preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $latestVersion);
    }

    return "";
}
