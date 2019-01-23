<?php

namespace DDTrace\Util;

/**
 * Utility functions to handle version numbers and matching.
 */
final class Versions
{
    /**
     * @param string $version
     * @return bool
     */
    public static function phpVersionMatches($version)
    {
        return self::versionMatches(phpversion(), $version);
    }

    /**
     * @param string $expected
     * @param string $specimen
     * @return bool
     */
    public static function versionMatches($expected, $specimen)
    {
        $expectedFragments = self::asIntArray($expected);
        $specimenFragments = self::asIntArray($specimen);

        if (empty($expectedFragments) || empty($specimenFragments)) {
            return false;
        }

        for ($i = 0; $i < count($expectedFragments); $i++) {
            if ($specimenFragments[$i] !== $expectedFragments[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Converts a string '1.2.3' to an array of integers [1, 2, 3]
     *
     * @param string $versionAsString
     * @return int[]
     */
    private static function asIntArray($versionAsString)
    {
        return array_values(
            array_filter(
                array_map(
                    function ($fragment) {
                        return is_numeric($fragment) ? intval($fragment) : null;
                    },
                    explode('.', $versionAsString)
                )
            )
        );
    }
}
