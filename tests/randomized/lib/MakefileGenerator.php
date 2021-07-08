<?php

namespace RandomizedTests\Tooling;

require_once __DIR__ . '/Utils.php';

class MakefileGenerator
{
    public function generate($destination, array $scenarioNames)
    {
        $targetsString = sprintf(
            implode(
                " \\\n    ",
                array_map(
                    function ($identifier) {
                        return "test.scenario.$identifier";
                    },
                    $scenarioNames
                )
            )
        );

        Utils::writeTemplate(
            $destination,
            __DIR__ . '/templates/Makefile.template',
            [
                'test_targets' => $targetsString,
            ]
        );
    }
}
