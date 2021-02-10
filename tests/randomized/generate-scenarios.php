<?php

use RandomizedTests\Tooling\ApacheConfigGenerator;
use RandomizedTests\Tooling\MakefileGenerator;
use RandomizedTests\Tooling\PhpFpmConfigGenerator;

include __DIR__ . '/config/platforms.php';
include __DIR__ . '/config/envs.php';
include __DIR__ . '/config/inis.php';
include __DIR__ . '/lib/ApacheConfigGenerator.php';
include __DIR__ . '/lib/MakefileGenerator.php';
include __DIR__ . '/lib/PhpFpmConfigGenerator.php';

const TMP_SCENARIOS_FOLDER = __DIR__ . '/.tmp.scenarios';
const MAX_ENV_MODIFICATIONS = 5;
const MAX_INI_MODIFICATIONS = 5;

function generate()
{
    $scenariosFolder = TMP_SCENARIOS_FOLDER;
    $dockerComposeFile = "${scenariosFolder}/docker-compose.yml";
    exec("cp ./templates/docker-compose.template.yml ${dockerComposeFile}");
    $dockerComposeHandle = fopen($dockerComposeFile, 'a');

    $options = getopt('', ['scenario:']);

    $testIdentifiers = [];
    if (isset($options['scenario'])) {
        // Generate only one scenario
        $seed = intval($options['scenario']);
        $testIdentifiers[] = generateOne($dockerComposeHandle, $seed);
    } else {
        // If a scenario number has not been provided, we generate a number of different scenarios based on based
        // configuration
        $options = getopt('', ['seed:', 'number:']);
        $seed = isset($options['seed']) ? intval($options['seed']) : rand();
        srand($seed);
        echo "Using seed: $seed\n";

        if (empty($options['number'])) {
            echo "Error: --number option is required to set the number of scenarios to create.\n";
            exit(1);
        }
        $numberOfScenarios = intval($options['number']);

        for ($iteration = 0; $iteration < $numberOfScenarios; $iteration++) {
            $scenarioSeed = rand();
            $testIdentifiers[] = generateOne($dockerComposeHandle, $scenarioSeed);
        }
    }

    fclose($dockerComposeHandle);
    (new MakefileGenerator())->generate("$scenariosFolder/Makefile", $testIdentifiers);
}

function generateOne($dockerComposeHandle, $scenarioSeed)
{
    srand($scenarioSeed);
    $selectedOs = array_rand(OS);
    $availablePHPVersions = OS[$selectedOs]['php'];
    $selectedPhpVersion = $availablePHPVersions[array_rand($availablePHPVersions)];
    $selectedInstallationMethod = INSTALLATION[array_rand(INSTALLATION)];

    // Environment variables
    $numberOfEnvModifications = rand(0, MAX_ENV_MODIFICATIONS);
    $envModifications = array_merge([], DEFAULT_ENVS);
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

    // INI settings
    $numberOfIniModifications = rand(0, min(MAX_INI_MODIFICATIONS, count(INIS)));
    $iniModifications = [];
    for ($iniModification = 0; $iniModification < $numberOfIniModifications; $iniModification++) {
        $currentIni = array_rand(INIS);
        $availableValues = INIS[$currentIni];
        $iniModifications[$currentIni] = $availableValues[array_rand($availableValues)];
    }
    $identifier = "randomized-$scenarioSeed-$selectedOs-$selectedPhpVersion";
    $scenarioFolder = TMP_SCENARIOS_FOLDER . "/$identifier";
    exec("mkdir -p $scenarioFolder/app");
    exec("cp -r ./app $scenarioFolder/");
    exec("cp $scenarioFolder/app/composer-$selectedPhpVersion.json $scenarioFolder/app/composer.json");

    (new ApacheConfigGenerator())->generate("$scenarioFolder/www.apache.conf", $envModifications, $iniModifications);
    (new PhpFpmConfigGenerator())->generate("$scenarioFolder/www.php-fpm.conf", $envModifications, $iniModifications);

    // Vegeta request targets
    $requestsFilePath = "$scenarioFolder/vegeta-request-targets.txt";
    $requestsFileHandle = fopen($requestsFilePath, 'w');
    fwrite($requestsFileHandle, generateRequestScenarios(2000));
    fclose($requestsFileHandle);

    // Writing docker-compose file
    fwrite($dockerComposeHandle, "
  $identifier:
    image: datadog/dd-trace-ci:php-randomizedtests-$selectedOs-$selectedPhpVersion
    ulimits:
      core: 99999999999
    privileged: true
    volumes:
      - ./$identifier/app:/var/www/html
      - $scenarioFolder/www.php-fpm.conf:/etc/php-fpm.d/www.conf
      - $scenarioFolder/www.apache.conf:/etc/httpd/conf.d/www.conf
      - $requestsFilePath:/vegeta-request-targets.txt
      - ./.tracer-versions:/tmp/tracer-versions
      - ./.results/$identifier/:/results/
      - ./.results/$identifier/nginx:/var/log/nginx
      - ./.results/$identifier/php-fpm:/var/log/php-fpm
      - ./.results/$identifier/apache:/var/log/httpd/
    environment:
        INSTALL_MODE: $selectedInstallationMethod
        TEST_SCENARIO: $identifier
    depends_on:
      - agent
      - elasticsearch
      - redis
      - memcached
      - mysql
      - httpbin\n");

    return $identifier;
}

function generateRequestScenarios($number)
{
    $availableQueries = [
        'key' => 'value',
        'key1' => 'value1',
        'key.2' => '2',
        'key_3' => 'value-3',
        'key%204' => 'value%204',
    ];
    $availableHeaders = [
        'content-type: application/json',
        'authorization: Bearer abcdef0987654321',
        'origin: http://some.url.com:9000',
        'cache-control: no-cache',
        'accept: */*',
    ];
    $requests = '';
    for ($idx = 0; $idx < $number; $idx++) {
        $method = ['GET', 'POST'][rand(0, 1)];
        $port = [/* nginx */80, /* apache*/ 81][rand(0, 1)];
        $host = 'http://localhost';
        // Query String
        $query = '';
        if (percentOfTimes(50)) {
            $query .= '?';
            // We are adding a query string
            foreach ($availableQueries as $key => $value) {
                if (percentOfTimes(70)) {
                    continue;
                }
                $query .= "$key=$value&";
            }
        }
        // Headers
        //   - distributed traing
        //   - datadog origin header
        //   - common headers (e.g. Content-Type, Origin)
        $headers = [];
        if (percentOfTimes(30)) {
            $headers[] = 'x-datadog-trace-id: ' . rand();
            $headers[] = 'x-datadog-parent-id: ' . rand();
            $headers[] = 'x-datadog-sampling-priority: ' . (percentOfTimes(70) ? '1.0' : '0.3');
        }
        if (percentOfTimes(30)) {
            $headers[] = 'x-datadog-origin: some-origin';
        }
        foreach ($availableHeaders as $header) {
            if (percentOfTimes(20)) {
                $headers[] = $header;
            }
        }

        $requests .= sprintf(
            "%s %s:%d%s\n%s\n\n",
            $method,
            $host,
            $port,
            $query,
            implode("\n", $headers)
        );
    }
    return $requests;
}

/**
 * Returns true $percent of the times, otherwise false.
 */
function percentOfTimes($percent)
{
    return rand(0, 100) <= $percent;
}


generate();
