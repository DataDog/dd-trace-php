<?php

namespace DDTrace\Util;

/** @internal */
class RouteNormalizer
{
    /**
     * Memo of WordPress rule plans. Each entry stores whether the rule is safe
     * to normalize (result of the hasOnlyCapturedWordPressDynamics scan), a
     * pure function of the rule. Like any PHP static it is reset at the end of
     * every request under FPM, mod_php and CGI, so there it saves nothing:
     * WordPress matches one rule per request. It only avoids rescanning in
     * runtimes that keep PHP state across requests, or across repeated calls.
     *
     * @var array<string, array{safe: bool}>
     */
    private static $wordPressRulePlans = [];

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
     * so static text is never read as route syntax. A segment with parameters
     * becomes one dynamic element joining their names with '+' (rule 5); a
     * catch-all becomes one dynamic element named paramN (rule 4); otherwise
     * the segment is a static constant.
     */
    public static function normalizeElements(array $elements): string
    {
        $segments = [self::newElementSegment('')];
        $names = [];
        foreach ($elements as $element) {
            $current = count($segments) - 1;
            switch ($element[0]) {
                case 'static':
                    $pieces = explode('/', $element[1]);
                    $segments[$current]['static'] .= array_shift($pieces);
                    foreach ($pieces as $piece) {
                        $segments[] = self::newElementSegment($piece);
                    }
                    break;
                case 'param':
                    $segments[$current]['params'][] = $element[1];
                    $names[$element[1]] = true;
                    break;
                case 'tail':
                    $segments[$current]['tail'] = true;
                    break;
            }
        }

        $last = end($segments);
        $trailingSlash = count($segments) > 1 && $last['static'] === '' && !$last['params']
            && !$last['tail'];

        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment['tail']) {
                $i = 1;
                while (isset($names['param' . $i])) {
                    $i++;
                }
                $names['param' . $i] = true;
                $normalized[] = '{param' . $i . '}';
            } elseif ($segment['params']) {
                $params = array_map([self::class, 'encodeParamName'], $segment['params']);
                $normalized[] = '{' . implode('+', $params) . '}';
            } elseif ($segment['static'] !== '') {
                $normalized[] = self::encodeStaticSegment($segment['static']);
            }
        }

        if (!$normalized) {
            return '/';
        }

        return '/' . implode('/', $normalized) . ($trailingSlash ? '/' : '');
    }

    private static function newElementSegment(string $static): array
    {
        return ['static' => $static, 'params' => [], 'tail' => false];
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
     * @return array{safe: bool}
     */
    public static function wordPressRulePlan(string $rule): array
    {
        if (isset(self::$wordPressRulePlans[$rule])) {
            return self::$wordPressRulePlans[$rule];
        }

        return self::$wordPressRulePlans[$rule] = [
            'safe' => self::hasOnlyCapturedWordPressDynamics($rule),
        ];
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
            // All params were optional and absent. Symfony's RouteCompiler,
            // which Laravel also uses, puts the one separator character before
            // a variable into its optional, so it goes with the variable and
            // the rest of the static text stays ("search.{_format?}" → "search",
            // "list-.{page?}" → "list-").
            $staticOnly = preg_replace('/[\/,;.:\-_~+*=@|]?\{[^}]+\}/', '', $segment);
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
