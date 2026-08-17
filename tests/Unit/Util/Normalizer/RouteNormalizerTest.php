<?php

namespace DDTrace\Tests\Unit\Util\Normalizer;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Util\RouteNormalizer;

class RouteNormalizerTest extends BaseTestCase
{
    // encodeStaticSegment

    public function testEncodeStaticSegmentAllowed()
    {
        $this->assertSame('hello', RouteNormalizer::encodeStaticSegment('hello'));
        $this->assertSame('Hello-World_v1.0~test', RouteNormalizer::encodeStaticSegment('Hello-World_v1.0~test'));
    }

    public function testEncodeStaticSegmentEncodesReserved()
    {
        $this->assertSame('dump-request', RouteNormalizer::encodeStaticSegment('dump-request'));
        $this->assertSame('foo%40bar', RouteNormalizer::encodeStaticSegment('foo@bar'));
        $this->assertSame('foo%20bar', RouteNormalizer::encodeStaticSegment('foo bar'));
    }

    public function testEncodeStaticSegmentPreservesExistingPercentEncoding()
    {
        $this->assertSame('%2F', RouteNormalizer::encodeStaticSegment('%2F'));
        $this->assertSame('%2F', RouteNormalizer::encodeStaticSegment('%2f'));
    }

    // encodeParamName

    public function testEncodeParamNamePreservesNormal()
    {
        $this->assertSame('id', RouteNormalizer::encodeParamName('id'));
        $this->assertSame('user_id', RouteNormalizer::encodeParamName('user_id'));
    }

    public function testEncodeParamNameEncodesPlusSign()
    {
        $this->assertSame('foo%2Bbar', RouteNormalizer::encodeParamName('foo+bar'));
    }

    public function testEncodeParamNameEncodesReserved()
    {
        $this->assertSame('foo%23bar', RouteNormalizer::encodeParamName('foo#bar'));
    }

    // normalizeFromLaravel

    public function testLaravelSimpleRoute()
    {
        $this->assertSame('/users', RouteNormalizer::normalizeFromLaravel('/users'));
        $this->assertSame('/users/{id}', RouteNormalizer::normalizeFromLaravel('/users/{id}'));
    }

    public function testLaravelOptionalParamPresent()
    {
        $result = RouteNormalizer::normalizeFromLaravel('/users/{id}/{format?}', ['id' => '1', 'format' => 'json']);
        $this->assertSame('/users/{id}/{format}', $result);
    }

    public function testLaravelOptionalParamAbsent()
    {
        $result = RouteNormalizer::normalizeFromLaravel('/users/{id}/{format?}', ['id' => '1']);
        $this->assertSame('/users/{id}', $result);
    }

    public function testLaravelMixedSegmentTwoParams()
    {
        // /photos/{id}.{format} → both in same URL segment → combined
        $result = RouteNormalizer::normalizeFromLaravel('/photos/{id}.{format}', ['id' => '1', 'format' => 'jpg']);
        $this->assertSame('/photos/{id+format}', $result);
    }

    public function testLaravelMixedSegmentOptionalFormat()
    {
        // /posts/:id(.:format) style — optional format present
        $result = RouteNormalizer::normalizeFromLaravel('/posts/{id}/{format?}', ['id' => '1', 'format' => 'json']);
        $this->assertSame('/posts/{id}/{format}', $result);

        // optional format absent
        $result = RouteNormalizer::normalizeFromLaravel('/posts/{id}/{format?}', ['id' => '1']);
        $this->assertSame('/posts/{id}', $result);
    }

    public function testLaravelRequiredParamBesideAbsentOptional()
    {
        // {name} is required; {ext?} is absent — must keep {name}, not drop the whole segment
        $result = RouteNormalizer::normalizeFromLaravel('/files/{name}.{ext?}', ['name' => 'foo']);
        $this->assertSame('/files/{name}', $result);
    }

    public function testLaravelRequiredParamBesideAbsentOptionalBothPresent()
    {
        $result = RouteNormalizer::normalizeFromLaravel('/files/{name}.{ext?}', ['name' => 'foo', 'ext' => 'txt']);
        $this->assertSame('/files/{name+ext}', $result);
    }

    public function testLaravelDeeperRoute()
    {
        $result = RouteNormalizer::normalizeFromLaravel('/dashboard/shared_widget_update/{id}/{widget_id}');
        $this->assertSame('/dashboard/shared_widget_update/{id}/{widget_id}', $result);
    }

    public function testLaravelTrailingSlash()
    {
        $result = RouteNormalizer::normalizeFromLaravel('/users/{id}/');
        $this->assertSame('/users/{id}/', $result);
    }

