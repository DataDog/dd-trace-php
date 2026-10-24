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
        $this->assertSame([], $plan['matchedParams']);
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

    public function testCacheKeyChangesWhenGeneratorFileIsRebuilt()
    {
        $cacheDir = $this->makeCacheDir();
        $genFile = $cacheDir . DIRECTORY_SEPARATOR . 'url_generating_routes.php';
        file_put_contents($genFile, '<?php return [];');

        touch($genFile, 1700000000);
        clearstatcache();
        $before = EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', 'en');
        $this->assertSame(
            $before,
            EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', 'en')
        );

        touch($genFile, 1700000001);
        clearstatcache();
        $after = EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', 'en');

        $this->assertNotNull($before);
        $this->assertNotSame($before, $after);
        $this->removeCacheDir($cacheDir);
    }

    public function testCacheKeyUsesLegacyGeneratorFile()
    {
        $cacheDir = $this->makeCacheDir();
        $genFile = $cacheDir . DIRECTORY_SEPARATOR . 'UrlGenerator.php';
        file_put_contents($genFile, '<?php return [];');

        touch($genFile, 1700000000);
        clearstatcache();
        $before = EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', null);
        touch($genFile, 1700000001);
        clearstatcache();
        $after = EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', null);

        $this->assertNotNull($before);
        $this->assertNotSame($before, $after);
        $this->removeCacheDir($cacheDir);
    }

    public function testCacheKeyIsNullWhileGeneratorFileMayBeServedStale()
    {
        // A generator rewritten within the current second, or within opcache's
        // revalidation window, may still be served in its previous version by
        // require. Caching it under the new modification time would pin that
        // version for the life of the process.
        //
        // The window is measured from the request start time, which is the
        // clock opcache compares against, so the modification times below are
        // anchored to it rather than to time(). Anchoring to time() would make
        // the second assertion depend on how long the suite has been running.
        $requestStart = isset($_SERVER['REQUEST_TIME']) && is_numeric($_SERVER['REQUEST_TIME'])
            ? (int) $_SERVER['REQUEST_TIME']
            : time();

        $cacheDir = $this->makeCacheDir();
        $genFile = $cacheDir . DIRECTORY_SEPARATOR . 'url_generating_routes.php';
        file_put_contents($genFile, '<?php return [];');

        touch($genFile, $requestStart);
        clearstatcache();
        $this->assertNull(EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', 'en'));

        touch($genFile, $requestStart - 60);
        clearstatcache();
        $this->assertNotNull(EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', 'en'));

        // A generator whose modification time is after the request started can
        // never be cached, however long the request runs.
        touch($genFile, $requestStart + 5);
        clearstatcache();
        $this->assertNull(EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', 'en'));

        $this->removeCacheDir($cacheDir);
    }

    public function testCacheKeyIsNullWithoutGeneratorFile()
    {
        $cacheDir = $this->makeCacheDir();
        $this->assertNull(EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', 'en'));
        $this->removeCacheDir($cacheDir);
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
