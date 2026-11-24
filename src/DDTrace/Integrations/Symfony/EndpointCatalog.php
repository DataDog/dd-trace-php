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


    /**
     * The compiled generator data of a matched route, as the generator cache
     * file that Symfony itself loads holds it: looked up by name, then with
     * the locale suffix of Symfony's i18n routes. Null when the container has
     * no cache dir, the generator file is missing, or it has neither name.
     *
     * The file is required on every request, as Symfony's own generator does,
     * so the data is what Symfony routes with even while opcache still serves
     * a previous version of a rebuilt file.
     *
     * @return array|null
     */
    public static function matchedRouteData($routeName, $locale, ContainerInterface $container)
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
        if (!self::isGeneratorRouteData($routeData) && $locale !== null) {
            $routeData = isset($gen[$routeName . '.' . $locale]) ? $gen[$routeName . '.' . $locale] : null;
        }

        return self::isGeneratorRouteData($routeData) ? $routeData : null;
    }

    /**
     * The routing cache key of a route's cache entry. The routing cache
     * outlives the request, so the key is derived from the generator data the
     * entry is built from: a route table rebuilt in place, without a worker
     * restart, gets new keys as soon as the data loaded changes, and an entry
     * can never hold another version of the route than the one loaded
     * (http.route must not change, RFC-1103).
     *
     * Null when the data cannot be serialized (a default holding such an
     * object): the entry is then built on every request.
     *
     * @return string|null
     */
    public static function cacheKeyForRouteData(array $routeData)
    {
        try {
            return "symfony:v3\0" . md5(serialize($routeData));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Whether $routeData has the shape of a compiled generator route. */
    private static function isGeneratorRouteData($routeData): bool
    {
        return is_array($routeData) && isset($routeData[3]) && is_array($routeData[3]);
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
        $routeData = self::matchedRouteData($routeName, $locale, $container);

        return $routeData === null ? null : self::routeCacheEntryFromRouteData($routeData, $withNormalization);
    }

    /**
     * routeCacheEntry() for route data matchedRouteData() returned.
     *
     * @return string|null
     */
    public static function routeCacheEntryFromRouteData(array $routeData, bool $withNormalization)
    {
        $plan = self::normalizationPlanFromGeneratorData($routeData);
        if ($plan === null) {
            return null;
        }

        if (!$withNormalization) {
            return $plan['path'];
        }

        // With optional variables, matchedParams holds the mandatory ones only,
        // so this is the exact route when there are none and the fallback
        // otherwise.
        $normalization = (string) RouteNormalizer::normalizeFromSymfony($plan['path'], $plan['matchedParams']);
        if ($plan['ambiguous']) {
            $regex = self::replayRegexFromGeneratorData($routeData);
            if ($regex !== null) {
                $normalization = self::replayNormalization($normalization, $plan['matchedParams'], $regex);
            }
        }

        return $plan['path'] . "\0" . $normalization;
    }

    /**
     * The normalization part of a cache entry for a route with optional
     * variables: the fallback route, the names of the mandatory variables
     * (Symfony variable names are word characters, so ',' separates them) and
     * the route's matcher regex.
     */
    public static function replayNormalization(string $fallback, array $mandatoryParams, string $regex): string
    {
        return self::REPLAY_MARKER . $fallback . "\0" . implode(',', array_keys($mandatoryParams)) . "\0" . $regex;
    }

    /**
     * Resolves the normalization part of a routeCacheEntry() entry for the
     * current request. It is either the normalized route, empty when there is
     * none, or, for routes with optional path variables, the fallback route
     * (mandatory variables only), the mandatory variables' names and the
     * route's matcher regex to replay on the request path (see
     * replayNormalization()). An optional variable the replay bound to an
     * empty value is absent (RFC-1125).
     *
     * Symfony merges the defaults of optional variables into _route_params, so
     * their URL participation is not observable after routing. The replay runs
     * the regex Symfony's compiled matcher uses for the route on the same path
     * and follows its participation rule. When the replay cannot tell which
     * optional variables took part, the fallback route is returned: a route
     * without the optional variables is better than no route.
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

        $parts = explode("\0", substr($normalization, 1), 3);
        if (count($parts) !== 3) {
            return null;
        }
        list($fallback, $mandatory, $regex) = $parts;

        // Optional variables are a tail of the path, so the last one ends it.
        $lastVariable = substr($path, strrpos($path, '{') + 1, -1);
        $values = self::replayValues($regex, $lastVariable, $pathInfo);
        if ($values === null) {
            return $fallback === '' ? null : $fallback;
        }

        // RFC-1125: an optional variable bound to an empty value is absent.
        // A mandatory one is in the route whatever its value.
        $matchedParams = $mandatory === '' ? [] : array_fill_keys(explode(',', $mandatory), true);
        foreach ($values as $name => $value) {
            if ($value !== '') {
                $matchedParams[$name] = true;
            }
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
        $values = self::replayValues($regex, $lastVariable, $pathInfo);

        return $values === null ? null : array_fill_keys(array_keys($values), true);
    }

    /**
     * matchedParamsFromReplay() with the value each participating variable
     * was bound to.
     *
     * @return array<string, string>|null
     */
    private static function replayValues(string $regex, string $lastVariable, string $pathInfo)
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
        $values = [];
        foreach ($matches as $name => $value) {
            if (is_string($name)) {
                $values[$name] = (string) $value;
            }
        }

        return $values;
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
     * When the route has optional variables, ambiguous is true and
     * matchedParams holds the mandatory variables only, which gives the
     * fallback route.
     *
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

        // Tokens run from the end of the path, so the optional variables come first.
        $matchedParams = [];
        $optionalTail = true;
        $ambiguous = false;
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
                $ambiguous = true;
                continue;
            }

            $optionalTail = false;
            $matchedParams[$name] = true;
        }

        return [
            'path' => self::tokensToPathTemplate($tokens),
            'matchedParams' => $matchedParams,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Compiles the route described by generator data with Symfony's own route
     * compiler, and returns its matcher regex. Returns null if the route has
     * host variables, which take part in the matched subject, or if the
     * compiled tokens differ from the generator's, so that the regex would not
     * be the one Symfony matched.
     *
     * Public so the unit tests can assert it returns the very regex Symfony
     * compiled for the route, rather than one of our own making.
     *
     * @internal
     *
     * @return string|null
     */
    public static function replayRegexFromGeneratorData(array $routeData)
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