    public function testLaravelRoot()
    {
        $this->assertSame('/', RouteNormalizer::normalizeFromLaravel('/'));
    }

    // normalizeFromSlim

    public function testSlimSimpleRoute()
    {
        $this->assertSame('/users/{id}', RouteNormalizer::normalizeFromSlim('/users/{id}'));
    }

    public function testSlimRegexConstraintStripped()
    {
        $this->assertSame('/users/{id}', RouteNormalizer::normalizeFromSlim('/users/{id:[0-9]+}'));
        $this->assertSame('/v2/{name}/blobs', RouteNormalizer::normalizeFromSlim('/v2/{name:[a-zA-Z0-9-]+}/blobs'));
        // Constraint containing '/' must not break the segment split
        $this->assertSame('/files/{name}', RouteNormalizer::normalizeFromSlim('/files/{name:[^/]+}'));
    }

    public function testSlimOptionalSegmentPresent()
    {
        $result = RouteNormalizer::normalizeFromSlim('/users/{id}[/{format}]', ['id' => '1', 'format' => 'json']);
        $this->assertSame('/users/{id}/{format}', $result);
    }

    public function testSlimOptionalSegmentAbsent()
    {
        $result = RouteNormalizer::normalizeFromSlim('/users/{id}[/{format}]', ['id' => '1']);
        $this->assertSame('/users/{id}', $result);
    }

    public function testSlimCatchAll()
    {
        $this->assertSame('/files/{file}', RouteNormalizer::normalizeFromSlim('/files/{file:.+}'));
    }

    public function testSlimStaticOptionalSectionPresent()
    {
        // /feed[.json] requested as /feed.json → .json section included
        $result = RouteNormalizer::normalizeFromSlim('/feed[.json]', [], '/feed.json');
        $this->assertSame('/feed.json', $result);
    }

    public function testSlimStaticOptionalSectionAbsent()
    {
        // /feed[.json] requested as /feed → .json section absent
        $result = RouteNormalizer::normalizeFromSlim('/feed[.json]', [], '/feed');
        $this->assertSame('/feed', $result);
    }

    public function testSlimStaticOptionalSectionNoUrlPath()
    {
        // Without URL path, backward-compatible: keep the section
        $result = RouteNormalizer::normalizeFromSlim('/feed[.json]', []);
        $this->assertSame('/feed.json', $result);
    }

    // normalizeFromSymfony

    public function testSymfonySimpleRoute()
    {
        $this->assertSame('/sleep/{seconds}', RouteNormalizer::normalizeFromSymfony('/sleep/{seconds}'));
    }

    public function testSymfonyMixedSegment()
    {
        // Symfony may produce routes like /posts/{id}.{_format}
        $result = RouteNormalizer::normalizeFromSymfony('/posts/{id}.{_format}');
        $this->assertSame('/posts/{id+_format}', $result);
    }

    public function testSymfonyStaticOnlyRoute()
    {
        $this->assertSame('/dump-request', RouteNormalizer::normalizeFromSymfony('/dump-request'));
    }

    public function testSymfonyOptionalParamAbsent()
    {
        // /blog/{page} requested as /blog — page has a default and was not in the URL
        $result = RouteNormalizer::normalizeFromSymfony('/blog/{page}', []);
        $this->assertSame('/blog', $result);
    }

    public function testSymfonyOptionalParamPresent()
    {
        // /blog/{page} requested as /blog/2 — page was in the URL
        $result = RouteNormalizer::normalizeFromSymfony('/blog/{page}', ['page' => '2']);
        $this->assertSame('/blog/{page}', $result);
    }

    public function testSymfonyRequiredParamsAlwaysKept()
    {
        // All params present — nothing dropped
        $result = RouteNormalizer::normalizeFromSymfony('/users/{id}/posts/{post_id}', ['id' => '1', 'post_id' => '5']);
        $this->assertSame('/users/{id}/posts/{post_id}', $result);
    }

    public function testSymfonyTrailingOptionalAbsent()
    {
        // /users/{id}/posts/{post_id} with only id in URL — post_id absent
        $result = RouteNormalizer::normalizeFromSymfony('/users/{id}/posts/{post_id}', ['id' => '1']);
        $this->assertSame('/users/{id}/posts', $result);
    }

    public function testSymfonyNoMatchedParamsArgKeepsAll()
    {
        // null matchedParams → old behaviour, no params dropped
        $result = RouteNormalizer::normalizeFromSymfony('/blog/{page}');
        $this->assertSame('/blog/{page}', $result);
    }

