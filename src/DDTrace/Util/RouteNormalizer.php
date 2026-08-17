<?php

namespace DDTrace\Util;

/** @internal */
class RouteNormalizer
{
    /**
     * Normalize a Laravel route URI.
     *
     * @param string $routeUri     URI from $route->uri(), e.g. "/users/{id}/{format?}"
     * @param array  $matchedParams Parameters from $route->parameters(); used to resolve optionals
     * @return string|null
     */
    public static function normalizeFromLaravel(string $routeUri, array $matchedParams = [])
    {
        return self::normalizeBraceRoute($routeUri, $matchedParams);
    }

    /**
     * Normalize a Slim route pattern.
     *
     * @param string $pattern      Pattern from $route->getPattern(), e.g. "/users/{id:[0-9]+}"
     * @param array  $matchedParams Matched params from $route->getArguments(); resolves optionals
     * @return string|null
     */
    public static function normalizeFromSlim(string $pattern, array $matchedParams = [])
    {
        return self::normalizeBraceRoute($pattern, $matchedParams, true);
    }

    /**
     * Normalize a Symfony route path.
     *
     * @param string $path Path template, e.g. "/users/{id}"
     * @return string|null
     */
    public static function normalizeFromSymfony(string $path)
    {
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
        $expanded = preg_replace('#/\*$#', '/{param1}', $expanded);
        $braceFormat = self::colonParamsToBraces($expanded);
        return self::normalizeBraceRoute($braceFormat, $matchedParams);
    }

    /**
     * Normalize a CakePHP route template.
     *
     * @param string $template Template from $app->template, e.g. "/articles/:id.:ext"
     * @return string|null
     */
    public static function normalizeFromCakePHP(string $template)
    {
        $braceFormat = self::cakephpToBraces($template);
        return self::normalizeBraceRoute($braceFormat, []);
    }

    /**
     * Normalize a Yii route path containing :param placeholders.
     *
     * @param string $routePath Path from Url::toRoute() with colon placeholders
     * @return string|null
     */
    public static function normalizeFromYii(string $routePath)
    {
        $braceFormat = self::colonParamsToBraces($routePath);
        return self::normalizeBraceRoute($braceFormat, []);
    }

    /**
     * Normalize a CodeIgniter V2 route pattern.
     *
     * CodeIgniter uses :any / :num wildcards and positional regex groups.
     * Named parameters are not available, so placeholders param1, param2, … are used.
     *
     * @param string $route Route key from $router->routes, e.g. "blog/(:num)"
     * @return string|null
     */
    public static function normalizeFromCodeIgniter(string $route)
    {
        $route = trim($route, '/');
        if ($route === '') {
            return '/';
        }

        $segments = explode('/', $route);
        $normalizedSegments = [];
        $paramIndex = 1;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $lower = strtolower($segment);
            if (
                $lower === ':any' || $lower === ':num' ||
                $lower === '(:any)' || $lower === '(:num)'
            ) {
                $normalizedSegments[] = '{param' . $paramIndex++ . '}';
            } elseif (preg_match('/[()[\].*+?|^$\\\\]/', $segment) || strpos($segment, ':') !== false) {
                $normalizedSegments[] = '{param' . $paramIndex++ . '}';
            } else {
                $normalizedSegments[] = self::encodeStaticSegment($segment);
            }
        }

        return '/' . implode('/', $normalizedSegments);
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

