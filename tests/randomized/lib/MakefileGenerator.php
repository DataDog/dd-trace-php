<?php

namespace RandomizedTests\Tooling;

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
        $template = \file_get_contents(__DIR__ . '/Makefile.template');
        file_put_contents(
            $destination,
            str_replace(
                ['{{test_target}}'],
                [$targetsString],
                $template
            )
        );
    }
}
