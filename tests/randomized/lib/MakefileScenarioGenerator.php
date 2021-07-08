<?php

namespace RandomizedTests\Tooling;

require_once __DIR__ . '/Utils.php';

class MakefileScenarioGenerator
{
    public function generate($destination, $scenarioName)
    {
        Utils::writeTemplate(
            $destination,
            __DIR__ . '/templates/Makefile.scenario.template',
            [
                'scenario_name' => $scenarioName,
            ]
        );
    }
}
