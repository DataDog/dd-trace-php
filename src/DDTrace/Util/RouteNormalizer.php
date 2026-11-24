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

    /**
     * A variable name in a Symfony route, and so in a Laravel one, which
     * Laravel compiles with Symfony's RouteCompiler: word characters and
     * non-ASCII bytes. Braces around anything else, such as {file-name},
     * are literal text.
     */
    const BRACE_VARIABLE_NAME = '[\w\x80-\xFF]+';

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
        // Only valid variable names are variables (see BRACE_VARIABLE_NAME).
        $path = preg_replace_callback(
            '/\{(' . self::BRACE_VARIABLE_NAME . ')\}/',
            static function ($m) use ($matchedParams) {
                return array_key_exists($m[1], $matchedParams) ? $m[0] : '{' . $m[1] . '?}';
            },
            $path
        );
        return self::normalizeBraceRoute($path, $matchedParams);
    }

    /**
     * Normalize a route that a framework adapter has already resolved into a
     * sequence of elements (RFC-1125):
     *
     *  - ['static', text]: text the request matched verbatim, in URL form;
     *  - ['param', name]: a named parameter that took part in the match;
     *  - ['unnamed']: an unnamed parameter that took part in the match;
     *  - ['tail']: an unnamed catch-all that matched a non-empty value.
     *
     * Elements are grouped into URL segments by the slashes in static text,
     * so static text is never read as route syntax. Static text is kept and
     * each parameter becomes its own template expression, in place, so the
     * result is an OpenAPI path template ("user-{id}", "{id}.{format}",
     * "/api{param1}"). Names are made valid with uniqueParamNames().
     *
     * $percentIsEscape tells whether the framework treats a '%' in static
     * text as the start of an escape (it matched the encoded path) or as a
     * literal character (it matched the decoded path).
     */
    public static function normalizeElements(array $elements, bool $percentIsEscape = true): string
    {
        $segments = [[]];
        $names = [];
        foreach ($elements as $element) {
            $current = count($segments) - 1;
            switch ($element[0]) {
                case 'static':
                    $pieces = explode('/', $element[1]);
                    $first = array_shift($pieces);
                    if ($first !== '') {
                        $segments[$current][] = ['static', $first];
                    }
                    foreach ($pieces as $piece) {
                        $segments[] = $piece !== '' ? [['static', $piece]] : [];
                    }
                    break;
                case 'param':
                    $segments[$current][] = ['param', count($names)];
                    $names[] = (string) $element[1];
                    break;
                case 'unnamed':
                case 'tail':
                    $segments[$current][] = ['param', count($names)];
                    $names[] = null;
                    break;
            }
        }

        $last = end($segments);
        $trailingSlash = count($segments) > 1 && !$last;
        $finalNames = self::uniqueParamNames($names);

        $normalized = [];
        foreach ($segments as $parts) {
            if (!$parts) {
                continue;
            }
            // Adjacent static fragments are joined before encoding, so that
            // an escape split across them (e.g. '%' and 'C3') is kept whole.
            $out = '';
            $static = '';
            foreach ($parts as $part) {
                if ($part[0] === 'param') {
                    $out .= self::encodeStaticSegment($static, $percentIsEscape)
                        . '{' . $finalNames[$part[1]] . '}';
                    $static = '';
                } else {
                    $static .= $part[1];
                }
            }
            $normalized[] = $out . self::encodeStaticSegment($static, $percentIsEscape);
        }

        if (!$normalized) {
            return '/';
        }

        return '/' . implode('/', $normalized) . ($trailingSlash ? '/' : '');
    }

    /**
     * The names of a route's parameters, in path order, as RFC-1125 requires:
     *
     *  - a name that is already valid ([A-Za-z0-9_]) keeps it;
     *  - other characters (each Unicode code point) are replaced with '_';
     *    a replaced name that equals a name already in the route gets the
     *    smallest numeric suffix not used in the route (user_id_2, ...), so
     *    replaced names that collide with each other are suffixed in path
     *    order;
     *  - an unnamed parameter (null) gets param<N>, the smallest N not used.
     *
     * @param array<int, string|null> $names
     * @return array<int, string>
     */
    public static function uniqueParamNames(array $names): array
    {
        $used = [];
        foreach ($names as $name) {
            if ($name !== null && self::encodeParamName($name) === $name) {
                $used[$name] = true;
            }
        }

        $final = [];
        foreach ($names as $i => $name) {
            if ($name === null) {
                continue;
            }
            $encoded = self::encodeParamName($name);
            if ($encoded === $name) {
                $final[$i] = $name;
                continue;
            }
            $candidate = $encoded;
            for ($k = 2; isset($used[$candidate]); $k++) {
                $candidate = $encoded . '_' . $k;
            }
            $used[$candidate] = true;
            $final[$i] = $candidate;
        }

        $n = 1;
        foreach ($names as $i => $name) {
            if ($name !== null) {
                continue;
            }
            while (isset($used['param' . $n])) {
                $n++;
            }
            $used['param' . $n] = true;
            $final[$i] = 'param' . $n;
        }

        ksort($final);
        return $final;
    }

    /**
     * Normalize a WordPress matched_rule (regex) by matching it again and
     * deriving the route from PCRE's capture offsets.
     *
     * WordPress route matching uses regex rules like "^blog/([^/]+)/?$".
     * Per RFC-1125's whole-route regex rule, text outside the captures is
     * static text, each capture that took part is a parameter (PCRE supplies
     * declared names; unnamed captures get param<N>), and a capture that did
     * not take part is absent. Rules that can consume variable text outside a
     * capture are rejected: the route cannot be expressed, because their
     * uncaptured request text would otherwise become a route constant.
     *
     * Like WP::parse_request(), the rule is matched against the subject and,
     * if that fails, against the URL-decoded subject; static text then comes
     * from the decoded subject, where '%' is a literal character. WordPress
     * matches with "#^rule#", so a rule without "$" matches a prefix only: it
     * is a prefix route, and the rest of the path is its implicit unnamed
     * catch-all.
     *
     * $urlPath is required to tell which optional captures took part. When
     * the rule does not match it, the route falls back to the rule's
     * mandatory parts (see normalizeFromWordPressRule()).
     *
     * @param string $matchedRule Value of $wp->matched_rule
     * @param string $urlPath     Value of $wp->request, the subject WordPress
     *                            matched the rule against
     * @return string|null
     */
    public static function normalizeFromWordPress(string $matchedRule, string $urlPath)
    {
        // An empty rule is no route: WordPress matched no rule (RFC-1125).
        if ($matchedRule === '' || !self::wordPressRulePlan($matchedRule)['safe']) {
            return null;
        }

        // WordPress uses # delimiters when selecting matched_rule, so an
        // unescaped # could not occur in a rule that successfully matched.
        $pattern = '#^' . $matchedRule . '#';

        $subject = trim($urlPath, '/');
        $percentIsEscape = true;
        $matches = [];
        $flags = PREG_OFFSET_CAPTURE;
        if (defined('PREG_UNMATCHED_AS_NULL')) {
            $flags |= constant('PREG_UNMATCHED_AS_NULL');
        }
        if (@preg_match($pattern, $subject, $matches, $flags) !== 1) {
            $subject = urldecode($subject);
            $percentIsEscape = false;
            if (@preg_match($pattern, $subject, $matches, $flags) !== 1) {
                // WordPress matched another subject: which captures took part
                // is unknown, so fall back to the rule's mandatory parts.
                return self::normalizeFromWordPressRule($matchedRule);
            }
        }
        if ($matches[0][1] !== 0) {
            return null;
        }

        $matchedLength = strlen($matches[0][0]);
        $elements = self::regexMatchElements($matchedRule, $subject, 0, $matchedLength, $matches);
        if ($elements === null) {
            return null;
        }

        // The rest of a prefix match is a catch-all: absent when empty or
        // only '/', and placed like any catch-all otherwise.
        $rest = (string) substr($subject, $matchedLength);
        if (trim($rest, '/') !== '') {
            if ($rest[0] === '/') {
                $elements[] = ['static', '/'];
            }
            $elements[] = ['tail'];
        }

        return self::normalizeElements($elements, $percentIsEscape);
    }

    /**
     * The fallback route of a WordPress rule when the subject WordPress matched
     * it against is unknown (RFC-1125): its literal text and its mandatory
     * captures, without the optional parts, whose presence cannot be
     * determined. Null when the rule cannot be expressed as a route.
     *
     * @return string|null
     */
    public static function normalizeFromWordPressRule(string $matchedRule)
    {
        if ($matchedRule === '' || !self::wordPressRulePlan($matchedRule)['safe']) {
            return null;
        }
        $elements = self::regexFallbackElements($matchedRule);

        return $elements === null ? null : self::normalizeElements($elements);
    }

    /**
     * Parse a WordPress rule once and remember whether it is safe to normalize.
     * The returned shape:
     *   [
     *     'safe' => bool,  // result of regexHasOnlyCapturedDynamics
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

        $safe = self::regexHasOnlyCapturedDynamics($rule);
        \DDTrace\routing_cache_set($cacheKey, $safe ? '1' : '0');

        return ['safe' => $safe];
    }

    /**
     * Whether a whole-route regex can be expressed as a route (RFC-1125): the
     * text it can match outside capture groups is plain literal text.
     *
     * This is a rejection filter, not a PCRE parser. Capture bodies are opaque.
     * Outside captures, only literal text, fixed alternatives, optional fixed text,
     * and non-capturing wrappers are allowed.
     */
    public static function regexHasOnlyCapturedDynamics(string $rule): bool
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
                $posixEnd = self::regexPosixClassEnd($rule, $i);
                if ($posixEnd !== null) {
                    $i = $posixEnd;
                } elseif ($char === '\\' && isset($rule[$i + 1])) {
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
                } elseif ($captureDepth === 0 && self::isAsciiAlnum($escaped)) {
                    return false;
                }
                continue;
            }
            if ($char === '[') {
                if ($captureDepth === 0) {
                    return false;
                }
                $inClass = true;
                // A ']' right after '[' or '[^' is literal, not the end of the class.
                if (isset($rule[$i + 1]) && $rule[$i + 1] === '^') {
                    $i++;
                }
                if (isset($rule[$i + 1]) && $rule[$i + 1] === ']') {
                    $i++;
                }
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
                } elseif ($captureDepth === 0 && self::regexCaptureGroupsNamedCapture($rule, $i)) {
                    // An unnamed capture around a named one only groups it
                    // (see regexMatchElements()), so its other text must be
                    // literal too.
                    $capturing = false;
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
     * The elements of a whole-route regex match (RFC-1125): the text of
     * $subject between $start and $end outside the captures that took part is
     * static text, and each such capture is a parameter, named or unnamed. A
     * capture that matched an empty value is absent. Nested captures are
     * covered by the outermost one. A capture whose value contains '/' is a
     * single parameter spanning those segments.
     *
     * $matches are preg_match() matches of $regex with PREG_OFFSET_CAPTURE. Null when
     * the capture names do not line up with their groups.
     *
     * @return array|null
     */
    public static function regexMatchElements(string $regex, string $subject, int $start, int $end, array $matches)
    {
        $captures = self::wordPressNumericCaptures($matches);
        if ($captures === null || !self::applyWordPressCaptureNames($matches, $captures)) {
            return null;
        }

        $wrappers = self::regexWrapperGroups($regex);
        $present = [];
        foreach ($captures as $group => $capture) {
            // An unnamed capture around a named one only groups it (e.g.
            // (\.(?<format>[a-z]+))?): the named capture is the parameter.
            if (!$capture['present'] || isset($wrappers[$group])) {
                continue;
            }
            $present[] = [
                'start' => $capture['offset'],
                'end' => $capture['offset'] + strlen($capture['value']),
                'name' => $capture['name'],
            ];
        }
        usort($present, static function ($a, $b) {
            return $a['start'] === $b['start'] ? $b['end'] - $a['end'] : $a['start'] - $b['start'];
        });

        $elements = [];
        $position = $start;
        foreach ($present as $capture) {
            if ($capture['start'] < $position || $capture['end'] > $end) {
                // Nested in (or overlapping) a capture already emitted.
                continue;
            }
            if ($capture['start'] > $position) {
                $elements[] = ['static', substr($subject, $position, $capture['start'] - $position)];
            }
            $elements[] = $capture['name'] !== null ? ['param', $capture['name']] : ['unnamed'];
            $position = $capture['end'];
        }
        if ($position < $end) {
            $elements[] = ['static', substr($subject, $position, $end - $position)];
        }

        return $elements;
    }

    /**
     * The fallback of a whole-route regex whose match cannot be replayed: the
     * route with its mandatory parts only (RFC-1125). Literal text outside
     * captures is static text, a mandatory capture is a parameter, and an
     * optional part (a capture, a literal or a group followed by ?, * or
     * {0,...}) is left out. Null when the regex cannot be expressed that way:
     * anything other than literal text outside captures, or an alternation
     * that is not inside an optional part (which branch matched is unknown).
     *
     * @return array|null
     */
    public static function regexFallbackElements(string $regex)
    {
        $i = 0;
        $elements = self::regexFallbackSequence($regex, $i);
        return $elements === null || $i !== strlen($regex) ? null : $elements;
    }

    /** @return array|null the elements of $regex from $i up to the ')' or end that closes it */
    private static function regexFallbackSequence(string $regex, int &$i)
    {
        $elements = [];
        $length = strlen($regex);
        while ($i < $length) {
            $char = $regex[$i];
            if ($char === ')') {
                return $elements;
            }
            if ($char === '|' || $char === '[' || $char === '.' || strpos('*+?{', $char) !== false) {
                return null;
            }
            if ($char === '^' || $char === '$') {
                $i++;
                continue;
            }
            if ($char === '(') {
                $close = self::regexGroupEnd($regex, $i);
                if ($close === null) {
                    return null;
                }
                $quantifier = self::regexQuantifier($regex, $close + 1);
                if ($quantifier === null) {
                    return null;
                }
                list($optional, $next) = $quantifier;
                if ($regex[$i + 1] === '*') {
                    // (*VERB) and other special groups.
                    return null;
                }
                $named = self::wordPressNamedCaptureEnd($regex, $i);
                if (self::regexCaptureGroupsNamedCapture($regex, $i)) {
                    // An unnamed capture around a named one only groups it, as
                    // in regexMatchElements().
                    if (!$optional) {
                        $inner = $i + 1;
                        $innerElements = self::regexFallbackSequence($regex, $inner);
                        if ($innerElements === null || $inner !== $close) {
                            return null;
                        }
                        $elements = array_merge($elements, $innerElements);
                    }
                } elseif ($named !== null || $regex[$i + 1] !== '?') {
                    if (!$optional) {
                        $elements[] = $named !== null
                            ? ['param', (string) preg_replace('/^\(\?P?[<\']/', '', substr($regex, $i, $named - $i))]
                            : ['unnamed'];
                    }
                } elseif (substr($regex, $i + 1, 2) === '?:') {
                    if (!$optional) {
                        $inner = $i + 3;
                        $innerElements = self::regexFallbackSequence($regex, $inner);
                        if ($innerElements === null || $inner !== $close) {
                            return null;
                        }
                        $elements = array_merge($elements, $innerElements);
                    }
                } else {
                    // Lookarounds, inline options and other special groups.
                    return null;
                }
                $i = $next;
                continue;
            }

            if ($char === '\\') {
                if (!isset($regex[$i + 1])) {
                    return null;
                }
                if ($regex[$i + 1] === 'Q') {
                    $end = strpos($regex, '\\E', $i + 2);
                    $text = $end === false ? substr($regex, $i + 2) : substr($regex, $i + 2, $end - $i - 2);
                    $i = $end === false ? $length : $end + 2;
                    if ($text !== '') {
                        $elements[] = ['static', $text];
                    }
                    continue;
                }
                if (self::isAsciiAlnum($regex[$i + 1])) {
                    return null;
                }
                $text = $regex[$i + 1];
                $after = $i + 2;
            } else {
                $text = $char;
                $after = $i + 1;
            }
            $quantifier = self::regexQuantifier($regex, $after);
            if ($quantifier === null) {
                return null;
            }
            list($optional, $i) = $quantifier;
            if (!$optional) {
                $elements[] = ['static', $text];
            }
        }

        return $elements;
    }

    /**
     * The quantifier at $at, if any: [whether it can skip what it follows,
     * the index after it]. Null for a quantifier that repeats: the matched
     * text is then not fixed.
     *
     * @return array{0: bool, 1: int}|null
     */
    private static function regexQuantifier(string $regex, int $at)
    {
        $next = $regex[$at] ?? '';
        if ($next === '?' || $next === '*') {
            if ($next === '*') {
                return null;
            }
            $at++;
            if (isset($regex[$at]) && ($regex[$at] === '?' || $regex[$at] === '+')) {
                $at++;
            }
            return [true, $at];
        }
        if ($next === '+') {
            return null;
        }
        if ($next === '{' && preg_match('/\G\{(\d+)(?:(,)(\d*))?\}/', $regex, $m, 0, $at)) {
            $min = (int) $m[1];
            $max = !isset($m[2]) || $m[2] === '' ? $min : (!isset($m[3]) || $m[3] === '' ? null : (int) $m[3]);
            if ($max === 0) {
                return [true, $at + strlen($m[0])];
            }
            if ($min === 0 && $max === 1) {
                return [true, $at + strlen($m[0])];
            }
            if ($min === 1 && $max === 1) {
                return [false, $at + strlen($m[0])];
            }
            return null;
        }

        return [false, $at];
    }

    /**
     * The numbers of the unnamed capture groups of $regex that have a named
     * capture inside.
     *
     * @return array<int, true>
     */
    private static function regexWrapperGroups(string $regex): array
    {
        $wrappers = [];
        $group = 0;
        $length = strlen($regex);
        for ($i = 0; $i < $length; $i++) {
            $char = $regex[$i];
            if ($char === '\\') {
                if (isset($regex[$i + 1]) && $regex[$i + 1] === 'Q') {
                    $end = strpos($regex, '\\E', $i + 2);
                    if ($end === false) {
                        break;
                    }
                    $i = $end + 1;
                } else {
                    $i++;
                }
                continue;
            }
            if ($char === '[') {
                $i++;
                if (isset($regex[$i]) && $regex[$i] === '^') {
                    $i++;
                }
                if (isset($regex[$i]) && $regex[$i] === ']') {
                    $i++;
                }
                while ($i < $length && $regex[$i] !== ']') {
                    $posixEnd = self::regexPosixClassEnd($regex, $i);
                    if ($posixEnd !== null) {
                        $i = $posixEnd;
                    } elseif ($regex[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                continue;
            }
            if ($char !== '(') {
                continue;
            }
            if (self::wordPressNamedCaptureEnd($regex, $i) !== null) {
                $group++;
            } elseif (!isset($regex[$i + 1]) || ($regex[$i + 1] !== '?' && $regex[$i + 1] !== '*')) {
                $group++;
                if (self::regexCaptureGroupsNamedCapture($regex, $i)) {
                    $wrappers[$group] = true;
                }
            }
        }

        return $wrappers;
    }

    /**
     * Where a POSIX bracket expression inside a character class ends:
     * [:alpha:], [.a.] or [=a=] at $i, as PCRE's check_posix_syntax() reads
     * it. Its ']' does not end the enclosing class. Returns the index of that
     * ']', or null when $i does not start one (the '[' is then a literal).
     *
     * @return int|null
     */
    private static function regexPosixClassEnd(string $regex, int $i)
    {
        if (!isset($regex[$i + 1]) || $regex[$i] !== '[' || strpos(':.=', $regex[$i + 1]) === false) {
            return null;
        }
        $terminator = $regex[$i + 1];
        $length = strlen($regex);
        for ($j = $i + 2; $j < $length; $j++) {
            $char = $regex[$j];
            if ($char === '\\' && isset($regex[$j + 1]) && ($regex[$j + 1] === ']' || $regex[$j + 1] === '\\')) {
                $j++;
            } elseif (($char === '[' && isset($regex[$j + 1]) && $regex[$j + 1] === $terminator) || $char === ']') {
                return null;
            } elseif ($char === $terminator && isset($regex[$j + 1]) && $regex[$j + 1] === ']') {
                return $j + 1;
            }
        }

        return null;
    }

    /** Whether the group opened at $open is an unnamed capture with a named capture inside. */
    private static function regexCaptureGroupsNamedCapture(string $regex, int $open): bool
    {
        if (isset($regex[$open + 1]) && ($regex[$open + 1] === '?' || $regex[$open + 1] === '*')) {
            return false;
        }
        $close = self::regexGroupEnd($regex, $open);
        if ($close === null) {
            return false;
        }

        return preg_match(
            '/\(\?(?:P?<[A-Za-z_]|\'[A-Za-z_])/',
            substr($regex, $open + 1, $close - $open - 1)
        ) === 1;
    }

    /** @return int|null the index of the ')' that closes the group opened at $open */
    private static function regexGroupEnd(string $regex, int $open)
    {
        $depth = 0;
        $length = strlen($regex);
        for ($i = $open; $i < $length; $i++) {
            $char = $regex[$i];
            if ($char === '\\') {
                if (isset($regex[$i + 1]) && $regex[$i + 1] === 'Q') {
                    $end = strpos($regex, '\\E', $i + 2);
                    if ($end === false) {
                        return null;
                    }
                    $i = $end + 1;
                } else {
                    $i++;
                }
                continue;
            }
            if ($char === '[') {
                $i++;
                if (isset($regex[$i]) && $regex[$i] === '^') {
                    $i++;
                }
                if (isset($regex[$i]) && $regex[$i] === ']') {
                    $i++;
                }
                while ($i < $length && $regex[$i] !== ']') {
                    $posixEnd = self::regexPosixClassEnd($regex, $i);
                    if ($posixEnd !== null) {
                        $i = $posixEnd;
                    } elseif ($regex[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Normalize a route that uses {param} notation (Laravel, Symfony). Both
     * match the route against the decoded path, so a '%' in the route is a
     * literal character.
     */
    private static function normalizeBraceRoute(string $route, array $matchedParams)
    {
        $route = trim($route);
        if ($route === '' || $route === '/') {
            return '/';
        }

        $trailingSlash = strlen($route) > 1 && substr($route, -1) === '/';
        $route = rtrim($route, '/');

        $elements = [];
        foreach (explode('/', ltrim($route, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            $parts = self::normalizeBraceSegment($segment, $matchedParams);
            if (!$parts) {
                continue;
            }

            $elements[] = ['static', '/'];
            array_push($elements, ...$parts);
        }
        if ($trailingSlash) {
            $elements[] = ['static', '/'];
        }

        return self::normalizeElements($elements, false);
    }

    /**
     * Resolve a single URL segment that may contain {param} placeholders into
     * normalizeElements() elements. Static text is kept and each present
     * parameter is its own element ("user-{id}" stays "user-{id}",
     * "{id}.{format}" stays "{id}.{format}"). An optional parameter that is
     * absent is dropped together with the one separator character before it,
     * as Symfony's RouteCompiler (which Laravel also uses) puts that character
     * inside the optional ("search.{_format?}" -> "search", "list-.{page?}"
     * -> "list-").
     *
     * @return array The segment's elements; empty if nothing is left
     */
    private static function normalizeBraceSegment(string $segment, array $matchedParams): array
    {
        // Only braces around a valid variable name are a variable, as for
        // Symfony's RouteCompiler: anything else, including braces around a
        // variable ({{id}}), is literal text.
        $pieces = preg_split(
            '/(\{' . self::BRACE_VARIABLE_NAME . '\??\})/',
            $segment,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
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
            $name = $raw;

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

        return $parts;
    }

    /**
     * Percent-encode static text: every character other than A-Z a-z 0-9
     * - . _ ~ is encoded with uppercase hex (RFC-1125).
     *
     * When $percentIsEscape is true (the framework matched the encoded path),
     * a percent-encoded sequence is kept, with its hex uppercased, and never
     * encoded again (%c3%a9 -> %C3%A9). When false (the framework matched the
     * decoded path, so '%' is a literal character), '%' is encoded as %25.
     */
    public static function encodeStaticSegment(string $segment, bool $percentIsEscape = true): string
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
                $percentIsEscape &&
                $c === '%' &&
                $i + 2 < $len &&
                self::isHexDigit($segment[$i + 1]) &&
                self::isHexDigit($segment[$i + 2])
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
     * Whether $char is an ASCII letter or digit. ctype is an optional
     * extension, so it is not used here.
     */
    private static function isAsciiAlnum(string $char): bool
    {
        return $char !== '' && strspn($char, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789') === 1;
    }

    /** Whether $char is a hexadecimal digit, without the optional ctype extension. */
    private static function isHexDigit(string $char): bool
    {
        return $char !== '' && strspn($char, '0123456789ABCDEFabcdef') === 1;
    }

    /**
     * Make a parameter name usable as an OpenAPI parameter name: each Unicode
     * code point outside [A-Za-z0-9_] is replaced with '_' ("föo" -> "f_o").
     * A name that is not valid UTF-8 is replaced byte by byte. Collisions are
     * resolved by uniqueParamNames().
     */
    public static function encodeParamName(string $name): string
    {
        $encoded = @preg_replace('/[^A-Za-z0-9_]/u', '_', $name);
        if ($encoded === null) {
            $encoded = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        }
        return $encoded === '' ? '_' : $encoded;
    }
}
