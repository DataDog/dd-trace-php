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
        $this->assertSame('%C3%A9', RouteNormalizer::encodeStaticSegment('%c3%a9'));
    }

    public function testEncodeStaticSegmentEncodesALiteralPercent()
    {
        // The framework treats '%' literally: never read as an escape.
        $this->assertSame('%25c3%25a9', RouteNormalizer::encodeStaticSegment('%c3%a9', false));
        $this->assertSame('100%25', RouteNormalizer::encodeStaticSegment('100%', false));
        $this->assertSame('caf%C3%A9', RouteNormalizer::encodeStaticSegment("caf\xC3\xA9", false));
    }

    // encodeParamName

    public function testEncodeParamNamePreservesNormal()
    {
        $this->assertSame('id', RouteNormalizer::encodeParamName('id'));
        $this->assertSame('user_id', RouteNormalizer::encodeParamName('user_id'));
    }

    public function testEncodeParamNameReplacesCharactersOutsideTheOpenApiSet()
    {
        $this->assertSame('foo_bar', RouteNormalizer::encodeParamName('foo+bar'));
        $this->assertSame('foo_bar', RouteNormalizer::encodeParamName('foo#bar'));
        // RFC-1125: one '_' per Unicode code point, not per byte.
        $this->assertSame('f_o', RouteNormalizer::encodeParamName("f\xC3\xB6o"));
        // Not valid UTF-8: replaced byte by byte.
        $this->assertSame('f_o', RouteNormalizer::encodeParamName("f\xF6o"));
        $this->assertSame('Id_2', RouteNormalizer::encodeParamName('Id-2'));
        $this->assertSame('_', RouteNormalizer::encodeParamName(''));
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
        // /photos/{id}.{format} → both in the same URL segment, kept separate
        $result = RouteNormalizer::normalizeFromLaravel('/photos/{id}.{format}', ['id' => '1', 'format' => 'jpg']);
        $this->assertSame('/photos/{id}.{format}', $result);
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
        $this->assertSame('/files/{name}.{ext}', $result);
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
        $this->assertSame('/posts/{id}.{_format}', $result);
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

    public function testNormalizeElementsKeepsStaticTextAndParametersOfOneSegment()
    {
        $this->assertSame('/v/v{version}{rest}/{id}', RouteNormalizer::normalizeElements([
            ['static', '/v/v'], ['param', 'version'], ['param', 'rest'], ['static', '/'], ['param', 'id'],
        ]));
        $this->assertSame('/users/user-{id}', RouteNormalizer::normalizeElements([
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
        $this->assertSame('/a%2Bb/{x_y}', RouteNormalizer::normalizeElements([
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
        // Two capture groups in the same URL segment stay separate, and the
        // static text around them is kept.
        $result = RouteNormalizer::normalizeFromWordPress('^post-([^/]+)-([0-9]+)/?$', 'post-hello-42');
        $this->assertSame('/post-{param1}-{param2}', $result);
    }

    public function testWordPressStaticPrefixKeptInItsSegment()
    {
        // "post-([^/]+)" is a single URL segment: the static prefix stays in it.
        $result = RouteNormalizer::normalizeFromWordPress('^post-([^/]+)$', 'post-hello');
        $this->assertSame('/post-{param1}', $result);
    }

    public function testWordPressAbsentOptionalCaptureSkipped()
    {
        // F-11: when an inner optional capture did not participate (empty string in captures),
        // it must not produce a phantom {paramN}.
        // RFC-1125: an unnamed capture gets param<N>, the smallest N not used.
        $result = RouteNormalizer::normalizeFromWordPress('^(?:([^/]+)-)?([^/]+)$', 'x');
        $this->assertSame('/{param1}', $result);
    }

    public function testWordPressBothCapturesPresentInOptionalGroup()
    {
        $result = RouteNormalizer::normalizeFromWordPress('^(?:([^/]+)-)?([^/]+)$', 'foo-x');
        $this->assertSame('/{param1}-{param2}', $result);
    }

    // Rule-plan memo: the per-rule safety scan of
    // regexHasOnlyCapturedDynamics runs once per rule while PHP statics
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
            '/normalized-named-captures/{first}-{second}',
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

    public function testAbsentOptionalKeepsStaticTextOfItsSegment()
    {
        $this->assertSame('/users/user-{id}', RouteNormalizer::normalizeFromLaravel(
            '/users/user-{id}.{format?}',
            ['id' => '1']
        ));
        $this->assertSame('/users/user-{id}.{format}', RouteNormalizer::normalizeFromLaravel(
            '/users/user-{id}.{format?}',
            ['id' => '1', 'format' => 'json']
        ));
    }

    public function testUnknownOptionalPresenceFallsBackToMandatoryParameters()
    {
        // With no matched parameters, only the optionals are left out.
        $this->assertSame('/users/{id}', RouteNormalizer::normalizeFromLaravel('/users/{id}/{format?}', []));
        $this->assertSame('/articles/{slug}', RouteNormalizer::normalizeFromSymfony(
            '/articles/{slug}.{format}',
            ['slug' => true]
        ));
    }

    public function testRfcExampleRailsMandatoryFormat()
    {
        // http.route: /photos/:id.:format → /photos/{id}.{format}
        $result = RouteNormalizer::normalizeElements([
            ['static', '/photos/'], ['param', 'id'], ['static', '.'], ['param', 'format'],
        ]);
        $this->assertSame('/photos/{id}.{format}', $result);
    }

    public function testRfcExampleRailsOptionalFormatPresent()
    {
        // /posts/:id(.:format) with format present → /posts/{id}.{format}
        $result = RouteNormalizer::normalizeElements([
            ['static', '/posts/'], ['param', 'id'], ['static', '.'], ['param', 'format'],
        ]);
        $this->assertSame('/posts/{id}.{format}', $result);
    }

    public function testRfcExampleRailsOptionalFormatAbsent()
    {
        // /posts/:id(.:format) without format → /posts/{id}: the optional,
        // separator included, is resolved away before normalization.
        $result = RouteNormalizer::normalizeElements([['static', '/posts/'], ['param', 'id']]);
        $this->assertSame('/posts/{id}', $result);
    }

    // RFC-1125 test cases

    public function testRfcStaticTextIsPercentEncoded()
    {
        // Tests 9-11. Laravel and Symfony match the decoded path, so a '%' in
        // their route is a literal character.
        $this->assertSame('/caf%C3%A9', RouteNormalizer::normalizeFromSymfony("/caf\xC3\xA9", []));
        $this->assertSame('/user%3Ame', RouteNormalizer::normalizeFromLaravel('/user:me'));
        $this->assertSame('/a%2Bb/{id}', RouteNormalizer::normalizeFromLaravel('/a+b/{id}'));
        $this->assertSame('/caf%25C3%25A9', RouteNormalizer::normalizeFromSymfony('/caf%C3%A9', []));
        // Laminas and WordPress match the encoded path: the escape is kept.
        $this->assertSame('/caf%C3%A9', RouteNormalizer::normalizeElements([['static', '/caf%c3%a9']]));
    }

    public function testRfcParameterNames()
    {
        // Tests 30-34.
        $this->assertSame('/users/{user_id}', RouteNormalizer::normalizeElements([
            ['static', '/users/'], ['param', 'user-id'],
        ]));
        $this->assertSame('/items/{item_id}', RouteNormalizer::normalizeElements([
            ['static', '/items/'], ['param', 'item.id'],
        ]));
        $this->assertSame('/a/{user_id_2}/{user_id}', RouteNormalizer::normalizeElements([
            ['static', '/a/'], ['param', 'user-id'], ['static', '/'], ['param', 'user_id'],
        ]));
        $this->assertSame('/a/{x_y_3}/{x_y}/{x_y_2}', RouteNormalizer::normalizeElements([
            ['static', '/a/'], ['param', 'x-y'], ['static', '/'], ['param', 'x_y'], ['static', '/'], ['param', 'x_y_2'],
        ]));
        $this->assertSame('/u/{f_o}', RouteNormalizer::normalizeElements([['static', '/u/'], ['param', "f\xC3\xB6o"]]));
    }

    public function testRfcReplacedNamesCollidingWithEachOtherAreSuffixedInPathOrder()
    {
        $this->assertSame('/a/{a_b}/{a_b_2}', RouteNormalizer::normalizeElements([
            ['static', '/a/'], ['param', 'a-b'], ['static', '/'], ['param', 'a.b'],
        ]));
    }

    public function testRfcUnnamedParametersGetTheSmallestUnusedNumber()
    {
        // Tests 36-38.
        $this->assertSame('/static/{param1}', RouteNormalizer::normalizeElements([['static', '/static/'], ['tail']]));
        $this->assertSame('/w/{param1}/{param2}', RouteNormalizer::normalizeElements([
            ['static', '/w/'], ['param', 'param1'], ['static', '/'], ['tail'],
        ]));
        $this->assertSame('/a/{param1}/b', RouteNormalizer::normalizeElements([
            ['static', '/a/'], ['tail'], ['static', '/b'],
        ]));
        $this->assertSame('/files/{param1}.txt', RouteNormalizer::normalizeElements([
            ['static', '/files/'], ['unnamed'], ['static', '.txt'],
        ]));
    }

    public function testRfcCatchAllContinuesTheSegmentBeforeIt()
    {
        // A prefix route without a trailing slash: PathPrefix("/api") hit with /apix.
        $this->assertSame('/api{param1}', RouteNormalizer::normalizeElements([['static', '/api'], ['tail']]));
        $this->assertSame('/api/{param1}', RouteNormalizer::normalizeElements([['static', '/api'], ['static', '/'], ['tail']]));
    }

    public function testWordPressRuleMatchedOnlyAfterDecoding()
    {
        // WP::parse_request() retries the rule on the URL-decoded subject;
        // its '%' and other characters are then literal.
        $this->assertSame(
            '/caf%C3%A9/{param1}',
            RouteNormalizer::normalizeFromWordPress("^caf\xC3\xA9/([^/]+)/?$", 'caf%C3%A9/x')
        );
    }

    public function testWordPressRuleWithoutEndAnchorIsAPrefixRoute()
    {
        // WordPress matches "#^rule#": the rest of the path is the rule's
        // implicit unnamed catch-all.
        $this->assertSame('/blog/{param1}', RouteNormalizer::normalizeFromWordPress('^blog', 'blog/a/b'));
        $this->assertSame('/blog{param1}', RouteNormalizer::normalizeFromWordPress('^blog', 'blogx'));
        $this->assertSame('/blog', RouteNormalizer::normalizeFromWordPress('^blog', 'blog'));
        $this->assertSame(
            '/tag/{param1}/{param2}',
            RouteNormalizer::normalizeFromWordPress('^tag/([^/]+)', 'tag/php/feed')
        );
    }

    // Whole-route regexes (Laminas Regex)

    public function testRegexMatchElementsFollowTheMatch()
    {
        $regex = '/normalized-rx-archive(?:/archive)?/(?<id>[0-9]+)';
        $this->assertSame(1, preg_match('(^' . $regex . '$)', '/normalized-rx-archive/5', $m, PREG_OFFSET_CAPTURE));
        $this->assertSame('/normalized-rx-archive/{id}', RouteNormalizer::normalizeElements(
            RouteNormalizer::regexMatchElements($regex, '/normalized-rx-archive/5', 0, 24, $m)
        ));
    }

    public function testUnnamedCaptureAroundANamedOneOnlyGroupsIt()
    {
        $regex = '/blog/(?<id>[0-9]+)(\.(?<format>[a-z]+))?';
        $this->assertTrue(RouteNormalizer::regexHasOnlyCapturedDynamics($regex));
        $this->assertSame(1, preg_match('(^' . $regex . '$)', '/blog/5.json', $m, PREG_OFFSET_CAPTURE));
        $this->assertSame('/blog/{id}.{format}', RouteNormalizer::normalizeElements(
            RouteNormalizer::regexMatchElements($regex, '/blog/5.json', 0, 12, $m)
        ));
        $this->assertSame('/blog/{id}', RouteNormalizer::normalizeElements(
            RouteNormalizer::regexFallbackElements($regex)
        ));
        // Its other text is then outside any capture, so it must be literal.
        $this->assertFalse(RouteNormalizer::regexHasOnlyCapturedDynamics('/blog/([0-9]+-(?<id>[0-9]+))'));
    }

    public function testRegexFallbackKeepsLiteralTextAndMandatoryCaptures()
    {
        $cases = [
            '/regex-year/(?P<year>\d{4})' => '/regex-year/{year}',
            '/normalized-regex/(?P<id>[a-z]+)(?:\.(?P<format>[a-z]+))?' => '/normalized-regex/{id}',
            '/normalized-rx-archive(?:/archive)?/(?<id>[0-9]+)' => '/normalized-rx-archive/{id}',
            '/normalized-rx-dot/v1\.(?<x>[a-z]+)?' => '/normalized-rx-dot/v1.',
            '/normalized-rx-unplaced/(?<lang>en|fr)/about' => '/normalized-rx-unplaced/{lang}/about',
            '/a/?' => '/a',
            '/files/([0-9]+)' => '/files/{param1}',
        ];
        foreach ($cases as $regex => $expected) {
            $elements = RouteNormalizer::regexFallbackElements($regex);
            $this->assertNotNull($elements, $regex);
            $this->assertSame($expected, RouteNormalizer::normalizeElements($elements), $regex);
        }
    }

    public function testRegexFallbackRejectsRegexesThatCannotBeExpressed()
    {
        foreach (['/a(?:b|c)/x', '/a.b', '/x(?=y)', '/a{2}', '/[a-z]+', '/a\d', '(*UTF8)/a', '/a{}'] as $regex) {
            $this->assertNull(RouteNormalizer::regexFallbackElements($regex), $regex);
        }
    }

    public function testBracesAroundAnInvalidVariableNameAreLiteralText()
    {
        // Symfony's RouteCompiler, which Laravel also uses, only takes word
        // characters as a variable name: {file-name} is literal text.
        $this->assertSame('/docs/%7Bfile-name%7D', RouteNormalizer::normalizeFromSymfony('/docs/{file-name}', []));
        $this->assertSame('/docs/%7Bfile-name%7D', RouteNormalizer::normalizeFromLaravel('/docs/{file-name}'));
        $this->assertSame(
            '/docs/%7Bfile-name%7D/{id}',
            RouteNormalizer::normalizeFromSymfony('/docs/{file-name}/{id}', ['id' => true])
        );
    }

    public function testStaticFragmentsAreJoinedBeforeEncoding()
    {
        // A Regex fallback yields one static element per character; the
        // escape must still be recognized across them.
        $this->assertSame('/caf%C3%A9/{id}', RouteNormalizer::normalizeElements(
            RouteNormalizer::regexFallbackElements('/caf%C3%A9/(?<id>[0-9]+)')
        ));
        $this->assertSame('/caf%C3%A9/{id}', RouteNormalizer::normalizeElements([
            ['static', '/caf%'], ['static', 'C3%A9/'], ['param', 'id'],
        ]));
    }

    public function testParameterSurroundedByLiteralBraces()
    {
        // Symfony's RouteCompiler finds the variable {id} inside {{id}}.
        $this->assertSame('/items/%7B{id}%7D', RouteNormalizer::normalizeFromSymfony('/items/{{id}}', ['id' => true]));
        $this->assertSame('/items/%7B{id}%7D', RouteNormalizer::normalizeFromLaravel('/items/{{id}}'));
    }

    public function testWordPressRuleWithALeadingBracketInACharacterClassIsRejected()
    {
        // A ']' right after '[' is literal, so the parentheses of [](] and
        // [])] are inside the classes and '[^/]+' is outside any capture.
        $this->assertNull(RouteNormalizer::normalizeFromWordPress('^docs/([](])[^/]+[])]$', 'docs/(alice)'));
        $this->assertFalse(RouteNormalizer::regexHasOnlyCapturedDynamics('^docs/([](])[^/]+[])]$'));
    }

    public function testWordPressRuleNotMatchingTheSubjectFallsBackToMandatoryParts()
    {
        // RFC-1125: never omit the tag because optional parts cannot be resolved.
        $this->assertSame(
            '/normalized-pathinfo/{param1}',
            RouteNormalizer::normalizeFromWordPress('(?:([^/]+)/)?normalized-pathinfo/([^/]+)/?$', 'other')
        );
        $this->assertSame('/blog/{param1}', RouteNormalizer::normalizeFromWordPressRule('^blog/([^/]+)/?$'));
        $this->assertSame(
            '/named/{first}-{second}',
            RouteNormalizer::normalizeFromWordPressRule('^named/(?P<first>[^/]+)-(?P<second>[^/]+)/?$')
        );
        // A rule that cannot be expressed gets no fallback either.
        $this->assertNull(RouteNormalizer::normalizeFromWordPressRule('^foo/.+$'));
    }

    public function testPosixClassesAreReadAsPcreReadsThem()
    {
        // The ']' of [:alpha:] does not end the enclosing class: here the ')'
        // is inside the second class, the capture never closes, and the rule
        // is rejected rather than letting uncaptured text through.
        $rule = '^docs/([:alpha:[^/]+[[:alpha:])]$';
        $this->assertFalse(RouteNormalizer::regexHasOnlyCapturedDynamics($rule));
        $this->assertNull(RouteNormalizer::normalizeFromWordPress($rule, 'docs/(alice)'));
        $this->assertNull(RouteNormalizer::normalizeFromWordPressRule($rule));

        // A POSIX class inside a capture is part of the capture: the route
        // can be expressed (RFC-1125), so it gets a tag.
        $this->assertTrue(RouteNormalizer::regexHasOnlyCapturedDynamics('^docs/([[:alpha:]]+)$'));
        $this->assertSame('/docs/{param1}', RouteNormalizer::normalizeFromWordPress('^docs/([[:alpha:]]+)$', 'docs/alice'));
        $this->assertSame('/docs/{id}', RouteNormalizer::normalizeElements(
            RouteNormalizer::regexFallbackElements('/docs/(?<id>[[:digit:]]+)')
        ));
        // A class holding ')' after a POSIX class keeps it a literal.
        $this->assertSame('/docs/{param1}', RouteNormalizer::normalizeFromWordPress('^docs/([[:alpha:])]+)$', 'docs/a)b'));
    }

    public function testEmptyWordPressRuleHasNoNormalizedRoute()
    {
        // WP::$matched_rule stays '' when no rule matched: there is no route,
        // so there is no tag, never a made-up one (RFC-1125).
        $this->assertNull(RouteNormalizer::normalizeFromWordPress('', 'some/unknown'));
        $this->assertNull(RouteNormalizer::normalizeFromWordPressRule(''));
    }
}
