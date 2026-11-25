<?php

namespace DDTrace\Tests\Unit\Integrations\Symfony;

use DDTrace\Integrations\Symfony\EndpointCatalog;
use DDTrace\Tests\Common\BaseTestCase;

class EndpointCatalogTest extends BaseTestCase
{
    public function testRequiredParametersProduceDeterministicPlan()
    {
        $plan = EndpointCatalog::normalizationPlanFromGeneratorData([
            ['id', 'format'],
            [],
            [],
            [
                ['variable', '.', '[^.]+', 'format'],
                ['variable', '/', '[^/]+', 'id'],
                ['text', '/articles'],
            ],
        ]);

        $this->assertSame('/articles/{id}.{format}', $plan['path']);
        $this->assertSame(['format' => true, 'id' => true], $plan['matchedParams']);
        $this->assertFalse($plan['ambiguous']);
    }

    public function testTrailingDefaultMakesParticipationAmbiguous()
    {
        $plan = EndpointCatalog::normalizationPlanFromGeneratorData([
            ['slug', 'format'],
            ['format' => 'html'],
            [],
            [
                ['variable', '.', '[^.]+', 'format'],
                ['variable', '/', '.+', 'slug'],
                ['text', '/articles'],
            ],
        ]);

        $this->assertSame('/articles/{slug}.{format}', $plan['path']);
        // Only the mandatory variables, which give the fallback route.
        $this->assertSame(['slug' => true], $plan['matchedParams']);
        $this->assertTrue($plan['ambiguous']);
    }

    public function testDefaultedParameterBeforeStaticSuffixIsRequired()
    {
        $plan = EndpointCatalog::normalizationPlanFromGeneratorData([
            ['locale'],
            ['locale' => 'en'],
            [],
            [
                ['text', '/index'],
                ['variable', '/', '[^/]+', 'locale'],
            ],
        ]);

        $this->assertSame('/{locale}/index', $plan['path']);
        $this->assertSame(['locale' => true], $plan['matchedParams']);
        $this->assertFalse($plan['ambiguous']);
    }

    public function testImportantDefaultedParameterIsRequired()
    {
        $plan = EndpointCatalog::normalizationPlanFromGeneratorData([
            ['page'],
            ['page' => 1],
            [],
            [
                ['variable', '/', '[^/]+', 'page', false, true],
                ['text', '/articles'],
            ],
        ]);

        $this->assertSame('/articles/{page}', $plan['path']);
        $this->assertSame(['page' => true], $plan['matchedParams']);
        $this->assertFalse($plan['ambiguous']);
    }

    public function testCacheKeyFollowsTheLoadedRouteData()
    {
        $old = [['slug'], [], [], [['variable', '/', '[^/]++', 'slug', true], ['text', '/old-blog']], [], []];
        $new = [['slug'], [], [], [['variable', '/', '[^/]++', 'slug', true], ['text', '/new-blog']], [], []];

        $this->assertSame(EndpointCatalog::cacheKeyForRouteData($old), EndpointCatalog::cacheKeyForRouteData($old));
        $this->assertNotSame(EndpointCatalog::cacheKeyForRouteData($old), EndpointCatalog::cacheKeyForRouteData($new));
    }

    public function testCacheKeyHashesTheSerializedRouteData()
    {
        $data = [['slug'], [], [], [['variable', '/', '[^/]++', 'slug', true], ['text', '/blog']], [], []];
        $serialized = serialize($data);
        $hash = PHP_VERSION_ID >= 80100 ? hash('xxh128', $serialized) : md5($serialized);

        $this->assertSame("symfony:v3\0" . $hash, EndpointCatalog::cacheKeyForRouteData($data));
    }

