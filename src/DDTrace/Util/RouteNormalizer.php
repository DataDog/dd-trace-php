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
        $braceFormat = self::colonParamsToBraces($expanded);
        $braceFormat = self::percentParamsToBraces($braceFormat);
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
        // Re-run the regex against the actual URL to find how many capture groups matched.
        // This handles optional groups like (?:/([0-9]+))? that may or may not be present.
        $matchedGroupCount = null;
        if ($urlPath !== null) {
            if (@preg_match('#^' . $matchedRule . '#', ltrim($urlPath, '/'), $captures)) {
                $matchedGroupCount = 0;
                for ($i = 1; $i < count($captures); $i++) {
                    if (isset($captures[$i]) && $captures[$i] !== '') {
                        $matchedGroupCount = $i;
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
                $prefixLen = strcspn($segment, '([{?*+|^$\\');
                $dynamicPart = substr($segment, $prefixLen);
                $groupCount = self::countCaptureGroups($dynamicPart);

                // Only emit a static prefix when there is at most one capture group;
                // mixed segments with multiple groups are treated as fully dynamic.
                if ($prefixLen > 0 && $groupCount <= 1) {
                    $staticPart = rtrim(substr($segment, 0, $prefixLen), '/-._');
                    if ($staticPart !== '') {
                        $normalizedSegments[] = self::encodeStaticSegment($staticPart);
                    }
                }

                if ($groupCount === 0) {
                    if ($matchedGroupCount !== null && $paramIndex > $matchedGroupCount) {
                        continue;
                    }
                    $normalizedSegments[] = '{param' . $paramIndex++ . '}';
                } else {
                    $params = [];
                    for ($j = 0; $j < $groupCount; $j++) {
                        if ($matchedGroupCount !== null && $paramIndex > $matchedGroupCount) {
                            break;
                        }
                        $params[] = 'param' . $paramIndex++;
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
     * Count capturing groups in a regex segment, ignoring character classes and non-capturing groups.
     */
    private static function countCaptureGroups(string $segment): int
    {
        $count = 0;
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
                if ($i + 1 >= $len || $segment[$i + 1] !== '?') {
                    $count++;
                }
            }
        }

        return $count;
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
            $staticOnly = preg_replace('/\{[^}]+\}/', '', $segment);
            $staticOnly = trim($staticOnly, '.-_~');
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
                        $innerWithValues = $inner;
                        foreach ($innerParams as $param) {
                            $value = (string)$matchedParams[$param];
                            $innerWithValues = str_replace($paramPrefix . $param, $value, $innerWithValues);
                        }
                        if (strpos($urlPath, $innerWithValues) !== false) {
                            return $inner;
                        }
                        // Try percent-encoded values (Laminas URL-decodes param values)
                        $innerEncoded = $inner;
                        foreach ($innerParams as $param) {
                            $value = rawurlencode((string)$matchedParams[$param]);
                            $innerEncoded = str_replace($paramPrefix . $param, $value, $innerEncoded);
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
            strpos($template, $paramPrefix . 'param' . $i) !== false ||
            strpos($template, '{param' . $i . '}') !== false
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
        $templateSegments = array_values(array_filter(explode('/', $template)));
        $urlSegments      = array_values(array_filter(explode('/', $urlPath)));

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
                // trying to match the URL segment against the template pattern.
                if ($urlIdx < count($urlSegments)) {
                    preg_match_all('/\{([^}?:]+)\}/', $seg, $pm);
                    $paramNames = $pm[1];
                    if (!empty($paramNames)) {
                        // Build a lightweight regex from the template segment
                        $staticParts = preg_split('/\{[^}]+\}/', $seg);
                        $regexParts  = array_map(
                            static function ($p) { return preg_quote($p, '/'); },
                            $staticParts
                        );
                        $segRegex = '/^' . implode('(.+)', $regexParts) . '$/';
                        if (@preg_match($segRegex, $urlSegments[$urlIdx])) {
                            foreach ($paramNames as $p) {
                                $matched[$p] = true;
                            }
                        }
                        // else: URL segment doesn't contain the dynamic part → params absent
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
