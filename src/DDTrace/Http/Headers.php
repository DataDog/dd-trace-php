<?php

namespace DDTrace\Http;


/**
 * A utility class that provides methods to work on http headers
 */
class Headers
{
    /**
     * Provided an associative array as input ['key' => 'value] , it returns an indexed array where each value is
     * 'key: value'.
     *
     * @param string[] $headers
     * @return string[]
     */
    public static function headersMapToColonSeparatedValues(array $headers)
    {
        $colonSeparatedValues = [];

        foreach ($headers as $key => $value) {
            $colonSeparatedValues[] = "$key: $value";
        }

        return $colonSeparatedValues;
    }
}
