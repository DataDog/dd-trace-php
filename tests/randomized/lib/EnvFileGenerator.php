<?php

namespace RandomizedTests\Tooling;

class EnvFileGenerator
{
    public function generate($destination, $scenarioName)
    {
        file_put_contents(
            $destination,
            \str_replace(
                ['{{scenario_name}}'],
                [$scenarioName],
                file_get_contents(__DIR__ . '/templates/.env.template')
            )
        );
    }
}
