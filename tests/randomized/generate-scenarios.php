<?php

use RandomizedTests\Tooling\ApacheConfigGenerator;
use RandomizedTests\Tooling\CLIRunnerGenerator;
use RandomizedTests\Tooling\DockerComposeFileGenerator;
use RandomizedTests\Tooling\EnvFileGenerator;
use RandomizedTests\Tooling\MakefileGenerator;
use RandomizedTests\Tooling\MakefileScenarioGenerator;
use RandomizedTests\Tooling\PhpFpmConfigGenerator;
use RandomizedTests\Tooling\PhpIniGenerator;
use RandomizedTests\Tooling\RequestTargetsGenerator;

include __DIR__ . '/config/envs.php';
include __DIR__ . '/config/inis.php';
include __DIR__ . '/config/platforms.php';
include __DIR__ . '/lib/ApacheConfigGenerator.php';
include __DIR__ . '/lib/CLIRunnerGenerator.php';
include __DIR__ . '/lib/DockerComposeFileGenerator.php';
include __DIR__ . '/lib/EnvFileGenerator.php';
include __DIR__ . '/lib/MakefileGenerator.php';
include __DIR__ . '/lib/MakefileScenarioGenerator.php';
include __DIR__ . '/lib/PhpFpmConfigGenerator.php';
include __DIR__ . '/lib/PhpIniGenerator.php';
include __DIR__ . '/lib/RequestTargetsGenerator.php';

const TMP_SCENARIOS_FOLDER = './.tmp.scenarios';
const MAX_ENV_MODIFICATIONS = 5;
const MAX_INI_MODIFICATIONS = 5;

function generate()
{
    $scenariosFolder = TMP_SCENARIOS_FOLDER;

    $options = getopt('', ['scenario:']);

    $testIdentifiers = [];
    if (isset($options['scenario'])) {
        // Generate only one scenario
        $seed = intval($options['scenario']);
        $testIdentifiers[] = generateOne($seed);
    } else {
        // If a scenario number has not been provided, we generate a number of different scenarios based on based
        // configuration
        $options = getopt('', ['seed:', 'number:', 'versions:', 'platforms:']);
        $seed = isset($options['seed']) ? intval($options['seed']) : rand();
        srand($seed);
        echo "Using seed: $seed\n";
        // Versions as a CSV, e.g. '7.4,8.0'
        $restrictedPHPVersions = isset($options['versions'])
            ? array_map('trim', explode(',', $options['versions']))
            : null;
        $restrictedPlatforms = isset($options['platforms'])
            ? array_map('trim', explode(',', $options['platforms']))
            : null;

        if (empty($options['number'])) {
            echo "Error: --number option is required to set the number of scenarios to create.\n";
            exit(1);
        }
        $numberOfScenarios = intval($options['number']);

        for ($iteration = 0; $iteration < $numberOfScenarios; $iteration++) {
            $scenarioSeed = rand();
            $testIdentifiers[] = generateOne($scenarioSeed, $restrictedPHPVersions, $restrictedPlatforms);
        }
    }

    (new MakefileGenerator())->generate("$scenariosFolder/Makefile", $testIdentifiers);
}

/**
 * From https://github.com/compose-spec/compose-spec/pull/314:
 *
 * > compose-spec/compose-go#261 changed docker compose behavior to require
 * > normalized project names as input, instead of normalizing project names
 * > automatically. This landed in compose-spec/compose-go v1.2.5 and
 * > docker/compose v2.5.1.
 *
 * The normalization was modelled after NormalizeProjectName in this PR:
 * https://github.com/compose-spec/compose-go/pull/261/files
 *
 * @param string $identifier
 * @return string
 */
function normalizeDockerProjectName($identifier)
{
    $identifier = strtolower($identifier);
    $identifier = preg_replace('/[^a-z0-9_-]/', '', $identifier, -1);
    $identifier = ltrim($identifier, '_-');
    return $identifier;
}

