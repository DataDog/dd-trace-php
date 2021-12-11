<?php

namespace RandomizedTests\Tooling;

require_once __DIR__ . '/Utils.php';

class ApacheConfigGenerator
{
    public function generate($destination, array $envs, array $inis)
    {
        $substitutions = [
            'envs' => '',
            'inis' => '',
        ];
        foreach ($envs as $envName => $envValue) {
            $substitutions['envs'] .= "    SetEnv $envName \"$envValue\"\n";
        }
        foreach ($inis as $iniName => $iniValue) {
            if (is_bool($iniValue)) {
                $substitutions['inis'] .= sprintf("    php_admin_flag %s %s\n", $iniName, $iniValue ? 'on' : 'off');
            } else {
                $substitutions['inis'] .= sprintf("    php_admin_value %s %s\n", $iniName, $iniValue);
            }
        }

        Utils::writeTemplate(
            $destination,
            __DIR__ . '/templates/apache.template.conf',
            $substitutions
        );
    }
}
