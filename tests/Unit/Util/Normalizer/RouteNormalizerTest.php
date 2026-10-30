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

    // normalizeFromSymfony — caller must always pass the set of params that
    // participated in the URL match; inferring from defaults would be spec-violating.

    public function testSymfonySimpleRouteAllParamsPresent()
    {
        $this->assertSame(
            '/sleep/{seconds}',
            RouteNormalizer::normalizeFromSymfony('/sleep/{seconds}', ['seconds' => '5'])
        );
    }

    public function testSymfonyMixedSegment()
    {
        // Symfony may produce routes like /posts/{id}.{_format}
        $result = RouteNormalizer::normalizeFromSymfony(
            '/posts/{id}.{_format}',
            ['id' => '1', '_format' => 'json']
        );
        $this->assertSame('/posts/{id+_format}', $result);
    }

    public function testSymfonyStaticOnlyRoute()
    {
        $this->assertSame('/dump-request', RouteNormalizer::normalizeFromSymfony('/dump-request', []));
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

    // Absent optionals: Symfony's RouteCompiler, which Laravel also uses, puts
    // exactly one preceding separator character into the optional.

    public function testAbsentSymfonyOptionalDropsOnlyItsSeparator()
    {
        $this->assertSame('/list', RouteNormalizer::normalizeFromSymfony('/list,{page}', []));
        $this->assertSame('/list', RouteNormalizer::normalizeFromSymfony('/list;{page}', []));
        $this->assertSame('/list-', RouteNormalizer::normalizeFromSymfony('/list-.{page}', []));
        $this->assertSame('/search', RouteNormalizer::normalizeFromSymfony('/search.{_format}', []));
        $this->assertSame('/~foo', RouteNormalizer::normalizeFromSymfony('/~foo.{ext}', []));
    }

    public function testAbsentLaravelOptionalDropsOnlyItsSeparator()
    {
        $this->assertSame('/list', RouteNormalizer::normalizeFromLaravel('list,{page?}', []));
        $this->assertSame('/list-', RouteNormalizer::normalizeFromLaravel('list-.{page?}', []));
    }

    // normalizeElements

    public function testNormalizeElementsKeepsStaticTextThatLooksLikeRouteSyntax()
    {
        $this->assertSame('/user%3Ame', RouteNormalizer::normalizeElements([['static', '/user:me']]));
        $this->assertSame('/caf%C3%A9', RouteNormalizer::normalizeElements([['static', '/caf%C3%A9']]));
        $this->assertSame('/a%7Bb%7D', RouteNormalizer::normalizeElements([['static', '/a{b}']]));
    }

    public function testNormalizeElementsCombinesParametersOfOneSegment()
    {
        $this->assertSame('/v/{version+rest}/{id}', RouteNormalizer::normalizeElements([
            ['static', '/v/v'], ['param', 'version'], ['param', 'rest'], ['static', '/'], ['param', 'id'],
        ]));
        $this->assertSame('/users/{id}', RouteNormalizer::normalizeElements([
            ['static', '/users/user-'], ['param', 'id'],
        ]));
    }

    public function testNormalizeElementsNamesCatchAllWithoutCollision()
    {
        $this->assertSame('/w/{param1}/{param2}', RouteNormalizer::normalizeElements([
            ['static', '/w/'], ['param', 'param1'], ['static', '/'], ['tail'],
        ]));
        $this->assertSame('/{param1}', RouteNormalizer::normalizeElements([['static', '/'], ['tail']]));
    }

    public function testNormalizeElementsSlashes()
    {
        $this->assertSame('/', RouteNormalizer::normalizeElements([['static', '/']]));
        $this->assertSame('/a/', RouteNormalizer::normalizeElements([['static', '/a/']]));
        $this->assertSame('/a/b', RouteNormalizer::normalizeElements([['static', '/a//b']]));
        $this->assertSame('/a%2Bb/{x%2By}', RouteNormalizer::normalizeElements([
            ['static', '/a+b/'], ['param', 'x+y'],
        ]));
    }

    // normalizeFromWordPress — the URL path is required; without it we cannot
    // tell which optional captures participated in the match.

    public function testWordPressSimpleRegex()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^blog/([^/]+)/?$', 'blog/hello');
        $this->assertSame('/blog/{param1}', $result);
    }

    public function testWordPressStaticRule()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^about/?$', 'about');
        $this->assertSame('/about', $result);
    }

    public function testWordPressMultipleGroups()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^([^/]+)/([^/]+)/?$', 'foo/bar');
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

    public function testWordPressRootRule()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^/?$', '');
        $this->assertSame('/', $result);
    }

    public function testWordPressMultipleCaptureGroupsInOneSegment()
    {
        // Two capture groups in the same slash-separated segment → combined with +
        // The static prefix "post-" is dropped as the whole mixed segment is treated as dynamic
        $result = RouteNormalizer::normalizeFromWordPress('^post-([^/]+)-([0-9]+)/?$', 'post-hello-42');
        $this->assertSame('/{param1+param2}', $result);
    }

    public function testWordPressStaticPrefixNotSeparateElement()
    {
        // F-07: static prefix before a capture must NOT become a separate segment element.
        // "post-([^/]+)" is a single URL segment → one RFC element.
        $result = RouteNormalizer::normalizeFromWordPress('^post-([^/]+)$', 'post-hello');
        $this->assertSame('/{param1}', $result);
    }

    public function testWordPressAbsentOptionalCaptureSkipped()
    {
        // F-11: when an inner optional capture did not participate (empty string in captures),
        // it must not produce a phantom {paramN}.
        $result = RouteNormalizer::normalizeFromWordPress('^(?:([^/]+)-)?([^/]+)$', 'x');
        $this->assertSame('/{param2}', $result);
    }

    public function testWordPressBothCapturesPresentInOptionalGroup()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^(?:([^/]+)-)?([^/]+)$', 'foo-x');
        $this->assertSame('/{param1+param2}', $result);
    }

    // Rule-plan memo: the per-rule safety scan of
    // hasOnlyCapturedWordPressDynamics runs once per rule while PHP statics
    // live (one request under FPM, mod_php and CGI).

    public function testWordPressRulePlanAcceptsSafeRule()
    {
        $plan = RouteNormalizer::wordPressRulePlan('^blog/([^/]+)/?$');
        $this->assertTrue($plan['safe']);
    }

    public function testWordPressRulePlanRejectsUnsafeRule()
    {
        // Unanchored variable text outside captures: not safe to normalize.
        $plan = RouteNormalizer::wordPressRulePlan('^foo.*bar$');
        $this->assertFalse($plan['safe']);
    }

    public function testNonDeterministicWordPressRuleStillUsesUrlPath()
    {
        // Participation of the optional capture depends on the URL — the plan
        // cache must not short-circuit this case to a precomputed shape.
        $absent = RouteNormalizer::normalizeFromWordPress('^([^/]+)(?:/([0-9]+))?/?$', 'simple');
        $present = RouteNormalizer::normalizeFromWordPress('^([^/]+)(?:/([0-9]+))?/?$', 'simple/123');
        $this->assertSame('/{param1}', $absent);
        $this->assertSame('/{param1}/{param2}', $present);
    }

    public function testWordPressRulePlanIsMemoized()
    {
        $first = RouteNormalizer::wordPressRulePlan('^blog/([^/]+)/?$');
        $second = RouteNormalizer::wordPressRulePlan('^blog/([^/]+)/?$');
        $this->assertSame($first, $second);
    }

    public function testWordPressRulePlanIsKeptInTheRoutingCache()
    {
        // The scan is a pure function of the rule, so its verdict belongs in
        // the same process-lifetime cache the other integrations use, rather
        // than in a PHP static that FPM resets before it is ever read again.
        $safeRule = '^cache-probe/([^/]+)/?$';
        $this->assertTrue(RouteNormalizer::wordPressRulePlan($safeRule)['safe']);
        $this->assertSame(
            '1',
            \DDTrace\routing_cache_get(RouteNormalizer::WORDPRESS_RULE_CACHE_PREFIX . $safeRule)
        );

        // A rejected rule is cached too, so an unsafe rule is scanned once.
        $unsafeRule = '^cache-probe/.+/?$';
        $this->assertFalse(RouteNormalizer::wordPressRulePlan($unsafeRule)['safe']);
        $this->assertSame(
            '0',
            \DDTrace\routing_cache_get(RouteNormalizer::WORDPRESS_RULE_CACHE_PREFIX . $unsafeRule)
        );
    }

    public function testWordPressCaptureAcceptingEmptyStringDependsOnUrl()
    {
        // ([^/]*) accepts an empty match: the normalized route differs per URL.
        // A capture-presence shortcut purely from the rule would wrongly return
        // the same route for both inputs.
        $rule = '^normalized-cache-shape/?([^/]*)/?$';
        $this->assertSame(
            '/normalized-cache-shape',
            RouteNormalizer::normalizeFromWordPress($rule, 'normalized-cache-shape/')
        );
        $this->assertSame(
            '/normalized-cache-shape/{param1}',
            RouteNormalizer::normalizeFromWordPress($rule, 'normalized-cache-shape/present/')
        );
    }

    public function testWordPressEscapedLiteralInRuleKeepsCharacterInUrlMatchPath()
    {
        // The URL-matched path must preserve the literal '.' from the match,
        // not drop it the way the rule-only fallback does.
        $this->assertSame(
            '/normalized-literal/file.json',
            RouteNormalizer::normalizeFromWordPress(
                '^normalized-literal/file\.json$',
                'normalized-literal/file.json/'
            )
        );
    }

    public function testWordPressNamedCapturesPreserveNamesInUrlMatchPath()
    {
        // Named captures must be emitted with their declared names, not paramN,
        // when the URL is provided.
        $this->assertSame(
            '/normalized-named-captures/{first+second}',
            RouteNormalizer::normalizeFromWordPress(
                '^normalized-named-captures/(?P<first>[^/]+)-(?P<second>[^/]+)/?$',
                'normalized-named-captures/first-second/'
            )
        );
    }

    public function testStaticPrefixLeadingTildePreservedWhenOptionalAbsent()
    {
        // F-04: rtrim — a leading special char like '~' must survive when the optional
        // param is absent. Old behaviour: trim('~foo.', '.-_~') = 'foo'. Fixed: rtrim.
        $result = RouteNormalizer::normalizeFromLaravel('~foo.{ext?}', []);
        $this->assertSame('/~foo', $result);
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
        $result = RouteNormalizer::normalizeFromWordPress('^dump-request$', 'dump-request');
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
        $result = RouteNormalizer::normalizeElements([
            ['static', '/photos/'], ['param', 'id'], ['static', '.'], ['param', 'format'],
        ]);
        $this->assertSame('/photos/{id+format}', $result);
    }

    public function testRfcExampleRailsOptionalFormatPresent()
    {
        // /posts/:id(.:format) with format present → /posts/{id+format}
        $result = RouteNormalizer::normalizeElements([
            ['static', '/posts/'], ['param', 'id'], ['static', '.'], ['param', 'format'],
        ]);
        $this->assertSame('/posts/{id+format}', $result);
    }

    public function testRfcExampleRailsOptionalFormatAbsent()
    {
        // /posts/:id(.:format) without format → /posts/{id}: the optional,
        // separator included, is resolved away before normalization.
        $result = RouteNormalizer::normalizeElements([['static', '/posts/'], ['param', 'id']]);
        $this->assertSame('/posts/{id}', $result);
    }
}
