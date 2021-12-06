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
     * Removes query string and fragment and user information from a url.
     *
     * @param string $url
     * @param bool $dropUserInfo Optional. If `true`, removes the user information fragment instead of obfuscating it.
     *                           Defaults to `false`.
     */
    public static function sanitize($url, $dropUserInfo = false)
    {
        /* The implementation of this method is an exact replica of \DDTrace\Private_\util_url_sanitize() - and has to
         * be kept in sync - until \DDTrace\Private_\util_url_sanitize() will be removed as part of the PHP->C
         * migration.
         */
        $sanitized = "";

        /* This operation should be idem-potent, but http://?:?@... breaks parse_url. We have to remove it and add
         * it back.
         */
        $sanitizedUserInfo = null;
        if (false !== \strpos($url, '?:?@')) {
            $url = \str_replace('?:?@', '', $url);
            $sanitizedUserInfo = '?:?@';
        } elseif (false !== \strpos($url, '?:@')) {
            $url = \str_replace('?:@', '', $url);
            $sanitizedUserInfo = '?:@';
        }

        $parsedUrl = \parse_url($url);

        if (isset($parsedUrl['scheme'])) {
            $sanitized .= $parsedUrl['scheme'] . '://';
        }

        if (isset($parsedUrl['user'])) {
            $sanitized .= $dropUserInfo ? '' : '?:';
            /* Password isset() in the array but empty() in valid url "http://user:@domain.com" (meaning no password).
         * see: https://datatracker.ietf.org/doc/html/rfc3986#section-3.2.1
         */
            if (!empty($parsedUrl['pass'])) {
                $sanitized .= $dropUserInfo ? '' : '?';
            }
            $sanitized .= $dropUserInfo ? '' : '@';
        } elseif ($sanitizedUserInfo && !$dropUserInfo) {
            $sanitized .= $sanitizedUserInfo;
        }

        if (isset($parsedUrl['host'])) {
            $sanitized .= $parsedUrl['host'];
            if (isset($parsedUrl['port'])) {
                $sanitized .= ':' . $parsedUrl['port'];
            }
            if (isset($parsedUrl['path'])) {
                $sanitized .= $parsedUrl['path'];
            }
        } elseif (isset($parsedUrl['path'])) {
            /* If the scheme is not present, parse_url() returns the host as part of the path,
         * for example: array (
         *   'path' => 'my_user:@some_url.com/path/',
         * )
         */
            if (false === \strpos($parsedUrl['path'], '@')) {
                $sanitized .= $parsedUrl['path'];
            } else {
                list($userInfo, $restOfPath) = \explode('@', $parsedUrl['path'], 2);
                $userInfoParts = \explode(':', $userInfo, 2);
                $sanitized .= $dropUserInfo ? '' : '?:';
                if (!empty($userInfoParts[1])) {
                    $sanitized .= $dropUserInfo ? '' : '?';
                }
                $sanitized .= ($dropUserInfo ? '' : '@') . $restOfPath;
            }
        }
        return $sanitized;
    }

    /**
     * Extracts the hostname of a given URL
     *
     * @param string $url
     * @return string
     */
    public static function hostname($url)
    {
        $url = self::sanitize($url, true);
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
