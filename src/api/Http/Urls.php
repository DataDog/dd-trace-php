<?php

namespace DDTrace\Http;

use DDTrace\Obfuscation\WildcardToRegex;

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
        '<(/)([0-9a-f]{8}-?[0-9a-f]{4}-?[1-5][0-9a-f]{3}-?[89ab][0-9a-f]{3}-?[0-9a-f]{12})(/|$)>i',
        // 32-512 bit hex hashes
        '<(/)([0-9a-f]{8,128})(/|$)>i',
        // int's
        '<(/)([0-9]+)(/|$)>',
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
        foreach ($patternsWithWildcards as $pattern) {
            $this->replacementPatterns[] = WildcardToRegex::convert($pattern);
        }
    }

    /**
     * Removes query string and fragment from a url.
     *
     * @param string $url
     * @return string
     */
    public static function sanitize($url)
    {
        return strstr($url, '?', true) ?: (string) $url;
    }

    /**
     * Extracts the hostname of a given URL
     *
     * @param string $url
     * @return string
     */
    public static function hostname($url)
    {
        $unparsableUrl = 'unparsable-host';
        $parts = \parse_url($url);
        if (!$parts) {
            return $unparsableUrl;
        }

        if (isset($parts['host'])) {
            return $parts['host'];
        }

        if (empty($parts['path'])) {
            return $unparsableUrl;
        }

        $path = $parts['path'];
        if (\substr($path, 0, 1) === '/') {
            // If the user by mistake directly provided an abs path, guzzle and curl
            // will let a request go through, but there will be an error.
            return 'unknown-host';
        }

        $pathFragments = \explode('/', $path);
        return $pathFragments[0];
    }

    /**
     * Metadata keys must start with [a-zA-Z:] so IP addresses,
     * for example, need to be prefixed with a valid character.
     *
     * Note: then name of this function is misleading, as it should actually be normalizeUrlForService(), but since this
     * part of the public API, we keep it like this and discuss a future deprecation.
     *
     * @param string $url
     * @return string
     */
    public static function hostnameForTag($url)
    {
        $url = \trim($url);

        // Common UDS protocols are treated differently as they are not recognized by parse_url()
        $knownUnixProtocols = ['uds', 'unix', 'http+unix', 'https+unix'];
        foreach ($knownUnixProtocols as $protocol) {
            $length = \strlen($protocol);
            if ($protocol . '://' === \substr($url, 0, $length + 3)) {
                return 'socket-' . Urls::normalizeFileSystemPath(\substr($url, $length + 3));
            }
        }

        return 'host-' . self::hostname($url);
    }

    /**
     * Replaces all groups of non-(alphabetical chatacters|numbers|dots) with character '-'.
     *
     * @param string $url
     * @return string
     */
    private static function normalizeFileSystemPath($url)
    {
        return \trim(\preg_replace('/[^0-9a-zA-Z\.]+/', '-', \trim($url)), '-');
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
        return preg_replace(self::$defaultPatterns, '$1?$3', $url);
    }
}