function generateOne($scenarioSeed, array $restrictedPHPVersions, array $restrictedPlatforms = null)
{
    srand($scenarioSeed);
    $allOS = OS;
    if ($restrictedPlatforms !== null && $restrictedPlatforms[0] !== '*') {
        $allOS = array_intersect_key($allOS, array_flip($restrictedPlatforms));
    }
    $selectedOs = array_rand($allOS);
    $availablePHPVersions = OS[$selectedOs]['php'];
    if ($restrictedPHPVersions && $restrictedPHPVersions[0] !== '*') {
        $availablePHPVersions = array_intersect($availablePHPVersions, $restrictedPHPVersions);
    }
    $selectedPhpVersion = $availablePHPVersions[array_rand($availablePHPVersions)];
    $selectedInstallationMethod = INSTALLATION[array_rand(INSTALLATION)];

    // Environment variables modifications
    $numberOfEnvModifications = rand(0, MAX_ENV_MODIFICATIONS);
    $envModifications = DEFAULT_ENVS;
    for ($envModification = 0; $envModification < $numberOfEnvModifications; $envModification++) {
        $currentEnv = array_rand(ENVS);
        $availableValues = ENVS[$currentEnv];
        $selectedEnvValue = $availableValues[array_rand($availableValues)];
        if (null === $selectedEnvValue) {
            unset($envModifications[$currentEnv]);
        } else {
            $envModifications[$currentEnv] = $selectedEnvValue;
        }
    }

    // INI settings modification
    $numberOfIniModifications = rand(0, min(MAX_INI_MODIFICATIONS, count(INIS)));
    $iniModifications = [];
    $primaryIni = [];
    for ($iniModification = 0; $iniModification < $numberOfIniModifications; $iniModification++) {
        $currentIni = array_rand(INIS);
        $availableValues = INIS[$currentIni];

        if ($selectedPhpVersion === '7.0' && $currentIni === 'extension') {
            continue; // The PHP Profiler is a Zend Extension for PHP 7.1+
        }

        if ($currentIni == "extension" || rand(0, 1)) {
            $primaryIni[$currentIni] = $availableValues[array_rand($availableValues)];
        } else {
            $iniModifications[$currentIni] = $availableValues[array_rand($availableValues)];
        }
    }

    $identifier = "randomized-$scenarioSeed-$selectedOs-$selectedPhpVersion";
    $identifier = normalizeDockerProjectName($identifier);
    $scenarioFolder = TMP_SCENARIOS_FOLDER . DIRECTORY_SEPARATOR . $identifier;

    // Preparing folder
    exec("mkdir -p $scenarioFolder/app");
    exec("cp -r ./app $scenarioFolder/");
    exec("cp $scenarioFolder/app/composer-$selectedPhpVersion.json $scenarioFolder/app/composer.json");

    $skipApache = $selectedOs == "buster"; // can't use an asan module within apache

    // Writing scenario specific files
    (new ApacheConfigGenerator())->generate("$scenarioFolder/www.apache.conf", $envModifications, $iniModifications);
    (new PhpFpmConfigGenerator())->generate("$scenarioFolder/www.php-fpm.conf", $envModifications, $iniModifications);
    (new PhpIniGenerator())->generate("$scenarioFolder/php.ini", $primaryIni);
    (new RequestTargetsGenerator())->generate("$scenarioFolder/vegeta-request-targets.txt", 2000, $skipApache);
    (new MakefileScenarioGenerator())->generate("$scenarioFolder/Makefile", $identifier);
    (new EnvFileGenerator())->generate("$scenarioFolder/.env", $identifier);
    (new DockerComposeFileGenerator())->generate(
        "$scenarioFolder/docker-compose.yml",
        [
            'identifier' => $identifier,
            'scenario_folder' => $scenarioFolder,
            'image' => "datadog/dd-trace-ci:php-randomizedtests-$selectedOs-$selectedPhpVersion-2",
            'php_version' => $selectedPhpVersion,
            'installation_method' => $selectedInstallationMethod,
            'project_root' => '../../../../',
            // buster builds are ASAN builds
            'USE_ZEND_ALLOC' => $selectedOs == "buster" ? 0 : 1,
            'USE_TRACKED_ALLOC' => $selectedOs == "buster" ? 1 : 0,
        ]
    );

    // For long running scripts we force no root span + autoflush
    $longRunningModifications = $envModifications;
    $longRunningModifications['DD_TRACE_AUTO_FLUSH_ENABLED'] = 'true';
    $longRunningModifications['DD_TRACE_GENERATE_ROOT_SPAN'] = 'false';
    (new CLIRunnerGenerator())->generate(
        "$scenarioFolder/cli-runner.sh",
        $scenarioSeed,
        $longRunningModifications,
        $iniModifications
    );

    return $identifier;
}

generate();
