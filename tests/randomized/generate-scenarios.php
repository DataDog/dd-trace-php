<?php

const TMP_SCENARIOS_FOLDER = __DIR__ . '/.tmp.scenarios';
const MAX_ENV_MODIFICATIONS = 5;
const MAX_INI_MODIFICATIONS = 5;

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
    'opcache.enable' => [false],
];

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

    // Generating makefile
    $makefile = "${scenariosFolder}/Makefile";
    exec("cp ./templates/Makefile.template ${makefile}");
    $makefileHandle = fopen($makefile, 'a');
    $testTargets = array_map(
        function ($identifier) {
            return "test.scenario.$identifier";
        },
        $testIdentifiers
    );
    fwrite($makefileHandle, sprintf("test: %s\n", implode(" \\\n    ", $testTargets)));
    fclose($makefileHandle);
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

    // Setting ENVs and INIs
    $fpmWwwFileContent = "";
    $apacheConfigFileContent = "";
    foreach ($envModifications as $envName => $envValue) {
        $fpmWwwFileContent .= "env[$envName] = \"$envValue\"\n";
        $apacheConfigFileContent .= "    SetEnv $envName \"$envValue\"\n";
    }
    foreach ($iniModifications as $iniName => $iniValue) {
        if (is_bool($iniValue)) {
            $fpmWwwFileContent .= sprintf("php_admin_flag[%s] = %s\n", $iniName, $iniValue ? 'on' : 'off');
            $apacheConfigFileContent .= sprintf("    php_admin_flag %s %s\n", $iniName, $iniValue ? 'on' : 'off');
        } else {
            $fpmWwwFileContent .= sprintf("php_admin_value[%s] = \"%s\"\n", $iniName, $iniValue);
            $apacheConfigFileContent .= sprintf("    php_admin_value %s %s\n", $iniName, $iniValue);
        }
    }
    // Writing PHP-FPM worker pool file
    $fpmWwwFilePath = "$scenarioFolder/www.php-fpm.conf";
    $fpmWwwFileHandle = fopen($fpmWwwFilePath, 'w');
    $fpmWwwTemplate = file_get_contents('./templates/php-fpm.template.conf');
    fwrite($fpmWwwFileHandle, str_replace('__configs_will_go_here__', $fpmWwwFileContent, $fpmWwwTemplate));
    fclose($fpmWwwFileHandle);
    // Writing Apache config file
    $apacheConfigFilePath = "$scenarioFolder/www.apache.conf";
    $apacheConfigFileHandle = fopen($apacheConfigFilePath, 'w');
    $apacheConfigTemplate = file_get_contents('./templates/apache.template.conf');
    fwrite(
        $apacheConfigFileHandle,
        str_replace('__configs_will_go_here__', $apacheConfigFileContent, $apacheConfigTemplate)
    );
    fclose($apacheConfigFileHandle);

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
      - $fpmWwwFilePath:/etc/php-fpm.d/www.conf
      - $apacheConfigFilePath:/etc/httpd/conf.d/www.conf
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
