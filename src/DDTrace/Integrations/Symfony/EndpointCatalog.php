<?php

namespace DDTrace\Integrations\Symfony;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;


class EndpointCatalog
{
    /** @var array<string, string|null> keyed by "$cacheDir\0$routeName" */
    private static $pathCache = [];

    /** @var array<string, array|false> keyed by $cacheDir; false means "no cache file" */
    private static $generatorArrayByDir = [];

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
     * Looks up the path template for a given route name from the compiled
     * url_generating_routes.php file. Returns null if the file is missing
     * or the route is not found.
     *
     * This never falls back to getRouteCollection().
     *
     * The route array is loaded once per cache dir and the per-route result is
     * memoized, so repeated requests for the same route skip the filesystem
     * probe, the require of the compiled routes file, and the token-to-template
     * reconstruction.
     */
    public static function pathForRoute($routeName, ContainerInterface $container)
    {
        $cacheDir = self::getCacheDir($container);
        if ($cacheDir === null) {
            return null;
        }

        $cacheKey = $cacheDir . "\0" . $routeName;
        if (array_key_exists($cacheKey, self::$pathCache)) {
            return self::$pathCache[$cacheKey];
        }

        $gen = self::loadGeneratorArray($cacheDir);
        if ($gen === false || !isset($gen[$routeName])) {
            return self::$pathCache[$cacheKey] = null;
        }

        $routeData = $gen[$routeName];
        $tokens = isset($routeData[3]) ? $routeData[3] : null;
        if (!is_array($tokens)) {
            return self::$pathCache[$cacheKey] = null;
        }

        return self::$pathCache[$cacheKey] = self::tokensToPathTemplate($tokens);
    }

    /**
     * Load Symfony's compiled URL generator array once per cache dir. Returns
     * false when neither candidate file exists.
     *
     * @return array|false
     */
    private static function loadGeneratorArray(string $cacheDir)
    {
        if (array_key_exists($cacheDir, self::$generatorArrayByDir)) {
            return self::$generatorArrayByDir[$cacheDir];
        }

        $base = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR;
        // Symfony 5+ uses url_generating_routes.php; 4.3–4.4 uses UrlGenerator.php
        $genFile = $base . 'url_generating_routes.php';
        if (!is_file($genFile)) {
            $genFile = $base . 'UrlGenerator.php';
            if (!is_file($genFile)) {
                return self::$generatorArrayByDir[$cacheDir] = false;
            }
        }

        $gen = require $genFile;
        return self::$generatorArrayByDir[$cacheDir] = is_array($gen) ? $gen : false;
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
