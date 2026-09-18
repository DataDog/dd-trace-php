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
     *                                  route defaults); required for dynamic routes
     * @return string|null
     */
    public static function normalizeFromSymfony(string $path, $matchedParams = null)
    {
        if ($matchedParams === null) {
            return self::normalizeBraceRoute($path, []);
        }

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
     * @param array|null  $urlMatchedParams Parameters captured by the Regex route matcher
     * @return string|null
     */
    public static function normalizeFromLaminas(string $template, array $matchedParams = [], $urlPath = null, $urlMatchedParams = null)
    {
        $expanded = self::expandBracketOptionals($template, $matchedParams, ':', $urlPath);

        // Replace wildcard /* with a param name that doesn't collide with existing params
        if (preg_match('#/\*$#', $expanded)) {
            $wildcardName = self::uniqueParamName($expanded, ':');
            $expanded = preg_replace('#/\*$#', '/{' . $wildcardName . '}', $expanded);
        }

        // Segment routes use :param; Regex routes use %param% (spec format) — handle both.
        // Detect Regex routes before conversion so matcher capture metadata can be applied.
        $hasPercentParams = (bool) preg_match('/%([a-zA-Z_][a-zA-Z0-9_]*)%/', $expanded);
        // For Regex routes, defaults inject values into matchedParams even for captures absent
        // from the URL (e.g. format='html' when no .html in path). Use $urlMatchedParams when
        // provided by the integration; otherwise fall back to URL-value heuristic or treat all
        // percent params as required when no URL info is available.
        if ($hasPercentParams) {
            if ($urlMatchedParams === null) {
                if ($urlPath === null) {
                    // No URL info: treat all percent params as required (present).
                    $expanded = preg_replace('/%([a-zA-Z_][a-zA-Z0-9_]*)%/', '{$1}', $expanded);
                    $urlMatchedParams = $matchedParams;
                } else {
                    // Heuristic: params whose values appear in the URL are treated as URL-matched.
                    $inferred = [];
                    foreach ($matchedParams as $name => $value) {
                        if (strpos($expanded, '%' . $name . '%') === false) {
                            continue;
                        }
                        $strValue = (string) $value;
                        if ($strValue !== '' && (
                            strpos($urlPath, $strValue) !== false ||
                            strpos($urlPath, rawurlencode($strValue)) !== false ||
                            strpos(strtolower($urlPath), strtolower(rawurlencode($strValue))) !== false
                        )) {
                            $inferred[$name] = $value;
                        }
                    }
                    $expanded = preg_replace_callback(
                        '/%([a-zA-Z_][a-zA-Z0-9_]*)%/',
                        static function ($m) use ($inferred) {
                            return array_key_exists($m[1], $inferred)
                                ? '{' . $m[1] . '}'
                                : '{' . $m[1] . '?}';
                        },
                        $expanded
                    );
                    $urlMatchedParams = $inferred;
                }
            } else {
                $expanded = preg_replace_callback(
                    '/%([a-zA-Z_][a-zA-Z0-9_]*)%/',
                    static function ($m) use ($urlMatchedParams) {
                        return array_key_exists($m[1], $urlMatchedParams)
                            ? '{' . $m[1] . '}'
                            : '{' . $m[1] . '?}';
                    },
                    $expanded
                );
            }
        }

        $braceFormat = self::colonParamsToBraces($expanded);
        $braceFormat = self::percentParamsToBraces($braceFormat);

        return self::normalizeBraceRoute(
            $braceFormat,
            $hasPercentParams ? $urlMatchedParams : $matchedParams
        );
    }

    /**
     * Normalize a WordPress matched_rule (regex).
     *
     * WordPress route matching uses regex rules like "^blog/([^/]+)/?$".
     * PCRE supplies declared names; unnamed captures use param1, param2, ….
     *
     * @param string      $matchedRule Value of $wp->matched_rule
     * @param string|null $urlPath     Value of $wp->request; used to detect which
     *                                 optional capture groups actually participated
     *                                 in the match, so phantom segments are not emitted.
     * @return string|null
     */
    public static function normalizeFromWordPress(string $matchedRule, $urlPath = null, $analysis = null)
    {
        if ($analysis === null) {
            $analysis = self::analyzeWordPressRoute($matchedRule, $urlPath);
        }

        return $analysis['normalized_route'] ?? null;
    }

    /**
     * Match a WordPress rule and derive the route from PCRE's capture offsets.
     *
     * Literal alternatives and optional literals may produce distinct normalized
     * routes. Rules that can consume variable text outside a capture are rejected,
     * because their uncaptured request text would otherwise become a route constant.
     *
     * When $urlPath is null, a backward-compatible fallback is used that emits all
     * capture groups without filtering by participation.
     *
     * @return array|null
     */
    public static function analyzeWordPressRoute(string $matchedRule, $urlPath = null)
    {
        if (!self::hasOnlyCapturedWordPressDynamics($matchedRule)) {
            return null;
        }

        if ($urlPath === null) {
            $normalized = self::normalizeWordPressRuleOnly($matchedRule);
            if ($normalized === null) {
                return null;
            }
            return [
                'normalized_route' => $normalized,
                'cache_signature'  => $normalized,
            ];
        }

        // WordPress uses # delimiters when selecting matched_rule, so an
        // unescaped # could not occur in a rule that successfully matched.
        $pattern = '#^' . $matchedRule . '#';

        $subject = trim($urlPath, '/');
        $matches = [];
        $flags = PREG_OFFSET_CAPTURE;
        if (defined('PREG_UNMATCHED_AS_NULL')) {
            $flags |= constant('PREG_UNMATCHED_AS_NULL');
        }
        if (@preg_match($pattern, $subject, $matches, $flags) !== 1) {
            return null;
        }
        if ($matches[0][1] !== 0 || strlen($matches[0][0]) !== strlen($subject)) {
            return null;
        }

        $captures = self::wordPressNumericCaptures($matches);
        if ($captures === null || !self::applyWordPressCaptureNames($matches, $captures)) {
            return null;
        }

        $normalizedRoute = self::normalizeWordPressMatch($subject, $captures);
        if ($normalizedRoute === null) {
            return null;
        }

        return [
            'normalized_route' => $normalizedRoute,
            // This is bounded because uncaptured variable input was rejected above.
            'cache_signature' => $normalizedRoute,
        ];
    }

    /**
     * Backward-compatible fallback for normalizeFromWordPress when no URL path is available.
     *
     * Parses the PCRE rule structure to identify capture groups and segment boundaries
     * ('/') at capturing-depth 0. All capture groups are treated as present.
     */
    /** @return string|null */
    private static function normalizeWordPressRuleOnly(string $rule)
    {
        // Strip anchors and common trailing patterns
        $s = $rule;
        if (isset($s[0]) && $s[0] === '^') {
            $s = substr($s, 1);
        }
        if (substr($s, -3) === '/?$') {
            $s = substr($s, 0, -3);
        } elseif (substr($s, -2) === '/$') {
            $s = substr($s, 0, -2);
        } elseif (substr($s, -2) === '?$') {
            $s = substr($s, 0, -2);
        } elseif (substr($s, -1) === '$') {
            $s = substr($s, 0, -1);
        }
        if (substr($s, -2) === '/?') {
            $s = substr($s, 0, -2);
        }

        if ($s === '') {
            return '/';
        }

        $captureNum = 0;
        $groups = [];        // stack: true = capturing, false = non-capturing
        $capturingDepth = 0;
        $inClass = false;
        $inQuote = false;
        $len = strlen($s);

        $segments = [];
        $currentSegment = ['static' => '', 'captures' => []];

        for ($i = 0; $i < $len; $i++) {
            $char = $s[$i];

            if ($inQuote) {
                if ($char === '\\' && isset($s[$i + 1]) && $s[$i + 1] === 'E') {
                    $inQuote = false;
                    $i++;
                }
                continue;
            }

            if ($inClass) {
                if ($char === '\\' && isset($s[$i + 1])) {
                    $i++;
                } elseif ($char === ']') {
                    $inClass = false;
                }
                continue;
            }

            if ($char === '\\') {
                if (!isset($s[$i + 1])) {
                    break;
                }
                $next = $s[++$i];
                if ($next === 'Q') {
                    $inQuote = true;
                }
                continue;
            }

            if ($char === '[' && $capturingDepth > 0) {
                $inClass = true;
                continue;
            }

            if ($char === '(') {
                $capturing = true;
                if (substr($s, $i + 1, 2) === '?:') {
                    $capturing = false;
                    $i += 2;
                } elseif (substr($s, $i + 1, 3) === '?P<') {
                    $end = strpos($s, '>', $i + 4);
                    if ($end !== false) {
                        $i = $end;
                    }
                } elseif (substr($s, $i + 1, 2) === '?<'
                    && isset($s[$i + 3]) && strpos('=!', $s[$i + 3]) === false) {
                    $end = strpos($s, '>', $i + 3);
                    if ($end !== false) {
                        $i = $end;
                    }
                } elseif (isset($s[$i + 1]) && $s[$i + 1] === '?') {
                    $capturing = false;
                    $i++;
                }
                $groups[] = $capturing;
                if ($capturing) {
                    $captureNum++;
                    if ($capturingDepth === 0) {
                        $currentSegment['captures'][] = $captureNum;
                    }
                    $capturingDepth++;
                }
                continue;
            }

            if ($char === ')') {
                $wasCapturing = array_pop($groups);
                if ($wasCapturing) {
                    $capturingDepth--;
                }
                if (isset($s[$i + 1]) && ($s[$i + 1] === '?' || $s[$i + 1] === '*' || $s[$i + 1] === '+')) {
                    $i++;
                } elseif (isset($s[$i + 1]) && $s[$i + 1] === '{') {
                    $end = strpos($s, '}', $i + 1);
                    if ($end !== false) {
                        $i = $end;
                    }
                }
                continue;
            }

            if ($capturingDepth > 0) {
                continue;
            }

            // At capturingDepth === 0
            if ($char === '/') {
                $segments[] = $currentSegment;
                $currentSegment = ['static' => '', 'captures' => []];
            } elseif ($char === '?' || $char === '*' || $char === '+') {
                // quantifier — skip
            } elseif ($char === '{') {
                $end = strpos($s, '}', $i);
                if ($end !== false) {
                    $i = $end;
                }
            } elseif ($char === '|') {
                break; // take first alternative only
            } elseif ($char !== '.') {
                $currentSegment['static'] .= $char;
            }
        }

        $segments[] = $currentSegment;

        $normalized = [];
        foreach ($segments as $seg) {
            if (!empty($seg['captures'])) {
                $params = array_map(static function ($n) { return 'param' . $n; }, $seg['captures']);
                $normalized[] = '{' . implode('+', $params) . '}';
            } elseif ($seg['static'] !== '') {
                $normalized[] = self::encodeStaticSegment($seg['static']);
            }
        }

        return '/' . implode('/', $normalized);
    }

    /**
     * This is a rejection filter, not a PCRE parser. Capture bodies are opaque.
     * Outside captures, only literal text, fixed alternatives, optional fixed text,
     * and non-capturing wrappers are allowed.
     */
    private static function hasOnlyCapturedWordPressDynamics(string $rule): bool
    {
        $groups = [];
        $captureDepth = 0;
        $inClass = false;
        $inQuote = false;
        $length = strlen($rule);

        for ($i = 0; $i < $length; $i++) {
            $char = $rule[$i];

            if ($inQuote) {
                if ($char === '\\' && isset($rule[$i + 1]) && $rule[$i + 1] === 'E') {
                    $inQuote = false;
                    $i++;
                }
                continue;
            }
            if ($inClass) {
                if ($char === '\\' && isset($rule[$i + 1])) {
                    $i++;
                } elseif ($char === ']') {
                    $inClass = false;
                }
                continue;
            }
            if ($char === '\\') {
                if (!isset($rule[$i + 1])) {
                    return false;
                }
                $escaped = $rule[++$i];
                if ($escaped === 'Q') {
                    $inQuote = true;
                } elseif ($captureDepth === 0 && ctype_alnum($escaped)) {
                    return false;
                }
                continue;
            }
            if ($char === '[') {
                if ($captureDepth === 0) {
                    return false;
                }
                $inClass = true;
                continue;
            }
            if ($char === '(') {
                $capturing = true;
                if (isset($rule[$i + 1]) && $rule[$i + 1] === '*') {
                    if ($captureDepth === 0) {
                        return false;
                    }
                    $capturing = false;
                } elseif (isset($rule[$i + 1]) && $rule[$i + 1] === '?') {
                    $namedEnd = self::wordPressNamedCaptureEnd($rule, $i);
                    if ($namedEnd !== null) {
                        $i = $namedEnd;
                    } elseif (substr($rule, $i + 1, 2) === '?:') {
                        $capturing = false;
                        $i += 2;
                    } elseif ($captureDepth > 0) {
                        // The outer capture covers everything consumed by this group.
                        $capturing = false;
                    } else {
                        return false;
                    }
                }
                $groups[] = $capturing;
                if ($capturing) {
                    $captureDepth++;
                }
                continue;
            }
            if ($char === ')') {
                if (empty($groups)) {
                    return false;
                }
                if (array_pop($groups)) {
                    $captureDepth--;
                }
                continue;
            }
            if ($captureDepth === 0 && strpos('.[*+{', $char) !== false) {
                return false;
            }
        }

        return !$inClass && empty($groups);
    }

    /** @return int|null */
    private static function wordPressNamedCaptureEnd(string $rule, int $open)
    {
        if (substr($rule, $open + 1, 3) === '?P<') {
            $end = strpos($rule, '>', $open + 4);
        } elseif (substr($rule, $open + 1, 2) === '?<'
            && isset($rule[$open + 3]) && strpos('=!', $rule[$open + 3]) === false) {
            $end = strpos($rule, '>', $open + 3);
        } elseif (substr($rule, $open + 1, 2) === "?'") {
            $end = strpos($rule, "'", $open + 3);
        } else {
            return null;
        }

        return $end === false ? null : $end;
    }

    /** @return array|null */
    private static function wordPressNumericCaptures(array $matches)
    {
        $captures = [];
        foreach ($matches as $key => $match) {
            if (!is_int($key) || $key === 0) {
                continue;
            }
            if (!is_array($match) || count($match) !== 2) {
                return null;
            }
            $captures[$key] = [
                'value' => $match[0],
                'offset' => $match[1],
                'present' => $match[1] >= 0 && $match[0] !== '',
                'name' => null,
            ];
        }
        ksort($captures);
        return $captures;
    }

    private static function applyWordPressCaptureNames(array $matches, array &$captures): bool
    {
        $pendingName = null;
        $pendingMatch = null;
        foreach ($matches as $key => $match) {
            if (is_string($key)) {
                if ($pendingName !== null) {
                    return false;
                }
                $pendingName = $key;
                $pendingMatch = $match;
                continue;
            }
            if ($key === 0 || $pendingName === null) {
                continue;
            }
            if (!isset($captures[$key]) || $pendingMatch !== $match
                || $captures[$key]['name'] !== null) {
                return false;
            }
            $captures[$key]['name'] = $pendingName;
            $pendingName = null;
            $pendingMatch = null;
        }
        return $pendingName === null;
    }

    /** @return string|null */
    private static function normalizeWordPressMatch(string $subject, array $captures)
    {
        if ($subject === '') {
            return '/';
        }

        $values = explode('/', $subject);
        $segments = [];
        $offset = 0;
        foreach ($values as $index => $value) {
            $segments[$index] = [
                'value' => $value,
                'start' => $offset,
                'end' => $offset + strlen($value),
                'captures' => [],
            ];
            $offset = $segments[$index]['end'] + 1;
        }

        foreach ($captures as $index => &$capture) {
            if (!$capture['present']) {
                continue;
            }
            $captureEnd = $capture['offset'] + strlen($capture['value']);
            $capture['first_segment'] = null;
            $capture['last_segment'] = null;
            foreach ($segments as $segmentIndex => &$segment) {
                if ($capture['offset'] < $segment['end'] && $captureEnd > $segment['start']) {
                    $segment['captures'][$index] = true;
                    if ($capture['first_segment'] === null) {
                        $capture['first_segment'] = $segmentIndex;
                    }
                    $capture['last_segment'] = $segmentIndex;
                }
            }
            unset($segment);
            if ($capture['first_segment'] === null) {
                return null;
            }
        }
        unset($capture);

        $normalized = [];
        for ($segmentIndex = 0; $segmentIndex < count($segments); $segmentIndex++) {
            if (empty($segments[$segmentIndex]['captures'])) {
                $normalized[] = self::encodeStaticSegment($segments[$segmentIndex]['value']);
                continue;
            }

            $lastSegment = $segmentIndex;
            do {
                $previousLast = $lastSegment;
                foreach ($captures as $capture) {
                    if (!$capture['present'] || $capture['first_segment'] > $lastSegment
                        || $capture['last_segment'] < $segmentIndex) {
                        continue;
                    }
                    $lastSegment = max($lastSegment, $capture['last_segment']);
                }
            } while ($lastSegment !== $previousLast);

            $params = [];
            foreach ($captures as $index => $capture) {
                if (!$capture['present'] || $capture['first_segment'] > $lastSegment
                    || $capture['last_segment'] < $segmentIndex) {
                    continue;
                }
                $params[] = $capture['name'] !== null
                    ? self::encodeParamName($capture['name'])
                    : 'param' . $index;
            }
            $normalized[] = '{' . implode('+', $params) . '}';
            $segmentIndex = $lastSegment;
        }

        return '/' . implode('/', $normalized);
    }

    /**
     * Normalize a route that uses {param} notation.
     */
    private static function normalizeBraceRoute(string $route, array $matchedParams)
    {
        $route = trim($route);
        if ($route === '' || $route === '/') {
            return '/';
        }

        $trailingSlash = (strlen($route) > 1 && substr($route, -1) === '/') ? '/' : '';
        $route = rtrim($route, '/');

        if ($route[0] !== '/') {
            $route = '/' . $route;
        }

        // Strip inline constraints (e.g. {name:[^/]+} → {name}) before
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
                        // only expand when the literal text appears in the URL
                        // at a position > 0 (never at the very start, since optional
                        // sections always follow mandatory route text).
                        if ($urlPath !== null) {
                            return (strpos($urlPath, $inner) > 0) ? $inner : '';
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
                        // Check position > 0: optional sections always follow mandatory
                        // route text so a match at position 0 is a false positive (e.g.
                        // the default value is identical to the mandatory route prefix).
                        $innerWithValues = $inner;
                        foreach ($innerParams as $param) {
                            $value = (string)$matchedParams[$param];
                            $innerWithValues = preg_replace(
                                '/' . preg_quote($paramPrefix . $param, '/') . '(?![a-zA-Z0-9_-])/',
                                $value,
                                $innerWithValues
                            );
                        }
                        if (strpos($urlPath, $innerWithValues) > 0) {
                            return $inner;
                        }
                        // Try percent-encoded values (Laminas URL-decodes param values).
                        // Also try lowercase hex since browsers may send %c3%a9 for %C3%A9.
                        $innerEncoded = $inner;
                        foreach ($innerParams as $param) {
                            $value = rawurlencode((string)$matchedParams[$param]);
                            $innerEncoded = preg_replace(
                                '/' . preg_quote($paramPrefix . $param, '/') . '(?![a-zA-Z0-9_-])/',
                                $value,
                                $innerEncoded
                            );
                        }
                        if (strpos($urlPath, $innerEncoded) > 0 ||
                            strpos(strtolower($urlPath), strtolower($innerEncoded)) > 0) {
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
}