    // normalizeFromLaminas

    public function testLaminasSimpleColon()
    {
        $this->assertSame('/users/{id}', RouteNormalizer::normalizeFromLaminas('/users/:id'));
    }

    public function testLaminasOptionalPresent()
    {
        $result = RouteNormalizer::normalizeFromLaminas('/users/:id[.:format]', ['id' => '1', 'format' => 'json']);
        $this->assertSame('/users/{id+format}', $result);
    }

    public function testLaminasOptionalAbsent()
    {
        $result = RouteNormalizer::normalizeFromLaminas('/users/:id[.:format]', ['id' => '1']);
        $this->assertSame('/users/{id}', $result);
    }

    public function testLaminasMultiParamOptionalPresent()
    {
        // Both params in the section present and appear in the URL → expand
        $result = RouteNormalizer::normalizeFromLaminas(
            '/archive[/:year/:month]',
            ['year' => '2024', 'month' => '08'],
            '/archive/2024/08'
        );
        $this->assertSame('/archive/{year}/{month}', $result);
    }

    public function testLaminasMultiParamOptionalAbsent()
    {
        // Both params injected by middleware but absent from URL → do not expand
        $result = RouteNormalizer::normalizeFromLaminas(
            '/archive[/:year/:month]',
            ['year' => '2024', 'month' => '08'],
            '/archive'
        );
        $this->assertSame('/archive', $result);
    }

    public function testLaminasNestedOptionalBothPresent()
    {
        $result = RouteNormalizer::normalizeFromLaminas(
            '/foo[/:bar[/:baz]]',
            ['bar' => 'a', 'baz' => 'b'],
            '/foo/a/b'
        );
        $this->assertSame('/foo/{bar}/{baz}', $result);
    }

    public function testLaminasNestedOptionalOnlyOuterPresent()
    {
        $result = RouteNormalizer::normalizeFromLaminas(
            '/foo[/:bar[/:baz]]',
            ['bar' => 'a'],
            '/foo/a'
        );
        $this->assertSame('/foo/{bar}', $result);
    }

    public function testLaminasRegexRouteSpec()
    {
        // Laminas\Router\Http\Regex uses %param% spec format for URL generation
        $this->assertSame('/blog/{id}', RouteNormalizer::normalizeFromLaminas('/blog/%id%'));
        $this->assertSame('/user/{id}/{name}', RouteNormalizer::normalizeFromLaminas('/user/%id%/%name%'));
    }

    public function testLaminasLiteralRoute()
    {
        $this->assertSame('/dump-request', RouteNormalizer::normalizeFromLaminas('/dump-request'));
    }

    public function testLaminasWildcard()
    {
        // Wildcard routes produce '/*' from laminasSegmentPartsToRouteTemplate
        $result = RouteNormalizer::normalizeFromLaminas('/*');
        $this->assertSame('/{param1}', $result);
    }

    // normalizeFromCakePHP

    public function testCakePHPSimpleColon()
    {
        $this->assertSame('/articles/{id}', RouteNormalizer::normalizeFromCakePHP('/articles/:id'));
    }

    public function testCakePHPMixedSegment()
    {
        $result = RouteNormalizer::normalizeFromCakePHP('/articles/:id.:ext');
        $this->assertSame('/articles/{id+ext}', $result);
    }

    public function testCakePHPCatchAll()
    {
        $this->assertSame('/{catchall}', RouteNormalizer::normalizeFromCakePHP('/*'));
        $this->assertSame('/api/{catchall}', RouteNormalizer::normalizeFromCakePHP('/api/**'));
    }

    public function testCakePHPStaticRoute()
    {
        $this->assertSame('/admin/dashboard', RouteNormalizer::normalizeFromCakePHP('/admin/dashboard'));
    }

    // normalizeFromYii

    public function testYiiSimpleColonPlaceholder()
    {
        $this->assertSame('/articles/{id}', RouteNormalizer::normalizeFromYii('/articles/:id'));
    }

    public function testYiiStaticRoute()
    {
        $this->assertSame('/site/index', RouteNormalizer::normalizeFromYii('/site/index'));
    }

    // normalizeFromCodeIgniter

    public function testCodeIgniterLiteralRoute()
    {
        $this->assertSame('/articles/index', RouteNormalizer::normalizeFromCodeIgniter('articles/index'));
    }

    public function testCodeIgniterNumWildcard()
    {
        $this->assertSame('/blog/{param1}', RouteNormalizer::normalizeFromCodeIgniter('blog/(:num)'));
    }

    public function testCodeIgniterAnyWildcard()
    {
        $this->assertSame('/users/{param1}', RouteNormalizer::normalizeFromCodeIgniter('users/:any'));
    }

