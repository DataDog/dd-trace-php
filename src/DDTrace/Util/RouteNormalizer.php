<?php

namespace DDTrace\Util;

/**
 * Normalizes framework-specific HTTP route strings to the _dd.appsec.normalized_route format
 * defined in RFC-1103.
 *
 * The normalized route is a slash-separated sequence of atomic elements where each element
 * is either a static constant (only [A-Za-z0-9.-~_], others URL-encoded) or a dynamic
 * parameter in {name} notation. Parameters in the same URL segment are combined with
 * the '+' marker.
 *
 * @internal
 */
class RouteNormalizer
{
    /**
     * Normalize a Laravel route URI.
     *
     * Laravel uses {param} for required and {param?} for optional parameters.
     * Mixed-segment routes like /photos/{id}.{format} become /photos/{id+format}.
     *
     * @param string $routeUri  URI from $route->uri(), e.g. "/users/{id}/{format?}"
     * @param array  $matchedParams  Parameters from $route->parameters(); used to resolve optionals
     * @return string|null Normalized route, or null on parse failure
     */
    public static function normalizeFromLaravel(string $routeUri, array $matchedParams = [])
    {
        return self::normalizeBraceRoute($routeUri, $matchedParams);
    }

    /**
     * Normalize a Slim route pattern.
     *
     * Slim uses {param} or {param:regex} for required parameters. Slim 4 supports optional
     * segments in square brackets: /users/{id}[/{format}].
     *
     * @param string $pattern       Pattern from $route->getPattern(), e.g. "/users/{id:[0-9]+}"
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
     * The path produced by EndpointCatalog::pathForRoute() already uses {param} notation.
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
     * Example: "/users/:id[.:format]"
     *
     * @param string      $template     Template from httpRouteTemplateFromMatchedRoute()
     * @param array       $matchedParams Matched params from $routeMatch->getParams()
     * @param string|null $urlPath      The raw request URL path; used to exclude optional
     *                                  sections whose params were injected by middleware
     *                                  rather than matched from the URL (e.g. Laminas API
     *                                  Tools VersionListener sets :version even without a
     *                                  /v1/ prefix).
     * @return string|null
     */
    public static function normalizeFromLaminas(string $template, array $matchedParams = [], $urlPath = null)
    {
        $expanded = self::expandBracketOptionals($template, $matchedParams, ':', $urlPath);
        // Convert wildcard segments ('*') to a {param} placeholder before brace conversion
        $expanded = preg_replace('#/\*$#', '/{param1}', $expanded);
        $braceFormat = self::colonParamsToBraces($expanded);
        return self::normalizeBraceRoute($braceFormat, $matchedParams);
    }

