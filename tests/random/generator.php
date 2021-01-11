<?php

/*
Critical issues and scenarios
- https://github.com/DataDog/dd-trace-php/issues/1090
    - installation: pecl
- https://github.com/DataDog/dd-trace-php/pull/1121
    - PHP 7.0-7.2
    - one request with pdo + memcached
- https://github.com/DataDog/dd-trace-php/pull/1124
    - PHP 7.*
    - curl in request
- https://github.com/DataDog/dd-trace-php/pull/1022
    - DD_TRACE_ENABLED=0
    - a warn emitted
    - PHP 7
- https://github.com/DataDog/dd-trace-php/issues/1012
    - PHP 7
- https://github.com/DataDog/dd-trace-php/pull/1001
    - PHP 5.4-5.6
    - other conditions to be investigated
*/

const NUMBER_OF_SCENARIOS = 5;
const MAX_ENV_MODIFICATIONS = 5;

const DOCKER_IMAGES = [
    'centos:7' => [
        'php' => [
            '7.4',
        ],
    ],
];

const INSTALLATION = [
    'pecl',
    'package',
];

const ENVS = [
    'DD_ENV' => ['some_env'],
    'DD_SERVICE' => ['my_custom_service'],
    'DD_TRACE_ENABLED' => ['false'],
    'DD_TRACE_DEBUG' => ['true'],
    'DD_AGENT_HOST' => ['wrong_host'],
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

function main()
{
    for ($iteration = 0; $iteration < NUMBER_OF_SCENARIOS; $iteration++) {
        $selectedDockerImage = array_rand(DOCKER_IMAGES);
        $availablePHPVersions = DOCKER_IMAGES[$selectedDockerImage]['php'];
        $selectedPhpVersion = $availablePHPVersions[array_rand($availablePHPVersions)];
        $selectedInstallationMethod = INSTALLATION[array_rand(INSTALLATION)];
        $numberOfEnvModifications = rand(0, MAX_ENV_MODIFICATIONS);
        $envModifications = [];
        for ($envModification = 0; $envModification < $numberOfEnvModifications; $envModification++) {
            $currentEnv = array_rand(ENVS);
            $availableValues = ENVS[$currentEnv];
            $envModifications[$currentEnv] = $availableValues[array_rand($availableValues)];
        }
        $seed = rand();
        echo "Selected: " . $selectedDockerImage . "\n";
        echo "   PHP  : " . $selectedPhpVersion . "\n";
        echo "   Seed : " . $seed . "\n";
        echo "   Insta: " . $selectedInstallationMethod . "\n";
        echo "   Envs : " . var_export($envModifications, 1) . "\n";
    }
}

main();
