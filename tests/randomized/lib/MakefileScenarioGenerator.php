<?php

namespace RandomizedTests\Tooling;

class MakefileScenarioGenerator
{
    public function generate($destination, $scenarioName)
    {
        $template = \file_get_contents(__DIR__ . '/templates/Makefile.scenario.template');
        file_put_contents(
            $destination,
            str_replace(
                ['{{scenario_name}}'],
                [$scenarioName],
                $template
            )
        );
    }
}
