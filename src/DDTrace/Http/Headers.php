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
     * Given a list of headers in the format ['hd1: value1', 'hd2: value2'] tells whether or not a given header,
     * e.g. 'hd1' is present.
     *
     * @param array|null $headers
     * @param string $name
     * @return bool
     */
    public static function headerExistsInColonSeparatedValues($headers, $name)
    {
        if (empty($headers)) {
           return false;
        }

        foreach ($headers as $header) {
            if ($name === substr($header, 0, strlen($header))) {
                return true;
            }
        }

        return false;
    }
}
