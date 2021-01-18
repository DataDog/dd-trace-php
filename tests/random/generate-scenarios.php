<?php

const TMP_SCENARIOS_FOLDER = __DIR__ . '/.tmp.scenarios';
const DEFAULT_NUMBER_OF_SCENARIOS = 100;
const MAX_ENV_MODIFICATIONS = 5;
const MAX_INI_MODIFICATIONS = 5;
const DEFAULT_EXECUTION_BATCH =  20;

const OS = [
    'centos7' => [
        'php' => [
            '8.0',
            '7.4',
            '7.3',
            '7.2',
            '7.1',
            '7.0',
            '5.6',
            '5.5',
            '5.4',
        ],
    ],
];

const INSTALLATION = [
    'package',
];

const DEFAULT_ENVS = [
    'DD_AGENT_HOST' => 'agent',
];

const ENVS = [
    'DD_ENV' => ['some_env'],
    'DD_SERVICE' => ['my_custom_service'],
    'DD_TRACE_ENABLED' => ['false'],
    'DD_TRACE_DEBUG' => ['true'],
    'DD_AGENT_HOST' => [null, 'wrong_host'],
    'DD_TRACE_AGENT_PORT' => ['9999'],
    'DD_DISTRIBUTED_TRACING' => ['false'],
    'DD_AUTOFINISH_SPANS' => ['true'],
    'DD_PRIORITY_SAMPLING' => ['false'],
    'DD_SERVICE_MAPPING' => ['pdo:pdo-changed,curl:curl-changed'],
    'DD_TRACE_AGENT_CONNECT_TIMEOUT' => ['1'],
    'DD_TRACE_AGENT_TIMEOUT' => ['1'],
    'DD_TRACE_AUTO_FLUSH_ENABLED' => ['true'],
    'DD_TAGS' => ['tag_1:hi,tag_2:hello'],
    'DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN' => ['true'],
    'DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST' => ['true'],
    'DD_TRACE_MEASURE_COMPILE_TIME' => ['false'],
    'DD_TRACE_NO_AUTOLOADER' => ['true'],
    'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX' => ['^aaabbbccc$'],
    'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING' => ['cities/*'],
    'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING' => ['cities/*'],
    'DD_TRACE_SAMPLE_RATE' => ['0.5', '0.0'],
    'DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED' => ['false'],
    'DD_VERSION' => ['1.2.3'],
    // Analytics
    'DD_TRACE_SAMPLE_RATE' => ['0.3'],
    // Integrations
    'DD_TRACE_CAKEPHP_ENABLED' => ['false'],
    'DD_TRACE_CODEIGNITER_ENABLED' => ['false'],
    'DD_TRACE_CURL_ENABLED' => ['false'],
    'DD_TRACE_ELASTICSEARCH_ENABLED' => ['false'],
    'DD_TRACE_ELOQUENT_ENABLED' => ['false'],
    'DD_TRACE_GUZZLE_ENABLED' => ['false'],
    'DD_TRACE_LARAVEL_ENABLED' => ['false'],
    'DD_TRACE_LUMEN_ENABLED' => ['false'],
    'DD_TRACE_MEMCACHED_ENABLED' => ['false'],
    'DD_TRACE_MONGO_ENABLED' => ['false'],
    'DD_TRACE_MYSQLI_ENABLED' => ['false'],
    'DD_TRACE_PDO_ENABLED' => ['false'],
    'DD_TRACE_PHPREDIS_ENABLED' => ['false'],
    'DD_TRACE_PREDIS_ENABLED' => ['false'],
    'DD_TRACE_SLIM_ENABLED' => ['false'],
    'DD_TRACE_SYMFONY_ENABLED' => ['false'],
    'DD_TRACE_WEB_ENABLED' => ['false'],
    'DD_TRACE_WORDPRESS_ENABLED' => ['false'],
    'DD_TRACE_YII_ENABLED' => ['false'],
    'DD_TRACE_ZENDFRAMEWORK_ENABLED' => ['false'],
];

