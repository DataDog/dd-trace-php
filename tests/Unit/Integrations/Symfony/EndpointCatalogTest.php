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
        $cacheDir = $this->makeCacheDir();
        $genFile = $cacheDir . DIRECTORY_SEPARATOR . 'url_generating_routes.php';
        file_put_contents($genFile, '<?php return [];');

        touch($genFile, time());
        clearstatcache();
        $this->assertNull(EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', 'en'));

        touch($genFile, time() - 60);
        clearstatcache();
        $this->assertNotNull(EndpointCatalog::normalizationCacheKeyForCacheDir($cacheDir, 'blog', 'en'));
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
        $regex = '{^/normalized/utf8(?:/(?P<föo>[^/]++))?/?$}sDu';
        $this->assertSame(
            ['föo' => true],
            EndpointCatalog::matchedParamsFromReplay($regex, 'föo', '/normalized/utf8/caf%C3%A9')
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