        $segments = self::splitRegexBySlash($rule);
        $normalizedSegments = [];
        $paramIndex = 1;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/[()[\].*+?|^${}\\\\]/', $segment)) {
                $groupCount = self::countCaptureGroups($segment);
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
     * Split a regex string by '/' but not inside character classes [...].
     * Prevents [^/] from being split into two segments.
     */
    private static function splitRegexBySlash(string $str): array
    {
        $segments = [];
        $current = '';
        $len = strlen($str);
        $bracketDepth = 0;

        for ($i = 0; $i < $len; $i++) {
            $c = $str[$i];

            if ($c === '\\' && $i + 1 < $len) {
                $current .= $c . $str[$i + 1];
                $i++;
                continue;
            }

            if ($c === '[') {
                $bracketDepth++;
                $current .= $c;
            } elseif ($c === ']' && $bracketDepth > 0) {
                $bracketDepth--;
                $current .= $c;
            } elseif ($c === '/' && $bracketDepth === 0) {
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
     *
     * @param bool $expandSquare When true, expand Slim-style [...] optional sections
     */
    private static function normalizeBraceRoute(
        string $route,
        array $matchedParams,
        bool $expandSquare = false
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

        if ($expandSquare) {
            $route = self::expandSquareBracketOptionals($route, $matchedParams);
        }

        // Strip inline constraints (e.g. Slim's {name:[^/]+} → {name}) before
        // splitting so that a '/' inside a constraint does not break the segment
        // split. The optional marker '?' is preserved: {name?:[0-9]+} → {name?}.
        $route = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*(\?)?):([^}]*)\}/', '{$1}', $route);

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
            return null;
        }

        if (count($paramNames) === 1) {
            return '{' . $paramNames[0] . '}';
        }

        return '{' . implode('+', $paramNames) . '}';
    }

    /**
     * Expand Slim-style optional sections [...] based on matched params.
     */
    private static function expandSquareBracketOptionals(string $route, array $matchedParams): string
    {
        $prev = null;
        while ($prev !== $route) {
            $prev = $route;
            $route = preg_replace_callback(
                '/\[([^\[\]]*)\]/',
                function ($m) use ($matchedParams) {
                    $inner = $m[1];
                    preg_match_all('/\{([^}?:]+)[?:]?[^}]*\}/', $inner, $pm);
                    $innerParams = $pm[1];

                    if (empty($innerParams)) {
                        return $inner;
                    }

                    foreach ($innerParams as $param) {
                        if (array_key_exists($param, $matchedParams)) {
                            return $inner;
                        }
                    }

                    return '';
                },
                $route
            );
        }
        return $route;
    }

    /**
     * Expand Laminas [...] optional sections based on matched params.
     *
     * When $urlPath is provided, an optional section is only expanded if the
     * section text with param values substituted is a substring of $urlPath.
     * This prevents middleware-injected params from incorrectly triggering
     * expansion of sections absent from the URL.
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
                    $pattern = '/' . preg_quote($paramPrefix, '/') . '([a-zA-Z_][a-zA-Z0-9_]*)/';
                    preg_match_all($pattern, $inner, $pm);
                    $innerParams = $pm[1];

                    // All params in the section must be present in matched params.
                    foreach ($innerParams as $param) {
                        if (!array_key_exists($param, $matchedParams)) {
                            return '';
                        }
                    }

                    if ($urlPath !== null && !empty($innerParams)) {
                        // Substitute every param value before checking the URL so that
                        // multi-param sections like [/:year/:month] are found correctly.
                        $innerWithValues = $inner;
                        foreach ($innerParams as $param) {
                            $value = (string)$matchedParams[$param];
                            $innerWithValues = preg_replace(
                                '/' . preg_quote($paramPrefix . $param, '/') . '/',
                                $value,
                                $innerWithValues
                            );
                        }
                        if (strpos($urlPath, $innerWithValues) === false) {
                            return '';
                        }
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
     */
    private static function colonParamsToBraces(string $template): string
    {
        return preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)(?:\{[^}]*\})?/',
            static function ($m) {
                return '{' . $m[1] . '}';
            },
            $template
        );
    }

    /**
     * Convert CakePHP route template syntax to brace notation.
     */
    private static function cakephpToBraces(string $template): string
    {
        $result = preg_replace('#/\*\*#', '/{catchall}', $template);
        $result = preg_replace('#/\*(?!\*)#', '/{catchall}', $result);
        $result = preg_replace('#(?<![/*])\*(?![/*])#', '{catchall}', $result);

        return preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function ($m) {
                return '{' . $m[1] . '}';
            },
            $result
        );
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
