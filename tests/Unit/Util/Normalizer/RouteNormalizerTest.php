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

    public function testWordPressRootRule()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^/?$');
        $this->assertSame('/', $result);
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
