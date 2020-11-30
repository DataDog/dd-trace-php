<?php

namespace RandomTesting;

const NUMBER_OF_ENVS = 100;
const ENVS_DIRECTORY_PATTERN = __DIR__ . '/envs/*';

# 'NAME' => [ ..., <value> => <percent of times> , ...]
# 'null' will result in environment variable not set.
const ENVIRONMENT_VARIABLES = [
    'DD_TRACE_ENABLED' => [null, 'false'],
    'DD_AGENT_HOST' => ['agent', 'non_existing'],
    'DD_TRACE_MEASURE_COMPILE_TIME' => [null, 'false'],
    'DD_TRACE_SAMPLE_RATE' => [null, '0.1'],
    'DD_TRACE_ANALYTICS_ENABLED' => [null, 'true'],
];

function percentOfCases($percent)
{
    return \rand(0, 99) < $percent;
}

function deleteExistingEnvs($envsRootPattern)
{
    foreach(glob($envsRootPattern) as $target){
        if(\is_dir($target)){
            \error_log('Removing existing env: ' . $target);
            $files = glob( $target . '/*', GLOB_MARK );
            foreach($files as $file){
                unlink($file);
            }
            rmdir( $target );
        }
    }
}

function randomlyGenerateNewEnvs($maxNumber, $environmentVariables)
{
    for ($envIdx = 1; $envIdx <= $maxNumber; $envIdx ++)
    {
        $envVariables = [];
        // TODO
    }
}

function main()
{
    deleteExistingEnvs(ENVS_DIRECTORY_PATTERN);
    randomlyGenerateNewEnvs(NUMBER_OF_ENVS, ENVIRONMENT_VARIABLES);
}

main();

echo "Done\n";
