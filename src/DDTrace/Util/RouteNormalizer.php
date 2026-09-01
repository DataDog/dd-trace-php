<?php

namespace DDTrace\Util;

/** @internal */
class RouteNormalizer
{
    /**
     * Normalize a Laravel route URI.
     *
     * @param string $routeUri     URI from $route->uri(), e.g. "/users/{id}/{format?}"
     * @param array  $matchedParams Parameters from $route->parameters(); used to resolve optionals.
     *                              Note: includes framework-injected defaults; caller must exclude them.
     * @return string|null
     */
    public static function normalizeFromLaravel(string $routeUri, array $matchedParams = [])
    {
        return self::normalizeBraceRoute($routeUri, $matchedParams);
    }

    /**
     * Normalize a Symfony route path.
     *
     * @param string     $path          Path template, e.g. "/users/{id}"
     * @param array|null $matchedParams Params actually present in the URL path (not including
     *                                  route defaults); when provided, absent params are dropped
     * @return string|null
     */
    public static function normalizeFromSymfony(string $path, $matchedParams = null)
    {
        if ($matchedParams !== null) {
            // Mark params absent from the URL as optional so normalizeBraceSegment drops them.
            // Use [^}?:]+ to match any param name including UTF-8 characters.
            $path = preg_replace_callback(
                '/\{([^}?:]+)\}/',
                static function ($m) use ($matchedParams) {
                    return array_key_exists($m[1], $matchedParams) ? $m[0] : '{' . $m[1] . '?}';
                },
                $path
            );
            return self::normalizeBraceRoute($path, $matchedParams);
        }
        return self::normalizeBraceRoute($path, []);
    }

    /**
     * Normalize a Laminas route template.
     *
     * Laminas uses :param for dynamic parameters and [...] for optional sections.
     * The Wildcard route type produces "/*" which is treated as a catch-all.
     *
     * @param string      $template      Template from httpRouteTemplateFromMatchedRoute()
     * @param array       $matchedParams Matched params from $routeMatch->getParams()
     * @param string|null $urlPath       The raw request URL path; filters out optional sections
     *                                   whose params were injected by middleware rather than
     *                                   matched from the URL (e.g. Laminas API Tools
     *                                   VersionListener sets :version even without a /v1/ prefix)
     * @return string|null
     */
    public static function normalizeFromLaminas(string $template, array $matchedParams = [], $urlPath = null)
    {
        $expanded = self::expandBracketOptionals($template, $matchedParams, ':', $urlPath);

        // Replace wildcard /* with a param name that doesn't collide with existing params
        if (preg_match('#/\*$#', $expanded)) {
            $wildcardName = self::uniqueParamName($expanded, ':');
            $expanded = preg_replace('#/\*$#', '/{' . $wildcardName . '}', $expanded);
        }

        // Segment routes use :param; Regex routes use %param% (spec format) — handle both.
        // Detect Regex routes before conversion so we can apply URL-based param filtering.
        $hasPercentParams = (bool) preg_match('/%([a-zA-Z_][a-zA-Z0-9_]*)%/', $expanded);
        $braceFormat = self::colonParamsToBraces($expanded);
        $braceFormat = self::percentParamsToBraces($braceFormat);

        // For Regex routes the defaults array injects values into matchedParams even for
        // optional captures absent from the URL (e.g. format='html' when no .html in path).
        // Use the URL path to determine which params were actually URL-matched.
        if ($hasPercentParams && $urlPath !== null) {
            $urlMatchedParams = self::inferSymfonyRouteParams($braceFormat, $urlPath);
            $braceFormat = preg_replace_callback(
                '/\{([^}?:]+)\}/',
                static function ($m) use ($urlMatchedParams) {
                    return array_key_exists($m[1], $urlMatchedParams) ? $m[0] : '{' . $m[1] . '?}';
                },
                $braceFormat
            );
            return self::normalizeBraceRoute($braceFormat, $urlMatchedParams);
        }

        return self::normalizeBraceRoute($braceFormat, $matchedParams);
    }

