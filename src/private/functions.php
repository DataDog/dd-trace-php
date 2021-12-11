<?php

namespace DDTrace\Private_;

use DDTrace\Http\Urls;

// Constants definition with [] content is not allowed in 5.4, so we need a class for 5.4 compatibility.
class Constants
{
    public static function getDefaultUriPathNormalizeRegexes()
    {
        return [
            '/^\d+$/',
            '/^[0-9a-fA-F]{8}-?[0-9a-fA-F]{4}-?[1-5][0-9a-fA-F]{3}-?[89abAB][0-9a-fA-F]{3}-?[0-9a-fA-F]{12}$/',
            '/^[0-9a-fA-F]{8,128}$/',
        ];
    }
}

/**
 * Given a uri path in the form '/user/123/path/Name' it returns a normalized path applying the correct outgoing rules:
 * e.g. '/user/?/path/?'
 * Note: it also accepts full urls which are preserved: http://example.com/int/123 ---> http://example.com/int/?
 *
 * @param string $uriPath
 * @return string
 */
function util_uri_normalize_outgoing_path($uriPath)
{
    return _util_uri_apply_rules($uriPath, /* incoming */ false);
}

/**
 * Given a uri path in the form '/user/123/path/Name' it returns a normalized path applying the correct incoming rules:
 * e.g. '/user/?/path/?'
 *
 * @param string $uriPath
 * @return string
 */
function util_uri_normalize_incoming_path($uriPath)
{
    return _util_uri_apply_rules($uriPath, /* incoming */ true);
}

/**
 * @param string $uriPath
 * @param boolean $incoming
 * @return string
 */
function _util_uri_apply_rules($uriPath, $incoming)
{
    if ('/' === $uriPath || '' === $uriPath || null === $uriPath) {
        return '/';
    }

    $uriPath = util_url_sanitize($uriPath);

    // We always expect leading slash if it is a pure path, while urls with RFC3986 complaint schemes are preserved.
    // See: https://tools.ietf.org/html/rfc3986#page-17
    if ($uriPath[0] !== '/' && 1 !== \preg_match('/^[a-z][a-zA-Z0-9+\-.]+:\/\//', $uriPath)) {
        $uriPath = '/' . $uriPath;
    }

    $fragmentRegexes = \ddtrace_config_path_fragment_regex();
    $incomingMappings = \ddtrace_config_path_mapping_incoming();
    $outgoingMappings = \ddtrace_config_path_mapping_outgoing();

    // We can now be in one of 3 cases:
    //   1) At least one among DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX and DD_TRACE_RESOURCE_URI_MAPPING_INCOMING|OUTGOING
    //      is defined.
    //   2) Nothing is defined, then apply *new normalization*.
    //      is defined. Then ignore legacy DD_TRACE_RESOURCE_URI_MAPPING and apply *new normalization*.
    //   2) Only DD_TRACE_RESOURCE_URI_MAPPING is defined, then apply *legacy normalization* for backward compatibility.
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
        return $normalizer->normalize($uriPath);
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
        Constants::getDefaultUriPathNormalizeRegexes(),
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

    return implode('/', $fragments);
}

/**
 * Removes query string, fragment and user information from a url.
 *
 * @param string $url
 * @param bool $dropUserInfo Optional. If `true`, removes the user information fragment instead of obfuscating it.
 *                           Defaults to `false`.
 * @return string
 */
function util_url_sanitize($url, $dropUserInfo = false)
{
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
            "/${userinfoPattern}:@/",
            "/${userinfoPattern}:${userinfoPattern}@/",
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

    return \str_replace('<sanitized>', '?', strstr($sanitizedUserinfo, '?', true) ?: $sanitizedUserinfo);
}

/**
 * Transform a host name (optionally with schema) or unix domain socket path into a service name-friendly string.
 *
 * @param string $hostOrUDS
 * @return string
 */
function util_normalize_host_uds_as_service($hostOrUDS)
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

/**
 * Extract all headers that are configured to be added as tags as an associative array [tag_name=>tag_value].

 * @param string[] $allHeaders
 * @param boolean $isRequest true for request headers, false for response headers.
 * @return string[]
 */
function util_extract_configured_headers_as_tags($allHeaders, $isRequest)
{
    $headersPassList = \ddtrace_config_http_headers();
    if (count($headersPassList) === 0) {
        return [];
    }

    $pattern = '/([^a-z0-9_\-:\/]){1}/i';
    $requestOrResponse = $isRequest ? 'request' : 'response';
    $prefix = "http.$requestOrResponse.headers.";
    $headersToTag = [];
    foreach ($allHeaders as $name => $value) {
        $lowerName = \trim(\strtolower($name));
        if (\in_array($lowerName, $headersPassList)) {
            $tagName = $prefix . \preg_replace($pattern, '_', $lowerName);
            $headersToTag[$tagName] = \trim($value);
        }
    }

    return $headersToTag;
}
