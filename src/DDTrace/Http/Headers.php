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

    /**
     * Provided an indexed array as input ['header_name: value'], it returns an associative array indexed by header
     * name: ['header_name' => 'value'].
     *
     * @param string[] $csvHeaders
     * @return string[]
     */
    public static function colonSeparatedValuesToHeadersMap(array $csvHeaders)
    {
        $map = [];

        foreach ($csvHeaders as $csv) {
            list($name, $value) = explode(':', $csv, 2);
            $map[trim($name)] = trim($value);
        }

        return $map;
    }
}
