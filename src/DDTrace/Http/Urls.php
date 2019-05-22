<?php

namespace DDTrace\Http;

/**
 * A utility class that provides methods to work on urls
 */
class Urls
{
    private static $defaultPatterns = [
        // UUID's
        // [1-5] = UUID version
        // [89ab] = UUID variant
        // @see https://en.wikipedia.org/wiki/Universally_unique_identifier#Format
        '|\b([0-9a-f]{8}-?[0-9a-f]{4}-?[1-5][0-9a-f]{3}-?[89ab][0-9a-f]{3}-?[0-9a-f]{12})\b|i',
        // 16-512 bit hex hashes
        '|\b([0-9a-f]{4,128})\b|i',
        // int's
        '|\b([0-9]+)\b|',
    ];

    private $replacementPatterns = [];

    /**
     * Inject URL replacement patterns using '*' and '$*' as wildcards
     * The '*' wildcard will match one or more characters to be replaced with '?'
     * The '$*' wildcard will match one or more characters without replacement
     *
     * @param string[] $patternsWithWildcards
     */
    public function __construct(array $patternsWithWildcards = [])
    {
        $this->replacementPatterns = array_map(
            '\DDTrace\Configuration\WildcardToRegex::convert',
            $patternsWithWildcards
        );
    }

    /**
     * Removes query string and fragment from a url.
     *
     * @param string $url
     * @return string
     */
    public static function sanitize($url)
    {
        return strstr($url, '?', true) ?: $url;
    }

    /**
     * Reduces cardinality of a url.
     *
     * @param string $url
     * @return string
     */
    public function normalize($url)
    {
        $url = self::sanitize($url);
        foreach ($this->replacementPatterns as $regexReplacement) {
            list($regex, $replacement) = $regexReplacement;
            $replacedCount = 0;
            $url = preg_replace($regex, $replacement, $url, -1, $replacedCount);
            if ($replacedCount > 0) {
                return $url;
            }
        }
        // Fall back to default replacement rules
        return preg_replace(self::$defaultPatterns, '?', $url);
    }
}
