<?php

namespace DDTrace\Util;

use DDTrace\Http\Urls;

/**
 * @internal This class may be changed any time (private API) and shall not be relied on in consumer code.
 */
class Normalizer
{
    private static function getDefaultUriPathNormalizeRegexes()
    {
        return [
            '/^\d+$/',
            '/^[0-9a-fA-F]{8}-?[0-9a-fA-F]{4}-?[1-5][0-9a-fA-F]{3}-?[89abAB][0-9a-fA-F]{3}-?[0-9a-fA-F]{12}$/',
            '/^[0-9a-fA-F]{8,128}$/',
        ];
    }

    /**
     * Given a uri path in the form '/user/123/path/Name' it returns a normalized path with the correct outgoing rules:
     * e.g. '/user/?/path/?'
     * Note: it also accepts full urls which are preserved: http://example.com/int/123 ---> http://example.com/int/?
     *
     * @param string $uriPath
     * @return string
     */
    public static function uriNormalizeOutgoingPath($uriPath)
    {
        return self::uriApplyRules($uriPath, /* incoming */ false);
    }

    /**
     * Given a uri path in the form '/user/123/path/Name' it returns a normalized path with the correct incoming rules:
     * e.g. '/user/?/path/?'
     *
     * @param string $uriPath
     * @return string
     */
    public static function uriNormalizeincomingPath($uriPath)
    {
        return self::uriApplyRules($uriPath, /* incoming */ true);
    }

    private static function decodeConfigSet($iniName)
    {
        $ini = \ini_get($iniName);
        return $ini == "" ? [] : array_map("trim", explode(",", $ini));
    }

    /**
     * @param string $uriPath
     * @param boolean $incoming
     * @return string
     */
    private static function uriApplyRules($inputUriPath, $incoming)
    {
        if ('/' === $inputUriPath || '' === $inputUriPath || null === $inputUriPath) {
            return '/';
        }

        $uriPath = self::urlSanitize($inputUriPath, false, true);
        if ($uriPath === '') {
            return '/' . self::cleanQueryString($inputUriPath, "datadog.trace.resource_uri_query_param_allowed");
        }

        // We always expect leading slash if it is a pure path, while urls with RFC3986 complaint schemes are preserved.
        // See: https://tools.ietf.org/html/rfc3986#page-17
        if ($uriPath[0] !== '/' && 1 !== \preg_match('/^[a-z][a-zA-Z0-9+\-.]+:\/\//', $uriPath)) {
            $uriPath = '/' . $uriPath;
        }

        $fragmentRegexes = self::decodeConfigSet("datadog.trace.resource_uri_fragment_regex");
        $incomingMappings = self::decodeConfigSet("datadog.trace.resource_uri_mapping_incoming");
        $outgoingMappings = self::decodeConfigSet("datadog.trace.resource_uri_mapping_outgoing");

        // We can now be in one of 3 cases:
        //   1) At least one of DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX and DD_TRACE_RESOURCE_URI_MAPPING_INCOMING|OUTGOING
        //      is defined.
        //   2) Nothing is defined, then apply *new normalization*.
        //      is defined. Then ignore legacy DD_TRACE_RESOURCE_URI_MAPPING and apply *new normalization*.
        //   2) Only DD_TRACE_RESOURCE_URI_MAPPING is set, then apply *legacy normalization* for backward compatibility.
        //   3) Nothing is defined, then apply *new normalization*.

        // DEPRECATED: Applying legacy normalization for backward compatibility if preconditions are matched.
        $legacyMappings = getenv('DD_TRACE_RESOURCE_URI_MAPPING');
        if (
            empty($fragmentRegexes)
            && empty($incomingMappings)
            && empty($outgoingMappings)
            && !empty($legacyMappings)
        ) {
            $normalizer = new Urls(explode(',', $legacyMappings));
            return $normalizer->normalize($uriPath)
                . self::cleanQueryString($inputUriPath, "datadog.trace.resource_uri_query_param_allowed");
        }


        $result = $uriPath;

        foreach (($incoming ? $incomingMappings : $outgoingMappings) as $rawMapping) {
            $normalizedMapping = trim($rawMapping);
            if ('' === $normalizedMapping) {
                continue;
            }

            $regex = '/\\/' . str_replace('*', '[^\\/?#]+', str_replace('/', '\\/', $normalizedMapping)) . '/';
            $replacement = '/' . str_replace('*', '?', $normalizedMapping);
            $result = preg_replace($regex, $replacement, $result);
        }

        // It's easier to work on a fragment basis. So we take a $uriPath and we normalize it to a meanigful
        // array of fragments.
        // E.g. $fragments will contain:
        //    '/some//path/123/and/something-else/' =====> ['some', '', 'path', '123', 'and', 'something-else']
        //          ^^...note that empty fragments are preserved....^^
        $fragments = explode('/', $result);

        $defaultPlusConfiguredfragmentRegexes = array_merge(
            self::getDefaultUriPathNormalizeRegexes(),
            $fragmentRegexes
        );
        // Now applying fragment regex normalization
        foreach ($defaultPlusConfiguredfragmentRegexes as $fragmentRegex) {
            // Leading and trailing slashes in regex patterns from envs are optional and we suggest not to use them
            // in docs as it might be source of confusion given the context where `/` is also the path separator.
            $regexWithSlash = '/' . trim($fragmentRegex, '/ ') . '/';
            foreach ($fragments as &$fragment) {
                $matchResult = @preg_match($regexWithSlash, $fragment);
                if (1 === $matchResult) {
                    $fragment = '?';
                }
            }
        }

        return implode('/', $fragments)
            . self::cleanQueryString($inputUriPath, "datadog.trace.resource_uri_query_param_allowed");
    }