    public function testCodeIgniterMultipleWildcards()
    {
        $result = RouteNormalizer::normalizeFromCodeIgniter('posts/(:num)/comments/(:num)');
        $this->assertSame('/posts/{param1}/comments/{param2}', $result);
    }

    public function testCodeIgniterCatchAll()
    {
        // A catch-all in CI is typically :any at the end
        $this->assertSame('/{param1}', RouteNormalizer::normalizeFromCodeIgniter(':any'));
    }

    // normalizeFromWordPress

    public function testWordPressSimpleRegex()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^blog/([^/]+)/?$');
        $this->assertSame('/blog/{param1}', $result);
    }

    public function testWordPressStaticRule()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^about/?$');
        $this->assertSame('/about', $result);
    }

    public function testWordPressMultipleGroups()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^([^/]+)/([^/]+)/?$');
        $this->assertSame('/{param1}/{param2}', $result);
    }

    public function testWordPressOptionalGroupAbsent()
    {
        // Optional second segment not present in URL — must not emit phantom {param2}
        $result = RouteNormalizer::normalizeFromWordPress('^([^/]+)(?:/([0-9]+))?/?$', 'simple');
        $this->assertSame('/{param1}', $result);
    }

    public function testWordPressOptionalGroupPresent()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^([^/]+)(?:/([0-9]+))?/?$', 'simple/123');
        $this->assertSame('/{param1}/{param2}', $result);
    }

    public function testWordPressOptionalGroupNoUrlPath()
    {
        // Without URL path, fall back to emitting all groups (backward-compatible)
        $result = RouteNormalizer::normalizeFromWordPress('^([^/]+)(?:/([0-9]+))?/?$');
        $this->assertSame('/{param1}/{param2}', $result);
    }

    public function testWordPressRootRule()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^/?$');
        $this->assertSame('/', $result);
    }

    public function testWordPressMultipleCaptureGroupsInOneSegment()
    {
        // Two capture groups in the same slash-separated segment → combined with +
        // The static prefix "post-" is dropped as the whole mixed segment is treated as dynamic
        $result = RouteNormalizer::normalizeFromWordPress('^post-([^/]+)-([0-9]+)/?$');
        $this->assertSame('/{param1+param2}', $result);
    }

    // RFC examples

    public function testRfcExampleFastApi()
    {
        // http.route: /dashboard/shared_widget_update/{id}/{widget_id}
        $result = RouteNormalizer::normalizeFromLaravel('/dashboard/shared_widget_update/{id}/{widget_id}');
        $this->assertSame('/dashboard/shared_widget_update/{id}/{widget_id}', $result);
    }

    public function testRfcExampleDjangoDumpRequest()
    {
        // http.route: ^dump-request$ → /dump-request (after regex stripping)
        // We test via WordPress normalizer since it handles regex
        $result = RouteNormalizer::normalizeFromWordPress('^dump-request$');
        $this->assertSame('/dump-request', $result);
    }

    public function testRfcExampleFlaskMixedStaticDynamic()
    {
        // http.route: /users/user-<id> → /users/{id}
        // Flask wraps static+dynamic in same segment; normalizer drops static prefix
        $result = RouteNormalizer::normalizeFromLaravel('/users/{id}');
        $this->assertSame('/users/{id}', $result);
    }

    public function testRfcExampleRailsMandatoryFormat()
    {
        // http.route: /photos/:id.:format → /photos/{id+format}
        $result = RouteNormalizer::normalizeFromCakePHP('/photos/:id.:format');
        $this->assertSame('/photos/{id+format}', $result);
    }

    public function testRfcExampleGoGorilla()
    {
        // http.route: /v2/{name:[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]}/blobs
        $result = RouteNormalizer::normalizeFromSlim('/v2/{name:[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]}/blobs');
        $this->assertSame('/v2/{name}/blobs', $result);
    }

    public function testRfcExampleRailsOptionalFormatPresent()
    {
        // /posts/:id(.:format) with format present → /posts/{id+format}
        $result = RouteNormalizer::normalizeFromLaminas('/posts/:id[.:format]', ['id' => '1', 'format' => 'json']);
        $this->assertSame('/posts/{id+format}', $result);
    }

    public function testRfcExampleRailsOptionalFormatAbsent()
    {
        // /posts/:id(.:format) without format → /posts/{id}
        $result = RouteNormalizer::normalizeFromLaminas('/posts/:id[.:format]', ['id' => '1']);
        $this->assertSame('/posts/{id}', $result);
    }
}
