<?php

namespace DDTrace\Tests\Integrations\DiscordPHP\V10;

use DDTrace\Integrations\DiscordPHP\DiscordPHPIntegration;

class DiscordPHPTest extends \DDTrace\Tests\Common\IntegrationTestCase
{
    function testAllTopLevelPartsHandled()
    {
        $allFirstParts = [];
        foreach (glob(__DIR__ . "/vendor/team-reflex/discord-php/src/Discord/WebSockets/Events/*.php") as $file) {
            $contents = file_get_contents($file);
            if (preg_match('{\$this->factory->part\((.*)::class}', $contents, $matches)) {
                $class = $matches[1];
                if (preg_match("((?|use (.*) as $class;|use (.*\\\\$class);))", $contents, $matches)) {
                    $class = $matches[1];
                } else {
                    $class = rtrim($class);
                }
                $allFirstParts[] = $class;
            }
        }

        $handlers = DiscordPHPIntegration::getPartsParsers();
        $this->assertSame([], array_diff($allFirstParts, array_keys($handlers)));
    }
}