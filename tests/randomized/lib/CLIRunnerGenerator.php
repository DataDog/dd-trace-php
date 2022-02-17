<?php

namespace RandomizedTests\Tooling;

class CLIRunnerGenerator
{
    public function generate($destination, $seed, array $envs, array $inis)
    {
        $envsString = "";
        foreach ($envs as $envName => $envValue) {
            $envsString .= sprintf(" %s=%s", $envName, \escapeshellarg($envValue));
        }
        $inisString = "";
        foreach ($inis as $iniName => $iniValue) {
            if (is_bool($iniValue)) {
                $inisString .= sprintf(" -d %s=%s", $iniName, $iniValue ? 'true' : 'false');
            } else {
                $inisString .= sprintf(" -d %s=%s", $iniName, \escapeshellarg($iniValue));
            }
        }
        $template = \file_get_contents(__DIR__ . '/templates/cli-runner.template.sh');
        file_put_contents(
            $destination,
            str_replace(
                ['{{envs}}', '{{inis}}', '{{seed}}'],
                [$envsString, $inisString, $seed],
                $template
            )
        );
    }
}