    public function testGeneratorFileKeyChangesWithTheModificationTime()
    {
        // Without opcache every require reads the file, so a generator renamed
        // into place with an older modification time (cache:clear's warmup
        // directory, rsync -a, cp -p, tar) is what the next require returns:
        // its key must differ from the previous file's.
        $cacheDir = $this->makeCacheDir();
        $genFile = $cacheDir . DIRECTORY_SEPARATOR . 'url_generating_routes.php';
        try {
            $container = $this->containerWithCacheDir($cacheDir);
            $this->writeGenerator($genFile, '/old-blog', time() - 120);
            $oldKey = EndpointCatalog::generatorFileCacheKey('blog', 'en', $container);
            $this->assertNotNull($oldKey);
            $this->assertSame($oldKey, EndpointCatalog::generatorFileCacheKey('blog', 'en', $container));
            $this->assertNotSame($oldKey, EndpointCatalog::generatorFileCacheKey('blog', null, $container));
            $this->assertNotSame($oldKey, EndpointCatalog::generatorFileCacheKey('other', 'en', $container));

            $this->writeGenerator($genFile . '.new', '/new-blog', time() - 3600);
            rename($genFile . '.new', $genFile);
            clearstatcache();
            $newKey = EndpointCatalog::generatorFileCacheKey('blog', 'en', $container);
            $this->assertNotNull($newKey);
            $this->assertNotSame($oldKey, $newKey);
        } finally {
            $this->removeCacheDir($cacheDir);
        }
    }

    public function testGeneratorFileKeyIsNullUntilTheModificationSecondHasPassed()
    {
        // A rewrite within the second of the modification time would keep it.
        $cacheDir = $this->makeCacheDir();
        $genFile = $cacheDir . DIRECTORY_SEPARATOR . 'url_generating_routes.php';
        try {
            $container = $this->containerWithCacheDir($cacheDir);
            $this->assertNull(EndpointCatalog::generatorFileCacheKey('blog', null, $container));

            $this->writeGenerator($genFile, '/blog', time() + 1);
            $this->assertNull(EndpointCatalog::generatorFileCacheKey('blog', null, $container));

            $this->writeGenerator($genFile, '/blog', time() - 1);
            $this->assertNotNull(EndpointCatalog::generatorFileCacheKey('blog', null, $container));
        } finally {
            $this->removeCacheDir($cacheDir);
        }
    }

