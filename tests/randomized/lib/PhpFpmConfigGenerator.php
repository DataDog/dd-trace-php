<?php

namespace RandomizedTests\Tooling;

class PhpFpmConfigGenerator
{
    public function generate($destination, array $envs, array $inis)
    {
        $envsString = "";
        foreach ($envs as $envName => $envValue) {
            $envsString .= "env[$envName] = \"$envValue\"\n";
        }
        $inisString = "";
        foreach ($inis as $iniName => $iniValue) {
            if (is_bool($iniValue)) {
                $inisString .= sprintf("php_admin_flag[%s] = %s\n", $iniName, $iniValue ? 'on' : 'off');
            } else {
                $inisString .= sprintf("php_admin_value[%s] = \"%s\"\n", $iniName, $iniValue);
            }
        }
        $template = \file_get_contents(__DIR__ . '/php-fpm.template.conf');
        file_put_contents(
            $destination,
            str_replace(
                ['{{envs}}', '{{inis}}'],
                [$envsString, $inisString],
                $template
            )
        );
    }
}
