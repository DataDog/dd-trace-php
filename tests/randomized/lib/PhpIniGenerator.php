<?php

namespace RandomizedTests\Tooling;

class PhpIniGenerator
{
    public function generate($destination, array $inis)
    {
        $inisString = "";
        foreach ($inis as $iniName => $iniValue) {
            $inisString .= sprintf("%s = %s\n", $iniName, is_bool($iniValue) ? $iniValue ? 'on' : 'off' : $iniValue);
        }
        file_put_contents($destination, $inisString);
    }
}
