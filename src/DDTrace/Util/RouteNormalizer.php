<?php

namespace DDTrace\Util;

/**
 * Builds _dd.appsec.normalized_route. docs/appsec_normalized_route.md lists,
 * per integration, when the tag is omitted or simplified; keep it up to date.
 *
 * @internal
 */
class RouteNormalizer
{
    /** Separator characters Symfony's RouteCompiler moves into an optional. */
    const OPTIONAL_SEPARATORS = ',;.:-_~+*=@|';

    /** Cache key prefix for the per-rule WordPress safety scan. */
    const WORDPRESS_RULE_CACHE_PREFIX = "wordpress-rule\0";

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
     * The caller is expected to supply the exact set of parameters that
     * participated in the URL match. Inferring participation here without the
     * match data would require redoing it or guessing from defaults, both of
     * which can produce routes that violate the normalization spec.
     *
     * @param string $path          Path template, e.g. "/users/{id}"
     * @param array  $matchedParams Params actually present in the URL path
     *                              (not including route defaults).
     * @return string|null
     */
    public static function normalizeFromSymfony(string $path, array $matchedParams)
    {
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
     * Normalize a route that a framework adapter has already resolved into a
     * sequence of elements:
     *
     *  - ['static', text]: text the request matched verbatim, in URL form;
     *  - ['param', name]: a parameter that took part in the match;
     *  - ['tail']: a catch-all that may span several URL segments.
     *
     * Elements are grouped into URL segments by the slashes in static text,
     * so static text is never read as route syntax. Within a segment, static
     * text is kept and each parameter becomes its own template expression, so
     * the result is an OpenAPI path template (e.g. "user-{id}", "{id}.{format}").
     * A catch-all becomes one dynamic element named paramN covering its whole
     * segment.
     */
    public static function normalizeElements(array $elements): string
    {
        $segments = [self::newElementSegment()];
        $names = [];
        foreach ($elements as $element) {
            $current = count($segments) - 1;
            switch ($element[0]) {
                case 'static':
                    $pieces = explode('/', $element[1]);
                    $first = array_shift($pieces);
                    if ($first !== '') {
                        $segments[$current]['parts'][] = ['static', $first];
                    }
                    foreach ($pieces as $piece) {
                        $segments[] = self::newElementSegment();
                        if ($piece !== '') {
                            $segments[count($segments) - 1]['parts'][] = ['static', $piece];
                        }
                    }
                    break;
                case 'param':
                    $segments[$current]['parts'][] = ['param', $element[1]];
                    $names[self::encodeParamName($element[1])] = true;
                    break;
                case 'tail':
                    $segments[$current]['tail'] = true;
                    break;
            }
        }

        $last = end($segments);
        $trailingSlash = count($segments) > 1 && !$last['parts'] && !$last['tail'];

        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment['tail']) {
                $i = 1;
                while (isset($names['param' . $i])) {
                    $i++;
                }
                $names['param' . $i] = true;
                $normalized[] = '{param' . $i . '}';
            } elseif ($segment['parts']) {
                $normalized[] = self::renderSegmentParts($segment['parts']);
            }
        }

        if (!$normalized) {
            return '/';
        }