    /**
     * Removes query string, fragment and user information from a url.
     *
     * @param string $url
     * @param bool $dropUserInfo Optional. If `true`, removes the user information fragment instead of obfuscating it.
     *                           Defaults to `false`.
     * @return string
     */
    public static function urlSanitize($url, $dropUserInfo = false, $trimQueryString = false)
    {
        $url = (string)$url;

        /* The implementation of this method is an exact replica of DDTrace\Http\Urls::sanitize() - and has to
         * be kept in sync - until this method will be removed as part of the PHP->C migration.
         *
         * Definition of unreserved and sub-delims in https://datatracker.ietf.org/doc/html/rfc3986#page-18
         * Note: this implementation detects the following false positives and sanitize them even if they are valid and
         * should not be sanitized (see: https://datatracker.ietf.org/doc/html/rfc3986#section-3.3)
         *   - path fragments like /before/<something>:@<anything>/after => /before/?:@<anything>/after
         *   - path fragments like /before/<something>:<something>@<anything>/after => /before/?:?@<anything>/after
         * However, given how rare they are and the fact that we over-sanitize (rather than under-sanitize), it is
         * believed that this represents a good trade-off between correctness and complexity.
         */
        $userinfoPattern = "[a-zA-Z0-9\-._~!$&'()*+,;=%?]+";
        /*                   \            /\         /||
         *                    \          /  \       / |↳ supports urls that might already be sanitized
         *                     \        /    \_____/  ↳ percent escape (hexadecimal already included in 'unreserved')
         *                      \______/        ↳ sub-delims https://datatracker.ietf.org/doc/html/rfc3986#section-2.2
         *                          ↳ unreserved https://datatracker.ietf.org/doc/html/rfc3986#section-2.3
         */

        $sanitizedUserinfo = preg_replace(
            [
                "/{$userinfoPattern}:@/",
                "/{$userinfoPattern}:{$userinfoPattern}@/",
            ],
            [
                $dropUserInfo ? '' : '<sanitized>:@',
                $dropUserInfo ? '' : '<sanitized>:<sanitized>@',
            ],
            /*
             * Skip the query string. There can only be one question mark as it is a reserved word
             * and only allowed between path and query.
             * See: https://datatracker.ietf.org/doc/html/rfc3986#section-3
             */
            $url
        );

        $urlNoQueryString = strstr($sanitizedUserinfo, '?', true);
        return ($urlNoQueryString === '' ? '' :
                    \str_replace('<sanitized>', '?', $urlNoQueryString ?: $sanitizedUserinfo))
            . ($trimQueryString ? "" : self::cleanQueryString($url, "datadog.trace.http_url_query_param_allowed"));
    }

