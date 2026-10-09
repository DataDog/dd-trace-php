<?php

namespace DDTrace\Integrations\Symfony;

use DDTrace\Util\RouteNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;


class EndpointCatalog
{
    /** Starts the normalization part of a cache entry that needs a replay. */
    const REPLAY_MARKER = "\1";

    public static function generate(ContainerInterface $container): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }

        $cacheDir = self::getCacheDir($container);
        if ($cacheDir) {
            $fromCache = self::fromRouterCache($cacheDir);
            if (!empty($fromCache)) {
                return $memo = $fromCache;
            }
        }

        // Fallback slower
        $fromCollection = self::fromRouteCollection($container);
        return $memo = $fromCollection;
    }


    public static function normalizationCacheKey($routeName, $locale, ContainerInterface $container)
    {
        $cacheDir = self::getCacheDir($container);
        if ($cacheDir === null) {
            return null;
        }

        return self::normalizationCacheKeyForCacheDir($cacheDir, $routeName, $locale);
    }

    /**
     * The routing cache outlives the request, so the key includes the compiled
     * generator file's modification time. Otherwise a route table rebuilt in
     * place, without a worker restart, would keep being served from the cache.
     *
     * Returns null when the generator file is missing, and while it is too
     * recent to be cached: within the second of its modification time, a
     * further rebuild would keep the same key, and within opcache's
     * revalidation window, require may still return the previous version.
     */
    public static function normalizationCacheKeyForCacheDir(string $cacheDir, $routeName, $locale)
    {
        $genFile = self::findGeneratorFile($cacheDir);
        if ($genFile === null) {
            return null;
        }

        $mtime = @filemtime($genFile);
        if ($mtime === false || time() - $mtime <= self::opcacheRevalidateWindow()) {
            return null;
        }

        return "symfony:v2\0" . $genFile . "\0" . $mtime . "\0" . $routeName . "\0" . (string) $locale;
    }

    /**
     * Builds the routing cache entry for a matched route: its http.route,
     * followed, when $withNormalization is set, by "\0" and the normalization
     * that normalizedRouteFromCacheEntry() resolves. An entry without "\0" has
     * no normalization computed yet.
     *
     * The route is looked up by name, then with the locale suffix of Symfony's
     * i18n routes. Returns null when the generator cache has neither.
     *
     * @return string|null
     */
    public static function routeCacheEntry($routeName, $locale, ContainerInterface $container, bool $withNormalization)
    {
        $cacheDir = self::getCacheDir($container);
        if ($cacheDir === null) {
            return null;
        }

        $gen = self::loadGeneratorArray($cacheDir);
        if ($gen === false) {
            return null;
        }

        $routeData = isset($gen[$routeName]) ? $gen[$routeName] : null;
        $plan = self::normalizationPlanFromGeneratorData($routeData);
        if ($plan === null && $locale !== null) {
            $routeData = isset($gen[$routeName . '.' . $locale]) ? $gen[$routeName . '.' . $locale] : null;
            $plan = self::normalizationPlanFromGeneratorData($routeData);
        }

        if ($plan === null) {
            return null;
        }

        if (!$withNormalization) {
            return $plan['path'];
        }

        if (!$plan['ambiguous']) {
            $normalization = (string) RouteNormalizer::normalizeFromSymfony($plan['path'], $plan['matchedParams']);
        } else {
            $regex = self::replayRegexFromGeneratorData($routeData);
            $normalization = $regex === null ? '' : self::REPLAY_MARKER . $regex;
        }

        return $plan['path'] . "\0" . $normalization;
    }

    /**
     * Resolves the normalization part of a routeCacheEntry() entry for the
     * current request. It is either the normalized route, empty when there is
     * none, or, for routes with optional path variables, the route's matcher
     * regex to replay on the request path.
     *
     * Symfony merges the defaults of optional variables into _route_params, so
     * their URL participation is not observable after routing. The replay runs
     * the regex Symfony's compiled matcher uses for the route on the same path
     * and follows its participation rule.
     *
     * @return string|null
     */
    public static function normalizedRouteFromCacheEntry(string $entry, string $pathInfo)
    {
        $separator = strpos($entry, "\0");
        if ($separator === false || $separator === strlen($entry) - 1) {
            return null;
        }

        $path = substr($entry, 0, $separator);
        $normalization = substr($entry, $separator + 1);
        if ($normalization[0] !== self::REPLAY_MARKER) {
            return $normalization;
        }

        // Optional variables are a tail of the path, so the last one ends it.
        $lastVariable = substr($path, strrpos($path, '{') + 1, -1);
        $matchedParams = self::matchedParamsFromReplay(substr($normalization, 1), $lastVariable, $pathInfo);
        if ($matchedParams === null) {
            return null;
        }

        return RouteNormalizer::normalizeFromSymfony($path, $matchedParams);
    }

    /**
     * Replays a route's matcher regex the way CompiledUrlMatcherTrait::doMatch()
     * matches it. Returns the participating path variables, or null when the
     * regex does not match, or when Symfony may have matched another subject:
     * with a trailing slash that the last variable did not take, it matches the
     * trimmed path as well, and then redirects to it.
     *
     * @return array<string, bool>|null
     */
    public static function matchedParamsFromReplay(string $regex, string $lastVariable, string $pathInfo)
    {
        $pathInfo = rawurldecode($pathInfo) ?: '/';
        $trimmedPathInfo = rtrim($pathInfo, '/') ?: '/';
        if (!preg_match($regex, $pathInfo, $matches)) {
            return null;
        }

        if ($trimmedPathInfo !== $pathInfo) {
            $last = isset($matches[$lastVariable]) ? $matches[$lastVariable] : null;
            if (($last === null || ($last !== '' && substr($last, -1) !== '/'))
                && preg_match($regex, $trimmedPathInfo)) {
                return null;
            }
        }

        // Like doMatch(), a variable is present if it is set in the matches.
        // preg_match() leaves out trailing groups that did not participate,
        // and optional groups only precede groups nested inside them.
        $matchedParams = [];
        foreach ($matches as $name => $value) {
            if (is_string($name)) {
                $matchedParams[$name] = true;
            }
        }

        return $matchedParams;
    }

    /**
     * Turns a route regex into the pattern CompiledUrlMatcherDumper matches
     * for it, which also accepts or drops a trailing slash.
     */
    public static function matcherRegexFromRouteRegex(string $regex): string
    {
        $pos = strrpos($regex, '$');
        $hasTrailingSlash = '/' === $regex[$pos - 1];
        return substr_replace($regex, '/?$', $pos - $hasTrailingSlash, 1 + $hasTrailingSlash);
    }

    /**
     * @param mixed $routeData Symfony compiled generator data
     * @return array{path: string, matchedParams: array<string, bool>, ambiguous: bool}|null
     */
    public static function normalizationPlanFromGeneratorData($routeData)
    {
        if (!is_array($routeData)) {
            return null;
        }

        $defaults = isset($routeData[1]) && is_array($routeData[1]) ? $routeData[1] : [];
        $tokens = isset($routeData[3]) ? $routeData[3] : null;
        if (!is_array($tokens)) {
            return null;
        }

        $matchedParams = [];
        $optionalTail = true;
        foreach ($tokens as $token) {
            if (!is_array($token) || !isset($token[0])) {
                continue;
            }

            if ($token[0] !== 'variable') {
                $optionalTail = false;
                continue;
            }

            $name = isset($token[3]) ? (string) $token[3] : '';
            $important = !empty($token[5]);
            if ($optionalTail && !$important && array_key_exists($name, $defaults)) {
                return [
                    'path' => self::tokensToPathTemplate($tokens),
                    'matchedParams' => [],
                    'ambiguous' => true,
                ];
            }

            $optionalTail = false;
            $matchedParams[$name] = true;
        }

        return [
            'path' => self::tokensToPathTemplate($tokens),
            'matchedParams' => $matchedParams,
            'ambiguous' => false,
        ];
    }

    /**
     * Compiles the route described by generator data with Symfony's own route
     * compiler, and returns its matcher regex. Returns null if the route has
     * host variables, which take part in the matched subject, or if the
     * compiled tokens differ from the generator's, so that the regex would not
     * be the one Symfony matched.
     *
     * @return string|null
     */
    private static function replayRegexFromGeneratorData(array $routeData)
    {
        $defaults = isset($routeData[1]) && is_array($routeData[1]) ? $routeData[1] : [];
        $requirements = isset($routeData[2]) && is_array($routeData[2]) ? $routeData[2] : [];
        $tokens = $routeData[3];
        $hostTokens = isset($routeData[4]) && is_array($routeData[4]) ? $routeData[4] : [];
        foreach ($hostTokens as $token) {
            if (is_array($token) && isset($token[0]) && $token[0] === 'variable') {
                return null;
            }
        }

        $path = '';
        $utf8 = false;
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (!is_array($token) || !isset($token[0], $token[1])
                || ($token[0] !== 'text' && !isset($token[3]))) {
                return null;
            }
            if ($token[0] === 'text') {
                $path .= $token[1];
            } else {
                $path .= $token[1] . '{' . (!empty($token[5]) ? '!' : '') . $token[3] . '}';
                $utf8 = $utf8 || !empty($token[4]);
            }
        }

        try {
            $route = new Route($path, $defaults, $requirements, $utf8 ? ['utf8' => true] : []);
            $compiled = $route->compile();
        } catch (\Throwable $e) {
            return null;
        }

        if ($compiled->getTokens() !== $tokens) {
            return null;
        }

        return self::matcherRegexFromRouteRegex($compiled->getRegex());
    }

    /**
     * Symfony's routing cache files are recompiled in place, so opcache may
     * serve the previous version of one for up to revalidate_freq seconds.
     */
    private static function opcacheRevalidateWindow(): int
    {
        if (!extension_loaded('Zend OPcache')
            || !filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN)) {
            return 0;
        }

        return max(0, (int) ini_get('opcache.revalidate_freq'));
    }

    /**
     * Load Symfony's compiled URL generator array. Returns false when neither
     * candidate file exists.
     *
     * @return array|false
     */
    private static function loadGeneratorArray(string $cacheDir)
    {
        $genFile = self::findGeneratorFile($cacheDir);
        if ($genFile === null) {
            return false;
        }

        $gen = require $genFile;
        return is_array($gen) ? $gen : false;
    }

    /** @return string|null */
    private static function findGeneratorFile(string $cacheDir)
    {
        $base = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR;
        // Symfony 5+ uses url_generating_routes.php; 4.3–4.4 uses UrlGenerator.php
        $genFile = $base . 'url_generating_routes.php';
        if (!is_file($genFile)) {
            $genFile = $base . 'UrlGenerator.php';
            if (!is_file($genFile)) {
                return null;
            }
        }

        return $genFile;
    }


    private static function getCacheDir(ContainerInterface $container)
    {
        if ($container->hasParameter('kernel.cache_dir')) {
            $dir = $container->getParameter('kernel.cache_dir');
            if (is_string($dir) && $dir !== '') {
                return $dir;
            }
        }
        return null;
    }

    private static function fromRouterCache($cacheDir): array
    {
        $gen = self::loadGeneratorArray($cacheDir);
        if ($gen === false) {
            return [];
        }

        $methodsByRoute = [];
        $matchFile = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'url_matching_routes.php';
        if (is_file($matchFile)) {
            $matchData = require $matchFile;
            if (is_array($matchData)) {
                $methodsByRoute = self::extractMethodsByRoute($matchData);
            }
        }

        $out = [];

        foreach ($gen as $routeName => $routeData) {
            if (!is_string($routeName) || !is_array($routeData)) {
                continue;
            }

            // Generator cache format (Symfony): tokens are at index 3
            $tokens = isset($routeData[3]) ? $routeData[3] : null;
            if (!is_array($tokens)) {
                continue;
            }

            $path = self::tokensToPathTemplate($tokens);

            $methods = isset($methodsByRoute[$routeName]) ? $methodsByRoute[$routeName] : null;
            $method = self::pickMethod($methods);

            $out[] = [
                'path' => $path,
                'operationName' => 'http.request',
                'resourceName' => $method . ' ' . $path,
                'method' => $method,
            ];
        }

        return $out;
    }

    private static function fromRouteCollection(ContainerInterface $container): array
    {
        if (!$container->has(RouterInterface::class) && !$container->has('router')) {
            return [];
        }

        /** @var RouterInterface $router */
        $router = $container->has(RouterInterface::class)
            ? $container->get(RouterInterface::class)
            : $container->get('router');

        $collection = $router->getRouteCollection();
        $out = [];

        foreach ($collection->all() as $name => $route) {
            if (!$route instanceof Route) {
                continue;
            }

            $path = $route->getPath();
            $methods = $route->getMethods(); // array of strings, possibly empty

            $method = self::pickMethodFromMethodList($methods);

            $out[] = [
                'path' => $path,
                'operationName' => 'http.request',
                'resourceName' => $method . ' ' . $path,
                'method' => $method,
            ];
        }

        return $out;
    }

    private static function tokensToPathTemplate(array $tokens): string
    {
        $path = '';

        // Symfony stores generator tokens in reverse order; iterate accordingly
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (!is_array($token) || !isset($token[0])) {
                continue;
            }

            $type = $token[0];

            if ($type === 'text') {
                $path .= isset($token[1]) ? (string) $token[1] : '';
                continue;
            }

            if ($type === 'variable') {
                $sep = isset($token[1]) ? (string) $token[1] : '';
                $varName = isset($token[3]) ? (string) $token[3] : '';
                $path .= $sep . '{' . $varName . '}';
                continue;
            }
        }

        return $path;
    }

    private static function extractMethodsByRoute(array $matchData): array
    {
        $methodsByRoute = [];

        $staticRoutes = isset($matchData[1]) && is_array($matchData[1]) ? $matchData[1] : [];
        foreach ($staticRoutes as $path => $candidates) {
            if (!is_array($candidates)) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $defaults = isset($candidate[0]) ? $candidate[0] : null;
                if (!is_array($defaults) || !isset($defaults['_route'])) {
                    continue;
                }
                $routeName = $defaults['_route'];
                $methods = isset($candidate[2]) ? $candidate[2] : null;
                $methodsByRoute[$routeName] = $methods;
            }
        }

        $dynamicRoutes = isset($matchData[3]) && is_array($matchData[3]) ? $matchData[3] : [];
        foreach ($dynamicRoutes as $offset => $candidates) {
            if (!is_array($candidates)) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $defaults = isset($candidate[0]) ? $candidate[0] : null;
                if (!is_array($defaults) || !isset($defaults['_route'])) {
                    continue;
                }
                $routeName = $defaults['_route'];
                $methods = isset($candidate[2]) ? $candidate[2] : null;
                $methodsByRoute[$routeName] = $methods;
            }
        }

        return $methodsByRoute;
    }

    private static function pickMethod($methods): string
    {
        if ($methods === null || $methods === []) {
            return 'GET';
        }
        if (is_array($methods) && array_key_exists('GET', $methods)) {
            return 'GET';
        }
        if (is_array($methods) && array_key_exists('POST', $methods)) {
            return 'POST';
        }
        if (is_array($methods)) {
            $keys = array_keys($methods);
            return isset($keys[0]) ? (string) $keys[0] : 'GET';
        }
        return 'GET';
    }

    private static function pickMethodFromMethodList(array $methods): string
    {
        if (empty($methods)) {
            return 'GET';
        }
        $upper = [];
        foreach ($methods as $m) {
            $upper[] = strtoupper((string) $m);
        }
        if (in_array('GET', $upper, true)) {
            return 'GET';
        }
        if (in_array('POST', $upper, true)) {
            return 'POST';
        }
        return $upper[0] !== '' ? $upper[0] : 'GET';
    }
}