        return '/' . implode('/', $normalized) . ($trailingSlash ? '/' : '');
    }

    /**
     * Render the parts of one URL segment as an OpenAPI path segment: static
     * text is percent-encoded, each parameter becomes "{name}".
     *
     * @param array<array{0: string, 1: string}> $parts
     */
    private static function renderSegmentParts(array $parts): string
    {
        $out = '';
        foreach ($parts as $part) {
            $out .= $part[0] === 'param'
                ? '{' . self::encodeParamName($part[1]) . '}'
                : self::encodeStaticSegment($part[1]);
        }
        return $out;
    }

    private static function newElementSegment(): array
    {
        return ['parts' => [], 'tail' => false];
    }

    /**
     * Normalize a WordPress matched_rule (regex) by matching it again and
     * deriving the route from PCRE's capture offsets.
     *
     * WordPress route matching uses regex rules like "^blog/([^/]+)/?$".
     * PCRE supplies declared names; unnamed captures use param1, param2, ….
     * Literal alternatives and optional literals may produce distinct normalized
     * routes. Rules that can consume variable text outside a capture are rejected,
     * because their uncaptured request text would otherwise become a route constant.
     *
     * $urlPath is required: without it we cannot determine which optional
     * captures participated in the match, and inferring participation purely
     * from the rule would violate the normalization spec.
     *
     * @param string $matchedRule Value of $wp->matched_rule
     * @param string $urlPath     Value of $wp->request, the subject WordPress
     *                            matched the rule against
     * @return string|null
     */
    public static function normalizeFromWordPress(string $matchedRule, string $urlPath)
    {
        if (!self::wordPressRulePlan($matchedRule)['safe']) {
            return null;
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

        return self::normalizeWordPressMatch($subject, $captures);
    }

    /**
     * Parse a WordPress rule once and remember whether it is safe to normalize.
     * The returned shape:
     *   [
     *     'safe' => bool,  // result of hasOnlyCapturedWordPressDynamics
     *   ]
     *
     * The scan is a pure function of the rule, so the verdict is kept in the
     * routing cache, which lives for the life of the process and is bounded.
     * WordPress matches one rule per request, so a PHP static would be reset
     * before it was ever read again under FPM, mod_php and CGI, and would grow
     * unbounded in runtimes that keep PHP state across requests.
     *
     * @return array{safe: bool}
     */
    public static function wordPressRulePlan(string $rule): array
    {
        $cacheKey = self::WORDPRESS_RULE_CACHE_PREFIX . $rule;
        $cached = \DDTrace\routing_cache_get($cacheKey);
        if ($cached !== false) {
            return ['safe' => $cached === '1'];
        }

        $safe = self::hasOnlyCapturedWordPressDynamics($rule);
        \DDTrace\routing_cache_set($cacheKey, $safe ? '1' : '0');

        return ['safe' => $safe];
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

    /**
     * Build the route from the subject WordPress matched: text outside the
     * captures that took part is kept as static text, and each such capture
     * becomes one parameter. Nested captures are covered by the outermost one.
     * A capture whose value contains '/' becomes a single element spanning
     * those segments.
     *
     * @return string|null
     */
    private static function normalizeWordPressMatch(string $subject, array $captures)
    {
        if ($subject === '') {
            return '/';
        }

        $present = [];
        foreach ($captures as $index => $capture) {
            if (!$capture['present']) {
                continue;
            }
            $present[] = [
                'start' => $capture['offset'],
                'end' => $capture['offset'] + strlen($capture['value']),
                'name' => $capture['name'] !== null ? $capture['name'] : 'param' . $index,
            ];
        }
        usort($present, static function ($a, $b) {
            return $a['start'] === $b['start'] ? $b['end'] - $a['end'] : $a['start'] - $b['start'];
        });

        $elements = [];
        $position = 0;
        foreach ($present as $capture) {
            if ($capture['start'] < $position) {
                // Nested in (or overlapping) a capture already emitted.
                continue;
            }
            if ($capture['start'] > $position) {
                $elements[] = ['static', substr($subject, $position, $capture['start'] - $position)];
            }
            $elements[] = ['param', $capture['name']];
            $position = $capture['end'];
        }
        if ($position < strlen($subject)) {
            $elements[] = ['static', substr($subject, $position)];
        }

        return self::normalizeElements($elements);
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
     * Static text is kept and each present parameter becomes "{name}"
     * ("user-{id}" stays "user-{id}", "{id}.{format}" stays "{id}.{format}").
     * An optional parameter that is absent is dropped together with the one
     * separator character before it, as Symfony's RouteCompiler (which
     * Laravel also uses) puts that character inside the optional
     * ("search.{_format?}" -> "search", "list-.{page?}" -> "list-").
     *
     * @return string|null The normalized element, or null if nothing is left
     */
    private static function normalizeBraceSegment(string $segment, array $matchedParams)
    {
        $pieces = preg_split('/(\{[^}]+\})/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts = [];
        foreach ($pieces as $i => $piece) {
            if ($i % 2 === 0) {
                if ($piece !== '') {
                    $parts[] = ['static', $piece];
                }
                continue;
            }

            $raw = substr($piece, 1, -1);
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
                $lastIndex = count($parts) - 1;
                if ($lastIndex >= 0 && $parts[$lastIndex][0] === 'static'
                    && strpos(self::OPTIONAL_SEPARATORS, substr($parts[$lastIndex][1], -1)) !== false) {
                    $parts[$lastIndex][1] = substr($parts[$lastIndex][1], 0, -1);
                    if ($parts[$lastIndex][1] === '') {
                        array_pop($parts);
                    }
                }
                continue;
            }

            $parts[] = ['param', $name];
        }

        if (!$parts) {
            return null;
        }

        return self::renderSegmentParts($parts);
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
     * Make a parameter name usable as an OpenAPI parameter name: characters
     * outside [A-Za-z0-9_] are replaced with '_'.
     */
    public static function encodeParamName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        return $name === '' ? '_' : $name;
    }
}