    /**
     * Normalize a CakePHP route template.
     *
     * CakePHP uses :param syntax and * / ** for catch-all segments.
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
     * The Yii integration builds a URL via Url::toRoute() with ":paramName" as
     * placeholder values, producing a path like "/articles/:id".
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
     * @param string $matchedRule Value of $wp->matched_rule
     * @return string|null
     */
    public static function normalizeFromWordPress(string $matchedRule)
    {
        $rule = $matchedRule;

        // Strip regex anchors
        $rule = ltrim($rule, '^');
        $rule = rtrim($rule, '$');

        // Strip optional trailing slash pattern "\/?" or "/?"
        if (preg_match('#\\\\?/\?$#', $rule, $m)) {
            $rule = substr($rule, 0, -strlen($m[0]));
        }

        $rule = trim($rule, '/');
        if ($rule === '') {
            return '/';
        }

        // Use bracket-aware splitting so that [^/] character classes are not split
        $segments = self::splitRegexBySlash($rule);
        $normalizedSegments = [];
        $paramIndex = 1;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\([^)]+\)$/', $segment)) {
                // Whole segment is a single capture group
                $normalizedSegments[] = '{param' . $paramIndex++ . '}';
            } elseif (preg_match('/[()[\].*+?|^${}\\\\]/', $segment)) {
                // Contains regex metacharacters → treat as dynamic
                $normalizedSegments[] = '{param' . $paramIndex++ . '}';
            } else {
                $normalizedSegments[] = self::encodeStaticSegment($segment);
            }
        }

        if (empty($normalizedSegments)) {
            return '/{param1}';
        }

        return '/' . implode('/', $normalizedSegments);
    }

    /**
     * Split a regex string by '/' but do not split inside character classes [...].
     * This prevents [^/] from being broken into two segments.
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

    // -------------------------------------------------------------------------
    // Core brace-format normalizer
    // -------------------------------------------------------------------------

    /**
     * Normalize a route that uses {param} notation.
     *
     * Handles:
     *   {param}        - required parameter
     *   {param?}       - optional parameter (resolved via $matchedParams)
     *   {param:regex}  - regex-constrained parameter (constraint stripped)
     *   [{param}]      - optional segment (Slim-style; only when $expandSquare=true)
     *   Static text mixed with params in a segment → single combined element
     *
     * @param bool $expandSquare  When true, expand Slim-style [...] optional sections
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

        $raw = ltrim($route, '/');
        $parts = explode('/', $raw);
        $normalizedSegments = [];

        foreach ($parts as $segment) {
            if ($segment === '') {
                continue;
            }

            $result = self::normalizeBraceSegment($segment, $matchedParams);
            if ($result === null) {
                // Optional segment whose parameter is absent — skip
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

            // Strip regex constraint: {param:regex} → param
            $colon = strpos($raw, ':');
            if ($colon !== false) {
                $raw = substr($raw, 0, $colon);
            }

            $name = trim($raw);

            if ($isOptional && !array_key_exists($name, $matchedParams)) {
                return null;
            }

            $paramNames[] = self::encodeParamName($name);
        }

        if (count($paramNames) === 1) {
            return '{' . $paramNames[0] . '}';
        }

        // Multiple params in the same segment → combine with the '+' marker
        return '{' . implode('+', $paramNames) . '}';
    }

    // -------------------------------------------------------------------------
    // Optional-section expanders
    // -------------------------------------------------------------------------

    /**
     * Expand Slim-style optional sections [...] in a route based on matched params.
     * Example: "/users/{id}[/{format}]" with format present → "/users/{id}/{format}"
     */
    private static function expandSquareBracketOptionals(string $route, array $matchedParams): string
    {
        // Expand from innermost to outermost by looping until stable
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
                        // Purely static optional section — include it (best effort)
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
     * Example: "/:id[.:format]" with format present → "/:id.:format"
     *
     * When $urlPath is provided, an optional section is only expanded if the
     * section text with param values substituted is a substring of $urlPath.
     * This prevents middleware-injected params (e.g. Laminas API Tools version)
     * from incorrectly triggering expansion of sections absent from the URL.
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

                    foreach ($innerParams as $param) {
                        if (!array_key_exists($param, $matchedParams)) {
                            continue;
                        }
                        if ($urlPath !== null) {
                            // Substitute the param value into the inner template text and
                            // verify the result is present in the actual URL path.  This
                            // filters out params injected by middleware (e.g. a default
                            // :version added by Laminas API Tools) that are not in the URL.
                            $value = (string)$matchedParams[$param];
                            $innerWithValue = preg_replace(
                                '/' . preg_quote($paramPrefix . $param, '/') . '/',
                                $value,
                                $inner
                            );
                            if (strpos($urlPath, $innerWithValue) === false) {
                                continue;
                            }
                        }
                        return $inner;
                    }

                    return '';
                },
                $template
            );
        }
        return $template;
    }

    // -------------------------------------------------------------------------
    // Framework-specific syntax converters
    // -------------------------------------------------------------------------

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
     * Handles :param and * / ** catch-all segments.
     */
    private static function cakephpToBraces(string $template): string
    {
        // Replace /** and /* with a {catchall} placeholder
        $result = preg_replace('#/\*\*#', '/{catchall}', $template);
        $result = preg_replace('#/\*(?!\*)#', '/{catchall}', $result);

        // Replace remaining bare * (e.g. at start or standalone segment)
        $result = preg_replace('#(?<![/*])\*(?![/*])#', '{catchall}', $result);

        return preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function ($m) {
                return '{' . $m[1] . '}';
            },
            $result
        );
    }

    // -------------------------------------------------------------------------
    // Character-level encoding helpers
    // -------------------------------------------------------------------------

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
     * Reserved: /?#+{} — these must not appear literally.
     * The '+' sign is the combining marker and must be encoded if it appears in a
     * framework-supplied name.
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
