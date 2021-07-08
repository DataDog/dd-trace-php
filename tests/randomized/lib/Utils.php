<?php

namespace RandomizedTests\Tooling;

class Utils
{
    /**
     * Returns true $percent of times this function is invoked. Otherwise false.
     *
     * @param int $percent
     * @return bool
     */
    public static function percentOfTimes($percent)
    {
        return rand(0, 100) <= $percent;
    }

    /**
     * Creates a $destination file from a template at $template after replacing $substitutions.
     *
     * @param string $destination Abs path to the file where the result will be saved.
     * @param string $template Abs path of the template file containing mustache-like '{{name}}' substitutions.
     * @param array $substitutions Associative array ['substitution_name' => 'substitution_value'].
     */
    public static function writeTemplate($destination, $template, array $substitutions = [])
    {
        $needles = \array_map(
            function ($key) {
                return "{{{$key}}}";
            },
            array_keys($substitutions)
        );
        $replaces = array_values($substitutions);
        file_put_contents(
            $destination,
            \str_replace(
                $needles,
                $replaces,
                \file_get_contents($template)
            )
        );
    }
}