// Add flags as boolean
const INIS = [
    'opcache.enabled' => [false],
    // 'opcache.preload' => ['TBD'],
];

function generate()
{
    $scenariosFolder = TMP_SCENARIOS_FOLDER;
    $dockerComposeFile = "${scenariosFolder}/docker-compose.yml";
    exec("cp ./docker-compose.template.yml ${dockerComposeFile}");
    $dockerComposeHandle = fopen($dockerComposeFile, 'a');

    $testIdentifiers = [];
    $numberOfScenarios = getenv('NUMBER_OF_SCENARIOS')
        ? intval(getenv('NUMBER_OF_SCENARIOS'))
        : DEFAULT_NUMBER_OF_SCENARIOS;
    for ($iteration = 0; $iteration < $numberOfScenarios; $iteration++) {
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
        $seed = rand();
        $identifier = "random-$seed-$selectedOs-$selectedPhpVersion";
        $testIdentifiers[] = $identifier;
        $scenarioFolder = TMP_SCENARIOS_FOLDER . "/$identifier";
        exec("mkdir -p $scenarioFolder/app");
        exec("cp -r ./app $scenarioFolder/");
        exec("cp $scenarioFolder/app/composer-$selectedPhpVersion.json $scenarioFolder/app/composer.json");

        // Writing PHP-FPM worker file
        $wwwFilePath = "$scenarioFolder/$identifier.www.conf";
        exec("cp ./www.template.conf $wwwFilePath");
        $wwwFileHandle = fopen($wwwFilePath, 'a');
        foreach ($envModifications as $envName => $envValue) {
            fwrite($wwwFileHandle, "env[$envName] = \"$envValue\"\n");
        }
        foreach ($iniModifications as $iniName => $iniValue) {
            if (is_bool($iniValue)) {
                fwrite($wwwFileHandle, "php_flag[$iniName] = " . ($iniValue ? 'on' : 'off') . "\n");
            } else {
                fwrite($wwwFileHandle, "php_value[$iniName] = \"$iniValue\"\n");
            }
        }
        fclose($wwwFileHandle);

        // Writing docker-compose file
        fwrite($dockerComposeHandle, "
  $identifier:
    image: datadog/dd-trace-ci:php-randomtests-$selectedOs-$selectedPhpVersion
    ulimits:
      core: 99999999999
    privileged: true
    networks:
      - random_tests
    volumes:
      - composer_cache:/composer-cache
      - ./$identifier/app:/var/www/html
      - $wwwFilePath:/etc/php-fpm.d/www.conf
      - ./.tracer-versions:/tmp/tracer-versions
      - ./.results/$identifier/:/results/
      - ./.results/$identifier/nginx:/var/log/nginx
      - ./.results/$identifier/php-fpm:/var/log/php-fpm
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
    }
    fclose($dockerComposeHandle);

    // Generating makefile
    $makefile = "${scenariosFolder}/Makefile";
    exec("cp ./Makefile.template ${makefile}");
    $makefileHandle = fopen($makefile, 'a');
    $batches = [];
    $executionBatchCount = getenv('EXECUTION_BATCH') ? intval(getenv('EXECUTION_BATCH')) : DEFAULT_EXECUTION_BATCH;
    for ($testIndex = 0; $testIndex < count($testIdentifiers); $testIndex++) {
        $batch = "test.batch." . (floor($testIndex / $executionBatchCount) + 1);
        $batches[$batch][] = "test.scenario." . $testIdentifiers[$testIndex];
    }
    fwrite($makefileHandle, sprintf("\ntest: %s\n", implode(' ', array_keys($batches))));
    foreach ($batches as $batch => $identifiers) {
        fwrite($makefileHandle, sprintf("%s: %s\n", $batch, implode(' ', $identifiers)));
    }
    fclose($makefileHandle);
}

generate();
