<?php

namespace RandomizedTests\Tooling;

require_once __DIR__ . '/Utils.php';

class EnvFileGenerator
{
    public function generate($destination, $scenarioName)
    {
        Utils::writeTemplate(
            $destination,
            __DIR__ . '/templates/.env.template',
            [
                'scenario_name' => $scenarioName,
            ]
        );
    }
}
