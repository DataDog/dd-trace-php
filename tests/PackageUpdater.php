<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap_phpunit.php';

class PackageUpdater
{
    private const INTEGRATIONS_DIR = __DIR__ . '/Integrations';
    private const PACKAGIST_API_URL = 'https://repo.packagist.org/p2/%s.json';

    private array $updates = [];
    private array $errors = [];

    public function run(): void
    {
        try {
            $files = $this->findTestFiles();
            foreach ($files as $file) {
                $this->processFile($file);
            }
            $this->displaySummary();
        } catch (Throwable $e) {
            echo "Fatal error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    private function findTestFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::INTEGRATIONS_DIR));
        $regex = new RegexIterator($iterator, '/^.+Test\.php$/i', RecursiveRegexIterator::GET_MATCH);
        $files = array_map(fn($file) => $file[0], iterator_to_array($regex));
        sort($files);
        return array_filter($files, fn($file) => basename(dirname($file)) === 'Latest');
    }

    private function processFile(string $file): void
    {
        echo "Processing: $file\n";

        try {
            // Get class info
            $content = file_get_contents($file);
            if (!preg_match('/namespace (.+);/', $content, $nsMatch) ||
                !preg_match('/class (.+) extends/', $content, $classMatch)) {
                return;
            }

            $className = "{$nsMatch[1]}\\{$classMatch[1]}";
            if (!class_exists($className)) {
                require_once $file;
                if (!class_exists($className)) return;
            }

            // Find library and composer.json
            $library = $this->findLibrary($className, $file);
            if (!$library) return;

            $composer = $this->findComposerFile($className, $file);
            if (!$composer) return;

            $this->updatePackageVersion($library, $composer);
        } catch (Throwable $e) {
            $this->errors[] = "Error processing $file: " . $e->getMessage();
        }
    }

    private function findLibrary(string $className, string $file): ?string
    {
        if (method_exists($className, 'getTestedLibrary')) {
            return call_user_func([$className, 'getTestedLibrary']);
        }

        $composerFile = dirname($file) . '/composer.json';
        if (file_exists($composerFile)) {
            $data = json_decode(file_get_contents($composerFile), true);
            return key($data['require'] ?? []) ?: null;
        }

        return null;
    }

    private function findComposerFile(string $className, string $file): ?string
    {
        foreach (['getAppIndexScript', 'getConsoleScript'] as $method) {
            if (method_exists($className, $method)) {
                $dir = dirname(call_user_func([$className, $method]));
                while (basename($dir) !== 'Frameworks') {
                    $possible = "$dir/composer.json";
                    if (file_exists($possible)) {
                        return $possible;
                    }
                    $dir = dirname($dir);
                }
            }
        }

        $composer = dirname($file) . '/composer.json';
        return file_exists($composer) ? $composer : null;
    }

    private function updatePackageVersion(string $library, string $composerFile): void
    {
        $latestVersion = $this->getLatestVersion($library);
        if (!$latestVersion) return;

        $composerData = json_decode(file_get_contents($composerFile), true);
        $currentVersion = $composerData['require'][$library] ?? null;

        if ($latestVersion !== $currentVersion) {
            $composerData['require'][$library] = $latestVersion;
            file_put_contents($composerFile, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->updates[] = compact('library', 'currentVersion', 'latestVersion', 'composerFile');
        }
    }

    private function getLatestVersion(string $library): ?string
    {
        $ch = curl_init(sprintf(self::PACKAGIST_API_URL, $library));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Package-Updater/1.0'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['packages'][$library])) {
            return null;
        }

        $versions = array_filter(
            $data['packages'][$library],
            fn($pkg) => isset($pkg['version_normalized']) &&
                !preg_match('/(alpha|beta|rc|dev)/i', $pkg['version_normalized'])
        );
        if (empty($versions)) {
            return null;
        }

        usort($versions, fn($a, $b) =>
            version_compare($b['version_normalized'], $a['version_normalized'])
        );

        return preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $versions[0]['version_normalized']);
    }

    private function displaySummary(): void
    {
        if ($this->updates) {
            echo "\nPackages updated:\n";
            foreach ($this->updates as $update) {
                echo sprintf("- %s: %s → %s (%s)\n",
                    $update['library'],
                    $update['currentVersion'],
                    $update['latestVersion'],
                    $update['composerFile']
                );
            }
        }

        if ($this->errors) {
            echo "\nErrors encountered:\n";
            foreach ($this->errors as $error) {
                echo "- $error\n";
            }
        }
    }
}

(new PackageUpdater())->run();