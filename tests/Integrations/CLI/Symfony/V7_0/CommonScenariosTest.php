<?php

namespace DDTrace\Tests\Integrations\CLI\Symfony\V7_0;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\Symfony\V6_2\CommonScenariosTest
{
    protected static function getConsoleScript()
    {
        return __DIR__ . '/../../../../Frameworks/Symfony/Version_7_0/bin/console';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        $workingDir = __DIR__ . '/../../../../Frameworks/Symfony/Version_7_0/';
        $output = [];
        $returnVar = 0;
        $command = "composer show symfony/console --working-dir=$workingDir | sed -n '/versions/s/^[^0-9]\+\([^,]\+\).*$/\\1/p'";
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            return null;
        }

        return trim($output[0]);
    }
}