    public function testOpcacheMayServeScriptsWhenItServesThem()
    {
        if (!extension_loaded('Zend OPcache')) {
            $this->assertFalse(EndpointCatalog::opcacheMayServeScripts());
        } elseif (self::opcacheServesScripts()) {
            $this->assertTrue(EndpointCatalog::opcacheMayServeScripts());
        } elseif (PHP_SAPI === 'cli' && !filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN)) {
            // Long-running CLI servers: opcache.enable_cli is off by default.
            $this->assertFalse(EndpointCatalog::opcacheMayServeScripts());
        } else {
            $this->markTestSkipped('opcache is loaded but does not serve scripts here');
        }
    }

    public function testRouteRebuiltInPlaceGetsANewKeyAndEntry()
    {
        // Whatever the generator file's modification time, a route that
        // changes gets a new key, so the cache never serves its old entry.
        $cacheDir = $this->makeCacheDir();
        $genFile = $cacheDir . DIRECTORY_SEPARATOR . 'url_generating_routes.php';
        try {
            $container = $this->containerWithCacheDir($cacheDir);
            $this->writeGenerator($genFile, '/old-blog', 1700000000);
            $before = EndpointCatalog::matchedRouteData('blog', null, $container);
            $this->assertSame('/old-blog/{slug}', EndpointCatalog::routeCacheEntryFromRouteData($before, false));

            // An older modification time, as rsync -a, cp -p or tar keep it.
            $this->writeGenerator($genFile, '/new-blog', 1600000000);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($genFile, true);
            }
            $after = EndpointCatalog::matchedRouteData('blog', null, $container);
            $this->assertSame('/new-blog/{slug}', EndpointCatalog::routeCacheEntryFromRouteData($after, false));
            $this->assertNotSame(
                EndpointCatalog::cacheKeyForRouteData($before),
                EndpointCatalog::cacheKeyForRouteData($after)
            );
        } finally {
            $this->removeCacheDir($cacheDir);
        }
    }

    public function testCacheKeyOfAGeneratorRenamedIntoPlaceMatchesWhatIsLoaded()
    {
        // cache:clear warms the new cache up in a sibling directory and renames
        // it into place, and rsync -a, cp -p or tar keep the file's own
        // modification time, so the new generator can be older than opcache's
        // revalidation window and opcache may keep serving the previous one.
        // The entry cached under the key must always be the route that was
        // loaded, which is the route Symfony routes with (http.route must not
        // change, RFC-1103).
        if (!self::opcacheServesScripts()) {
            $this->markTestSkipped('opcache does not serve scripts here (run with opcache.enable_cli=1)');
        }

        $requestStart = isset($_SERVER['REQUEST_TIME']) && is_numeric($_SERVER['REQUEST_TIME'])
            ? (int) $_SERVER['REQUEST_TIME']
            : time();
        $validateTimestamps = ini_set('opcache.validate_timestamps', '1');
        $revalidateFreq = ini_set('opcache.revalidate_freq', '2');
        $cacheDir = $this->makeCacheDir();
        $genFile = $cacheDir . DIRECTORY_SEPARATOR . 'url_generating_routes.php';
        try {
            $container = $this->containerWithCacheDir($cacheDir);
            $this->writeGenerator($genFile, '/old-blog', $requestStart - 120);
            $this->assertSame('/old-blog/{slug}', EndpointCatalog::routeCacheEntry('blog', null, $container, false));
            if (!opcache_is_script_cached($genFile)) {
                $this->markTestSkipped('opcache did not cache the generator file');
            }

            $this->writeGenerator($genFile . '.new', '/new-blog', $requestStart - 60);
            rename($genFile . '.new', $genFile);
            clearstatcache();

            $loaded = EndpointCatalog::matchedRouteData('blog', null, $container);
            $oldKey = EndpointCatalog::cacheKeyForRouteData(
                [['slug'], [], [], [['variable', '/', '[^/]++', 'slug', true], ['text', '/old-blog']], [], []]
            );
            $entry = EndpointCatalog::routeCacheEntryFromRouteData($loaded, false);
            if ($entry === '/old-blog/{slug}') {
                // opcache still serves the previous file: so does Symfony.
                $this->assertSame($oldKey, EndpointCatalog::cacheKeyForRouteData($loaded));
            } else {
                $this->assertSame('/new-blog/{slug}', $entry);
                $this->assertNotSame($oldKey, EndpointCatalog::cacheKeyForRouteData($loaded));
            }
        } finally {
            ini_set('opcache.validate_timestamps', $validateTimestamps);
            ini_set('opcache.revalidate_freq', $revalidateFreq);
            $this->removeCacheDir($cacheDir);
        }
    }

    public function testRouteDataUsesTheLocaleSuffixAndTheLegacyGeneratorFile()
    {
        $cacheDir = $this->makeCacheDir();
        try {
            $routes = [
                'blog.en' => [['slug'], [], [], [['variable', '/', '[^/]++', 'slug', true], ['text', '/en/blog']], [], []],
            ];
            file_put_contents(
                $cacheDir . DIRECTORY_SEPARATOR . 'UrlGenerator.php',
                '<?php return ' . var_export($routes, true) . ';'
            );
            $container = $this->containerWithCacheDir($cacheDir);

            $this->assertSame($routes['blog.en'], EndpointCatalog::matchedRouteData('blog', 'en', $container));
            $this->assertNull(EndpointCatalog::matchedRouteData('blog', null, $container));
        } finally {
            $this->removeCacheDir($cacheDir);
        }
    }

    public function testRouteDataIsNullWithoutGeneratorFile()
    {
        $cacheDir = $this->makeCacheDir();
        try {
            $this->assertNull(EndpointCatalog::matchedRouteData('blog', 'en', $this->containerWithCacheDir($cacheDir)));
        } finally {
            $this->removeCacheDir($cacheDir);
        }
    }

    public function testInvalidGeneratorDataHasNoPlan()
    {
        $this->assertNull(EndpointCatalog::normalizationPlanFromGeneratorData([]));
        $this->assertNull(EndpointCatalog::normalizationPlanFromGeneratorData([[], [], [], 'tokens']));
    }

    public function testMatcherRegexAcceptsTrailingSlashLikeSymfonyMatcher()
    {
        $this->assertSame(
            '{^/posts(?:/(?P<page>[^/]++))?/?$}sD',
            EndpointCatalog::matcherRegexFromRouteRegex('{^/posts(?:/(?P<page>[^/]++))?$}sD')
        );
        $this->assertSame(
            '{^/normalized/utf8(?:/(?P<föo>[^/]++))?/?$}sDu',
            EndpointCatalog::matcherRegexFromRouteRegex('{^/normalized/utf8(?:/(?P<föo>[^/]++))?$}sDu')
        );
    }

    public function testReplayReportsParticipatingOptionalParameters()
    {
        $regex = '{^/posts(?:/(?P<page>[^/]++))?/?$}sD';
        $this->assertSame([], EndpointCatalog::matchedParamsFromReplay($regex, 'page', '/posts'));
        $this->assertSame(['page' => true], EndpointCatalog::matchedParamsFromReplay($regex, 'page', '/posts/2'));
        $this->assertNull(EndpointCatalog::matchedParamsFromReplay($regex, 'page', '/other'));
    }

    public function testCacheEntryResolvesOptionalsByReplayAndFallsBackToMandatoryRoute()
    {
        $entry = "/posts/{page}\0"
            . EndpointCatalog::replayNormalization('/posts', [], '{^/posts(?:/(?P<page>[^/]++))?/?$}sD');
        $this->assertSame('/posts', EndpointCatalog::normalizedRouteFromCacheEntry($entry, '/posts'));
        $this->assertSame('/posts/{page}', EndpointCatalog::normalizedRouteFromCacheEntry($entry, '/posts/2'));
        // The replay cannot tell which optionals took part: fall back to the
        // route with the mandatory variables only, rather than no route.
        $this->assertSame('/posts', EndpointCatalog::normalizedRouteFromCacheEntry($entry, '/other'));
    }

    public function testReplayFollowsRouteRequirements()
    {
        $regex = '{^/normalized/ambiguous/(?P<slug>.+)(?:\.(?P<format>html|json))?/?$}sD';
        $this->assertSame(
            ['slug' => true],
            EndpointCatalog::matchedParamsFromReplay($regex, 'format', '/normalized/ambiguous/foo.bar')
        );
        // Symfony's greedy slug requirement also takes ".json".
        $this->assertSame(
            ['slug' => true],
            EndpointCatalog::matchedParamsFromReplay($regex, 'format', '/normalized/ambiguous/foo.json')
        );
    }

    public function testReplayCountsAnEmptyParticipatingCaptureAsPresent()
    {
        // Symfony binds rest = '' here, not its default.
        $regex = '{^/normalized/catchall(?:/(?P<rest>.*))?/?$}sD';
        $this->assertSame(
            ['rest' => true],
            EndpointCatalog::matchedParamsFromReplay($regex, 'rest', '/normalized/catchall/')
        );
        $this->assertSame([], EndpointCatalog::matchedParamsFromReplay($regex, 'rest', '/normalized/catchall'));
        $this->assertSame(
            ['rest' => true],
            EndpointCatalog::matchedParamsFromReplay($regex, 'rest', '/normalized/catchall/a/b/')
        );
    }

    public function testCacheEntryTreatsAnEmptyOptionalAsAbsent()
    {
        // RFC-1125: an optional part bound to an empty value is absent, even
        // though Symfony binds rest = '' rather than its default.
        $entry = "/normalized/catchall/{rest}\0" . EndpointCatalog::replayNormalization(
            '/normalized/catchall',
            [],
            '{^/normalized/catchall(?:/(?P<rest>.*))?/?$}sD'
        );
        $this->assertSame(
            '/normalized/catchall',
            EndpointCatalog::normalizedRouteFromCacheEntry($entry, '/normalized/catchall/')
        );
        $this->assertSame(
            '/normalized/catchall/{rest}',
            EndpointCatalog::normalizedRouteFromCacheEntry($entry, '/normalized/catchall/a/b')
        );
    }

    public function testCacheEntryKeepsAMandatoryVariableBoundToAnEmptyValue()
    {
        // A requirement that can match an empty string never makes a
        // variable optional.
        $entry = "/a/{x}/{page}\0" . EndpointCatalog::replayNormalization(
            '/a/{x}',
            ['x' => true],
            '{^/a/(?P<x>[^/]*)(?:/(?P<page>[^/]++))?/?$}sD'
        );
        $this->assertSame('/a/{x}', EndpointCatalog::normalizedRouteFromCacheEntry($entry, '/a/'));
        $this->assertSame('/a/{x}/{page}', EndpointCatalog::normalizedRouteFromCacheEntry($entry, '/a//2'));
    }

    public function testReplayDecodesThePathLikeSymfonyMatcher()
    {
        // Only the decoded path satisfies this requirement, so the match holds
        // only if the replay decodes the path as the matcher does before it.
        $regex = '{^/normalized/utf8(?:/(?P<city>café))?/?$}sDu';
        $this->assertSame(
            ['city' => true],
            EndpointCatalog::matchedParamsFromReplay($regex, 'city', '/normalized/utf8/caf%C3%A9')
        );
        $this->assertNull(
            EndpointCatalog::matchedParamsFromReplay($regex, 'city', '/normalized/utf8/cafe')
        );
    }

    public function testReplayReportsNonAsciiVariableNames()
    {
        // PCRE only accepts non-ASCII subpattern names from PHP 7.4 on, and
        // Symfony compiles the matcher with the same PCRE, so on older
        // versions such a route has no matcher to replay in the first place.
        if (@preg_match('{(?P<föo>x)}u', 'x') !== 1) {
            $this->markTestSkipped('PCRE here rejects non-ASCII subpattern names');
        }

        $regex = '{^/normalized/utf8(?:/(?P<föo>[^/]++))?/?$}sDu';
        $this->assertSame(
            ['föo' => true],
            EndpointCatalog::matchedParamsFromReplay($regex, 'föo', '/normalized/utf8/bar')
        );
    }

    public function testReplayOmitsTrailingSlashRedirects()
    {
        // Symfony matches these after redirecting to the path without the
        // trailing slash, so the requested path is not what it matched.
        $regex = '{^/posts(?:/(?P<page>[^/]++))?/?$}sD';
        $this->assertNull(EndpointCatalog::matchedParamsFromReplay($regex, 'page', '/posts/'));
        $this->assertNull(EndpointCatalog::matchedParamsFromReplay($regex, 'page', '/posts/2/'));
    }

    /**
     * @return bool whether opcache compiles and serves the scripts this process
     *              requires, rather than only being loaded
     */
    private static function opcacheServesScripts()
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }
        $status = @opcache_get_status(false);

        return is_array($status) && !empty($status['opcache_enabled']);
    }

    private function containerWithCacheDir($cacheDir)
    {
        $container = \Mockery::mock('Symfony\Component\DependencyInjection\ContainerInterface');
        $container->shouldReceive('hasParameter')->with('kernel.cache_dir')->andReturn(true);
        $container->shouldReceive('getParameter')->with('kernel.cache_dir')->andReturn($cacheDir);

        return $container;
    }

    /**
     * Writes a generator file in CompiledUrlGeneratorDumper's layout, with a
     * single route "blog": $prefix/{slug}.
     */
    private function writeGenerator($file, $prefix, $mtime)
    {
        $routes = [
            'blog' => [['slug'], [], [], [['variable', '/', '[^/]++', 'slug', true], ['text', $prefix]], [], []],
        ];
        file_put_contents($file, '<?php return ' . var_export($routes, true) . ';');
        touch($file, $mtime);
        clearstatcache();
    }

    private function makeCacheDir()
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('dd-symfony-cache-', true);
        mkdir($cacheDir);
        return $cacheDir;
    }

    private function removeCacheDir($cacheDir)
    {
        array_map('unlink', glob($cacheDir . DIRECTORY_SEPARATOR . '*'));
        rmdir($cacheDir);
    }
}