    /**
     * Normalize a WordPress matched_rule (regex).
     *
     * WordPress route matching uses regex rules like "^blog/([^/]+)/?$".
     * Named parameters are not available; placeholders param1, param2, … are used.
     *
     * @param string      $matchedRule Value of $wp->matched_rule
     * @param string|null $urlPath     Value of $wp->request; used to detect which
     *                                 optional capture groups actually participated
     *                                 in the match, so phantom segments are not emitted.
     * @return string|null
     */
    public static function normalizeFromWordPress(string $matchedRule, $urlPath = null)
    {
        // Re-run the regex against the actual URL to find which capture groups matched.
        // Tracks each group individually so gaps from optional groups (e.g. (?:(...))?)
        // that didn't participate are skipped instead of emitting phantom params.
        $matchedGroups = null;
        if ($urlPath !== null) {
            if (@preg_match('#^' . $matchedRule . '#', ltrim($urlPath, '/'), $captures)) {
                $matchedGroups = [];
                for ($i = 1; $i < count($captures); $i++) {
                    if (isset($captures[$i]) && $captures[$i] !== '') {
                        $matchedGroups[$i] = true;
                    }
                }
            }
        }

        $rule = ltrim($matchedRule, '^');
        $rule = rtrim($rule, '$');

        if (preg_match('#\\\\?/\?$#', $rule, $m)) {
            $rule = substr($rule, 0, -strlen($m[0]));
        }

        $rule = trim($rule, '/');
        if ($rule === '') {
            return '/';
        }

        // Pull the path separator out of (?:/...) non-capturing groups so that
        // splitRegexBySlash treats the embedded '/' as a real segment boundary.
        // Pattern: (?:/foo) means "optional /foo segment" — the '/' belongs at top level.
        $rule = str_replace('(?:/', '/(?:', $rule);

        $segments = self::splitRegexBySlash($rule);
        $normalizedSegments = [];
        $paramIndex = 1;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/[()[\].*+?|^${}\\\\]/', $segment)) {
                // If the segment is entirely escaped static text (e.g. file\.json),
                // decode the backslash escapes and emit it as a plain static segment.
                if (self::isStaticEscapedRegex($segment)) {
                    $decoded = preg_replace('/\\\\(.)/', '$1', $segment);
                    if ($decoded !== '') {
                        $normalizedSegments[] = self::encodeStaticSegment($decoded);
                    }
                    continue;
                }

                $prefixLen = strcspn($segment, '([{?*+|^$\\');
                $dynamicPart = substr($segment, $prefixLen);
                $groupNames = self::extractCaptureGroupNames($dynamicPart);
                $groupCount = count($groupNames);

                // Only emit a static prefix for purely-regex segments with no capture
                // groups. When captures exist the whole segment (prefix + captures) maps
                // to one RFC element, so the prefix must not become a separate element.
                if ($prefixLen > 0 && $groupCount === 0) {
                    $staticPart = rtrim(substr($segment, 0, $prefixLen), '/-._');
                    if ($staticPart !== '') {
                        $normalizedSegments[] = self::encodeStaticSegment($staticPart);
                    }
                }

                if ($groupCount === 0) {
                    if ($matchedGroups !== null && !isset($matchedGroups[$paramIndex])) {
                        $paramIndex++;
                        continue;
                    }
                    $normalizedSegments[] = '{param' . $paramIndex++ . '}';
                } else {
                    $params = [];
                    for ($j = 0; $j < $groupCount; $j++) {
                        if ($matchedGroups !== null && !isset($matchedGroups[$paramIndex])) {
                            $paramIndex++;
                            continue;
                        }
                        $name = $groupNames[$j] ?? null;
                        $params[] = $name !== null ? self::encodeParamName($name) : 'param' . $paramIndex;
                        $paramIndex++;
                    }
                    if (!empty($params)) {
                        $normalizedSegments[] = '{' . implode('+', $params) . '}';
                    }
                }
            } else {
                $normalizedSegments[] = self::encodeStaticSegment($segment);
            }
        }

        return '/' . implode('/', $normalizedSegments);
    }

    /**
     * Split a regex string by '/' but not inside character classes [...] or groups (...).
     * Prevents [^/] and (?:/...) from being split into multiple segments.
     */
    private static function splitRegexBySlash(string $str): array
    {
        $segments = [];
        $current = '';
        $len = strlen($str);
        $bracketDepth = 0;
        $parenDepth = 0;

        for ($i = 0; $i < $len; $i++) {
            $c = $str[$i];

            if ($c === '\\' && $i + 1 < $len) {
                $current .= $c . $str[$i + 1];
                $i++;
                continue;
            }

            if ($c === '[' && $parenDepth === 0) {
                $bracketDepth++;
                $current .= $c;
            } elseif ($c === ']' && $bracketDepth > 0) {
                $bracketDepth--;
                $current .= $c;
            } elseif ($c === '(' && $bracketDepth === 0) {
                $parenDepth++;
                $current .= $c;
            } elseif ($c === ')' && $parenDepth > 0 && $bracketDepth === 0) {
                $parenDepth--;
                $current .= $c;
            } elseif ($c === '/' && $bracketDepth === 0 && $parenDepth === 0) {
                $segments[] = $current;
                $current = '';
            } else {
                $current .= $c;
            }
        }

        $segments[] = $current;
        return $segments;
    }

    /**
     * Returns true if $s is entirely made up of backslash-escaped characters and
     * plain literal text, with no real regex metacharacters (captures, classes, etc.).
     */
    private static function isStaticEscapedRegex(string $s): bool
    {
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '\\') {
                if ($i + 1 >= $len) {
                    return false;
                }
                $i++;
            } elseif (strpos('([{?*+|^$', $s[$i]) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extract capture group names from a regex segment.
     * Named groups ((?P<name>...) or (?<name>...)) return their name; unnamed groups return null.
     * Non-capturing groups (?:...) and lookarounds are not included.
     */
    private static function extractCaptureGroupNames(string $segment): array
    {
        $names = [];
        $len = strlen($segment);
        $inClass = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $segment[$i];

            if ($c === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }

            if ($c === '[' && !$inClass) {
                $inClass = true;
            } elseif ($c === ']' && $inClass) {
                $inClass = false;
            } elseif ($c === '(' && !$inClass) {
                if ($i + 1 < $len && $segment[$i + 1] === '?') {
                    // Named group (?P<name>...) — Python/PCRE syntax
                    if ($i + 3 < $len && $segment[$i + 2] === 'P' && $segment[$i + 3] === '<') {
                        $closePos = strpos($segment, '>', $i + 4);
                        $names[] = $closePos !== false
                            ? substr($segment, $i + 4, $closePos - ($i + 4))
                            : null;
                    // Named group (?<name>...) but not lookbehind (?<=...) / (?<!...)
                    } elseif (
                        $i + 2 < $len && $segment[$i + 2] === '<' &&
                        isset($segment[$i + 3]) && $segment[$i + 3] !== '=' && $segment[$i + 3] !== '!'
                    ) {
                        $closePos = strpos($segment, '>', $i + 3);
                        $names[] = $closePos !== false
                            ? substr($segment, $i + 3, $closePos - ($i + 3))
                            : null;
                    }
                    // (?:...), (?=...), etc. — not a capturing group, skip
                } else {
                    $names[] = null;
                }
            }
        }

        return $names;
    }

    /**
     * Normalize a route that uses {param} notation.
     */
    private static function normalizeBraceRoute(
        string $route,
        array $matchedParams
    ) {
        $route = trim($route);
        if ($route === '' || $route === '/') {
            return '/';
        }

        $trailingSlash = (strlen($route) > 1 && substr($route, -1) === '/') ? '/' : '';
        $route = rtrim($route, '/');

        if ($route[0] !== '/') {
            $route = '/' . $route;
        }

        // Strip inline constraints (e.g. Slim's {name:[^/]+} → {name}) before
        // splitting so that a '/' inside a constraint does not break the segment
        // split. The optional marker '?' is preserved: {name?:[0-9]+} → {name?}.
        $route = preg_replace('/\{([^}?:]+(\?)?):([^}]*)\}/', '{$1}', $route);

        $raw = ltrim($route, '/');
        $parts = explode('/', $raw);
        $normalizedSegments = [];

        foreach ($parts as $segment) {
            if ($segment === '') {
                continue;
            }

            $result = self::normalizeBraceSegment($segment, $matchedParams);
            if ($result === null) {
                continue;
            }

            $normalizedSegments[] = $result;
        }

        return '/' . implode('/', $normalizedSegments) . $trailingSlash;
    }

    /**
     * Normalize a single URL segment that may contain {param} placeholders.
     *
     * @return string|null The normalized element, or null if the segment is optional and absent
     *                     with no remaining static text
     */
    private static function normalizeBraceSegment(string $segment, array $matchedParams)
    {
        preg_match_all('/\{([^}]+)\}/', $segment, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return self::encodeStaticSegment($segment);
        }

        $paramNames = [];
        foreach ($matches as $match) {
            $raw = $match[1];

            $isOptional = (substr($raw, -1) === '?');
            if ($isOptional) {
                $raw = substr($raw, 0, -1);
            }

            $colon = strpos($raw, ':');
            if ($colon !== false) {
                $raw = substr($raw, 0, $colon);
            }

            $name = trim($raw);

            if ($isOptional && !array_key_exists($name, $matchedParams)) {
                continue;
            }

            $paramNames[] = self::encodeParamName($name);
        }

        if (empty($paramNames)) {
            // All params were optional and absent.
            // Preserve any static text remaining in the segment (e.g. "search.{_format?}" → "search").
            // rtrim only: a leading special char (e.g. '~foo.{ext?}') must survive.
            $staticOnly = preg_replace('/\{[^}]+\}/', '', $segment);
            $staticOnly = rtrim($staticOnly, '.-_~');
            if ($staticOnly !== '') {
                return self::encodeStaticSegment($staticOnly);
            }
            return null;
        }

        if (count($paramNames) === 1) {
            return '{' . $paramNames[0] . '}';
        }

        return '{' . implode('+', $paramNames) . '}';
    }

    /**
     * Expand Laminas [...] optional sections based on matched params.
     *
     * When $urlPath is provided, an optional section is only expanded if the
     * section text with param values substituted is a substring of $urlPath.
     * This prevents middleware-injected params from incorrectly triggering
     * expansion of sections absent from the URL.
     *
     * For static-only optional sections (no params), the URL path is also checked
     * to determine whether the literal text appeared in the request.
     */
    private static function expandBracketOptionals(
        string $template,
        array $matchedParams,
        string $paramPrefix = ':',
        $urlPath = null
    ): string {
        $prev = null;
        while ($prev !== $template) {
            $prev = $template;
            $template = preg_replace_callback(
                '/\[([^\[\]]*)\]/',
                function ($m) use ($matchedParams, $paramPrefix, $urlPath) {
                    $inner = $m[1];
                    $pattern = '/' . preg_quote($paramPrefix, '/') . '([a-zA-Z_][a-zA-Z0-9_-]*)/';
                    preg_match_all($pattern, $inner, $pm);
                    $innerParams = $pm[1];

                    if (empty($innerParams)) {
                        // Static-only optional section (e.g. [/draft]):
                        // only expand when the literal text appears in the URL.
                        if ($urlPath !== null) {
                            return (strpos($urlPath, $inner) !== false) ? $inner : '';
                        }
                        return $inner;
                    }

                    // All params in the section must be present in matched params.
                    foreach ($innerParams as $param) {
                        if (!array_key_exists($param, $matchedParams)) {
                            return '';
                        }
                    }

                    if ($urlPath !== null) {
                        // Substitute every param value before checking the URL so that
                        // multi-param sections like [/:year/:month] are found correctly.
                        // Use a word-boundary-aware replacement so :id is not replaced
                        // inside :id2 (str_replace(':id', ...) would corrupt ':id2').
                        $innerWithValues = $inner;
                        foreach ($innerParams as $param) {
                            $value = (string)$matchedParams[$param];
                            $innerWithValues = preg_replace(
                                '/' . preg_quote($paramPrefix . $param, '/') . '(?![a-zA-Z0-9_-])/',
                                $value,
                                $innerWithValues
                            );
                        }
                        if (strpos($urlPath, $innerWithValues) !== false) {
                            return $inner;
                        }
                        // Try percent-encoded values (Laminas URL-decodes param values)
                        $innerEncoded = $inner;
                        foreach ($innerParams as $param) {
                            $value = rawurlencode((string)$matchedParams[$param]);
                            $innerEncoded = preg_replace(
                                '/' . preg_quote($paramPrefix . $param, '/') . '(?![a-zA-Z0-9_-])/',
                                $value,
                                $innerEncoded
                            );
                        }
                        if (strpos($urlPath, $innerEncoded) !== false) {
                            return $inner;
                        }
                        return '';
                    }

                    return $inner;
                },
                $template
            );
        }
        return $template;
    }

    /**
     * Convert ":paramName" colon-prefix notation to "{paramName}" brace notation.
     * Laminas segment constraints like ":param{constraint}" are also handled.
     * Hyphenated param names like ":user-id" are supported.
     */
    private static function colonParamsToBraces(string $template): string
    {
        return preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_-]*)(?:\{[^}]*\})?/',
            static function ($m) {
                return '{' . $m[1] . '}';
            },
            $template
        );
    }

    /**
     * Convert Laminas Regex route spec %param% notation to {param} brace notation.
     * Regex routes store their spec as "/path/%id%/%name%" for URL generation.
     */
    private static function percentParamsToBraces(string $template): string
    {
        return preg_replace('/%([a-zA-Z_][a-zA-Z0-9_]*)%/', '{$1}', $template);
    }

    /**
     * Find a param name of the form "paramN" that does not already appear in $template
     * as either a colon-param (:paramN) or a brace-param ({paramN}).
     */
    private static function uniqueParamName(string $template, string $paramPrefix = ':'): string
    {
        $i = 1;
        while (
            // Use regex so ':param1' doesn't falsely match inside ':param10'
            preg_match('/' . preg_quote($paramPrefix . 'param' . $i, '/') . '(?![0-9])/', $template) ||
            strpos($template, '{param' . $i . '}') !== false ||
            strpos($template, '%param' . $i . '%') !== false
        ) {
            $i++;
        }
        return 'param' . $i;
    }

    /**
     * URL-encode characters in a static segment that are outside [A-Za-z0-9.-~_].
     * Already-encoded percent sequences are left intact (hex digits uppercased).
     */
    public static function encodeStaticSegment(string $segment): string
    {
        $result = '';
        $len = strlen($segment);
        for ($i = 0; $i < $len; $i++) {
            $c = $segment[$i];
            if (
                ($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z') ||
                ($c >= '0' && $c <= '9') ||
                $c === '.' || $c === '-' || $c === '~' || $c === '_'
            ) {
                $result .= $c;
            } elseif (
                $c === '%' &&
                $i + 2 < $len &&
                ctype_xdigit($segment[$i + 1]) &&
                ctype_xdigit($segment[$i + 2])
            ) {
                $result .= '%' . strtoupper($segment[$i + 1]) . strtoupper($segment[$i + 2]);
                $i += 2;
            } else {
                $result .= rawurlencode($c);
            }
        }
        return $result;
    }

    /**
     * URL-encode reserved characters in a parameter name.
     * Reserved: /?#+{} — these must not appear literally in a parameter name.
     * The '+' combining marker must be encoded if it appears in a framework-supplied name.
     */
    public static function encodeParamName(string $name): string
    {
        $reserved = '/?#+{}';
        $result = '';
        $len = strlen($name);
        for ($i = 0; $i < $len; $i++) {
            $c = $name[$i];
            if (strpos($reserved, $c) !== false) {
                $result .= rawurlencode($c);
            } else {
                $result .= $c;
            }
        }
        return $result;
    }

    /**
     * Infer which Symfony route parameters were actually present in the URL path
     * (vs. injected as route defaults).
     *
     * Handles three kinds of template segments:
     *  - Whole-segment param:  {id}           → matched if URL has a segment at that position
     *  - Mixed segment:        {id}.{_format} → matched if URL segment matches template regex
     *  - Static segment:       users          → no params extracted
     *
     * UTF-8 parameter names are supported.
     */
    public static function inferSymfonyRouteParams(string $template, string $urlPath): array
    {
        $templateSegments = array_values(array_filter(explode('/', $template), 'strlen'));
        // Decode percent-encoded URL path so template literals (e.g. café) compare
        // correctly against encoded URL segments (e.g. caf%C3%A9).
        $urlSegments = array_values(array_filter(
            array_map('rawurldecode', explode('/', $urlPath)),
            'strlen'
        ));

        $matched = [];
        $urlIdx  = 0;

        foreach ($templateSegments as $seg) {
            if (preg_match('/^\{([^}?:]+)\}$/', $seg, $m)) {
                // Whole-segment param — present if there is a URL segment at this position
                if ($urlIdx < count($urlSegments)) {
                    $matched[$m[1]] = $urlSegments[$urlIdx];
                }
                $urlIdx++;
            } elseif (preg_match('/\{/', $seg)) {
                // Mixed segment (static text + one or more params): determine presence by
                // trying to match the URL segment, dropping trailing optional params as needed.
                if ($urlIdx < count($urlSegments)) {
                    preg_match_all('/\{([^}?:]+)\}/', $seg, $pm);
                    $paramNames  = $pm[1];
                    $n           = count($paramNames);
                    if ($n > 0) {
                        $urlSeg      = $urlSegments[$urlIdx];
                        $staticParts = preg_split('/\{[^}]+\}/', $seg);
                        // Try with k params (k = n, n-1, ..., 1). Drop params from the right
                        // until the URL segment matches. This handles optional trailing captures
                        // that were injected as route defaults but absent from the URL.
                        for ($k = $n; $k >= 1; $k--) {
                            $regexBody = '';
                            for ($i = 0; $i < $k; $i++) {
                                $regexBody .= preg_quote($staticParts[$i], '/') . '(.+)';
                            }
                            // Only include the trailing static part for a full match
                            if ($k === $n) {
                                $regexBody .= preg_quote($staticParts[$n], '/');
                            }
                            if (@preg_match('/^' . $regexBody . '$/', $urlSeg)) {
                                for ($i = 0; $i < $k; $i++) {
                                    $matched[$paramNames[$i]] = true;
                                }
                                break;
                            }
                        }
                    }
                }
                $urlIdx++;
            } else {
                // Pure static segment — advance URL position
                $urlIdx++;
            }
        }

        return $matched;
    }
}
