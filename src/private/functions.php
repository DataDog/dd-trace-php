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
            '/^[0-9a-f]{8}-?[0-9a-f]{4}-?[1-5][0-9a-f]{3}-?[89ab][0-9a-f]{3}-?[0-9a-f]{12}$/',
            '/^[0-9a-f]{8,128}$/',
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

    // Removing query string
    $uriPath = strstr($uriPath, '?', true) ? : $uriPath;

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