    public static function sanitizedQueryString()
    {
        return self::cleanQueryString(
            isset($_SERVER["QUERY_STRING"]) ? $_SERVER["QUERY_STRING"] : "",
            "datadog.trace.http_url_query_param_allowed"
        );
    }

    private static function normalizeString(string $str)
    {
        $noSpaces = str_replace(' ', '', $str);
        return trim(preg_replace('/[^a-zA-Z0-9.\_]+/', '-', $noSpaces), '- ');
    }

    private static function generateFilteredPostFields(string $postKey, mixed $postVal, array $whitelist): array
    {
        if (is_array($postVal)) {
            $filteredPostFields = [];
            foreach ($postVal as $key => $val) {
                $key = self::normalizeString($key);
                // If the current postkey is the empty string, we don't want ot add a '.' to the beginning of the key
                $filteredPostFields = array_merge(
                    $filteredPostFields,
                    self::generateFilteredPostFields(
                        (strlen($postKey) == 0 ? '' : $postKey . '.') . $key,
                        $val,
                        $whitelist
                    )
                );
            }
            return $filteredPostFields;
        } else {
            // Check if the current postKey is in the whitelist
            if (in_array($postKey, $whitelist)) {
                // The postkey is in the whitelist, return the value as is
                return [$postKey => $postVal];
            } elseif (in_array('*', $whitelist)) { // '*' is wildcard
                // Concatenate the postkey and postval into '<postkey>=<postval>'
                $postField = "$postKey=$postVal";

                // Match it with the regex to redact if needed
                $obfuscationRegex = \ini_get('datadog.trace.obfuscation_query_string_regexp');
                $obfuscationRegex = '(' . $obfuscationRegex . ')';
                if (preg_match($obfuscationRegex, $postField)) {
                    return [$postKey => '<redacted>'];
                } else {
                    return [$postKey => $postVal];
                }
            } else {
                // The postkey is not in the whitelist, and no wildcard set, then always use <redacted>
                return [$postKey => '<redacted>'];
            }
        }
    }

    private static function cleanRequestBody(array $requestBody, string $allowedParams): array
    {
        $whitelist = self::decodeConfigSet($allowedParams);
        return self::generateFilteredPostFields('', $requestBody, $whitelist);
    }

    public static function sanitizePostFields(array $postFields): array
    {
        return self::cleanRequestBody(
            $postFields,
            "datadog.trace.http_post_data_param_allowed"
        );
    }

    private static function cleanQueryString($queryString, $allowedSetting)
    {
        if (!preg_match('(\?\K[^:?@#][^#]*)', $queryString, $m)) {
            return "";
        }

        $queryString = $m[0];
        if ($queryString === "") {
            return "";
        }

        $allowedSet = self::decodeConfigSet($allowedSetting);

        if (empty($allowedSet)) {
            return "";
        }

        if ($allowedSet == ["*"]) {
            $obfuscationRegex = \ini_get('datadog.trace.obfuscation_query_string_regexp');
            if ($obfuscationRegex !== "") {
                $obfuscationRegex = '(' . $obfuscationRegex . ')';
                $queryString = preg_replace($obfuscationRegex, '<redacted>', $queryString);
            }

            return "?$queryString";
        }

        $allowedSet = array_flip($allowedSet);
        $preserve = array_filter(explode('&', $queryString), function ($part) use ($allowedSet) {
            return isset($allowedSet[explode('=', $part)[0]]);
        });

        if (empty($preserve)) {
            return "";
        }

        return '?' . implode('&', $preserve);
    }

    /**
     * Transform a host name (optionally with schema) or unix domain socket path into a service name-friendly string.
     *
     * @param string $hostOrUDS
     * @return string
     */
    public static function normalizeHostUdsAsService($hostOrUDS)
    {
        if (null === $hostOrUDS) {
            return '';
        }

        // Note, we do not use PHP's `parse_url()` because it would require tricks to be compatible with UDS file names.
        $parts = \explode("://", $hostOrUDS);
        $noSchema = count($parts) > 1 ? $parts[count($parts) - 1] : $hostOrUDS;
        $noSpaces = \str_replace(' ', '', $noSchema);

        return \trim(preg_replace('/[^a-zA-Z0-9.\_]+/', '-', $noSpaces), '- ');
    }
}
