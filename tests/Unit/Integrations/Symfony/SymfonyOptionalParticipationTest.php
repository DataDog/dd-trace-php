<?php

namespace DDTrace\Tests\Unit\Integrations\Symfony;

use DDTrace\Integrations\Symfony\EndpointCatalog;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Util\RouteNormalizer;
use Symfony\Component\Routing\Route;

/**
 * Optional path variables, over every shape Symfony's RouteCompiler can give
 * them, driven through the same steps as a request: Symfony compiles the route,
 * its compiled data is handed to the catalog in the layout
 * CompiledUrlGeneratorDumper writes, and the entry is resolved against the path.
 *
 * Two assertions are the point of this file, and they are what makes the
 * normalization Symfony's answer rather than ours:
 *
 *  - the regex the integration replays is byte-identical to the one Symfony
 *    compiled for the route (assertSame against CompiledRoute::getRegex());
 *  - the variables it reports as having taken part are exactly the ones
 *    Symfony's own matcher binds from that regex. CompiledUrlMatcherTrait's
 *    doMatch() binds a variable when its capture is set in the match, and
 *    symfonyParticipation() below applies that rule to Symfony's regex
 *    independently of the integration.
 *
 * Nothing here is inferred from the route declaration or from the shape of the
 * URL. Where a shape cannot be replayed (host variables), the tag is omitted.
 */
class SymfonyOptionalParticipationTest extends BaseTestCase
{
    /**
     * Each case: the route declaration, the requests it serves with the route
     * each must normalize to, and the requests it does not serve at all.
     *
     * @return array
     */
    public function optionalRouteShapes()
    {
        return [
            // One trailing optional: the separator goes with the variable.
            'single trailing optional' => [
                '/opt/single/{page}',
                ['page' => 1],
                [],
                '',
                [
                    '/opt/single' => '/opt/single',
                    '/opt/single/2' => '/opt/single/{page}',
                ],
                // Symfony redirects the trailing slash instead of matching it.
                ['/opt/single/'],
            ],
            // Nested optionals: the second cannot take part without the first.
            'two trailing optionals' => [
                '/opt/two/{a}/{b}',
                ['a' => 'x', 'b' => 'y'],
                [],
                '',
                [
                    '/opt/two' => '/opt/two',
                    '/opt/two/1' => '/opt/two/{a}',
                    '/opt/two/1/2' => '/opt/two/{a}/{b}',
                ],
                [],
            ],
            // Rule 5: an optional sharing the segment of a mandatory variable.
            'optional dot suffix' => [
                '/opt/dot/{id}.{format}',
                ['format' => 'html'],
                [],
                '',
                [
                    '/opt/dot/5' => '/opt/dot/{id}',
                    '/opt/dot/5.json' => '/opt/dot/{id+format}',
                ],
                [],
            ],
            // The RFC's multiple-optional-subparts example, one segment deep.
            'three subparts in one segment' => [
                '/opt/three/{a}.{b}.{c}',
                ['b' => 'x', 'c' => 'y'],
                [],
                '',
                [
                    '/opt/three/1' => '/opt/three/{a}',
                    '/opt/three/1.2' => '/opt/three/{a+b}',
                    '/opt/three/1.2.3' => '/opt/three/{a+b+c}',
                ],
                [],
            ],
            // '!' makes a defaulted variable mandatory again.
            'important variable is not optional' => [
                '/opt/important/{!page}',
                ['page' => 1],
                [],
                '',
                [
                    '/opt/important/2' => '/opt/important/{page}',
                ],
                ['/opt/important'],
            ],
            // Static text after the variable ends the optional tail.
            'defaulted variable before static text' => [
                '/opt/prefix/{locale}/index',
                ['locale' => 'en'],
                [],
                '',
                [
                    '/opt/prefix/fr/index' => '/opt/prefix/{locale}/index',
                ],
                ['/opt/prefix/index'],
            ],
            // A declared trailing slash is static text, so {page} stays
            // mandatory, and rule 1 keeps the slash in the normalized route.
            'declared trailing slash' => [
                '/opt/slash/{page}/',
                ['page' => 1],
                [],
                '',
                [
                    '/opt/slash/2/' => '/opt/slash/{page}/',
                ],
                ['/opt/slash/', '/opt/slash/2'],
            ],
            // The whole path is one optional variable: its separator stays
            // mandatory (RouteCompiler's first-token special case).
            'optional is the whole path' => [
                '/{page}',
                ['page' => 1],
                [],
                '',
                [
                    '/' => '/',
                    '/2' => '/{page}',
                ],
                [],
            ],
            // A non-slash separator is taken into the optional just the same,
            // and the segment it shares collapses to one element (rule 5).
            'optional behind a comma separator' => [
                '/opt/sep,{page}',
                ['page' => 1],
                [],
                '',
                [
                    '/opt/sep' => '/opt/sep',
                    '/opt/sep,2' => '/opt/{page}',
                ],
                [],
            ],
            // The requirement decides whether an empty capture takes part:
            // '.*' matches the bare separator, '.+' does not.
            'catch-all optional accepting an empty value' => [
                '/opt/catchall/{rest}',
                ['rest' => 'none'],
                ['rest' => '.*'],
                '',
                [
                    '/opt/catchall' => '/opt/catchall',
                    '/opt/catchall/' => '/opt/catchall/{rest}',
                    '/opt/catchall/a/b' => '/opt/catchall/{rest}',
                ],
                [],
            ],
            'catch-all optional requiring a value' => [
                '/opt/tail/{rest}',
                ['rest' => 'none'],
                ['rest' => '.+'],
                '',
                [
                    '/opt/tail' => '/opt/tail',
                    '/opt/tail/a/b' => '/opt/tail/{rest}',
                ],
                ['/opt/tail/'],
            ],
            // The default does not have to satisfy the requirement, and a URL
            // carrying it is then not served by the route at all.
            'optional whose default violates its requirement' => [
                '/opt/req/{page}',
                ['page' => 'all'],
                ['page' => '\d+'],
                '',
                [
                    '/opt/req' => '/opt/req',
                    '/opt/req/7' => '/opt/req/{page}',
                ],
                ['/opt/req/all'],
            ],
            // Host variables take part in the subject Symfony matched, which is
            // not the route, so the route cannot be replayed and the tag is
            // omitted for every request (see docs/appsec_normalized_route.md).
            'optional on a route with host variables' => [
                '/opt/host/{page}',
                ['page' => 1],
                ['sub' => '.+'],
                '{sub}',
                [
                    '/opt/host' => null,
                    '/opt/host/2' => null,
                ],
                [],
            ],
        ];
    }

    /**
     * @dataProvider optionalRouteShapes
     *
     * @param string $path
     * @param string $host
     */
    public function testNormalizationFollowsSymfonysOwnMatch(
        $path,
        array $defaults,
        array $requirements,
        $host,
        array $served,
        array $notServed
    ) {
        if (strpos($path, '{!') !== false && !self::supportsImportantVariables()) {
            $this->markTestSkipped('symfony/routing here has no important variables');
        }

        $route = new Route($path, $defaults, $requirements, [], $host);
        $compiled = $route->compile();
        $generatorData = [
            $compiled->getVariables(),
            $defaults,
            $requirements,
            $compiled->getTokens(),
            $compiled->getHostTokens(),
            [],
        ];

        $plan = EndpointCatalog::normalizationPlanFromGeneratorData($generatorData);
        $this->assertNotNull($plan, "no normalization plan for $path");

        $regex = EndpointCatalog::replayRegexFromGeneratorData($generatorData);
        if ($host !== '') {
            $this->assertNull($regex, "a host route must not be replayed: $path");
        } else {
            // The replayed regex is Symfony's, not one of our own making. It is
            // the regex its matcher runs rather than the one its compiler
            // emits: CompiledUrlMatcherDumper rewrites a declared trailing '/$'
            // to '/?$', and CompiledUrlMatcherTrait retries the trimmed path to
            // tell a match from a redirect, so the replay has to accept the
            // trailing slash and matchedParamsFromReplay() guards the redirect.
            $this->assertSame(
                EndpointCatalog::matcherRegexFromRouteRegex($compiled->getRegex()),
                $regex,
                "replay regex differs from Symfony's matcher regex for $path"
            );
        }

        $entry = $this->cacheEntry($plan, $regex);

        foreach ($served as $url => $expected) {
            $this->assertSame(
                $expected,
                EndpointCatalog::normalizedRouteFromCacheEntry($entry, $url),
                "normalized route for $path with $url"
            );

            if ($host !== '') {
                continue;
            }

            // Symfony's own answer for the same request, from its own regex.
            $oracle = self::symfonyParticipation($compiled->getRegex(), $url, $compiled->getPathVariables());
            $this->assertNotNull($oracle, "Symfony's regex does not match $url, which $path is said to serve");
            $this->assertSame(
                $oracle,
                $this->participationFromIntegration($plan, $regex, $url),
                "participation differs from Symfony's for $path with $url"
            );
        }

        foreach ($notServed as $url) {
            $this->assertNull(
                self::symfonyParticipation($compiled->getRegex(), $url, $compiled->getPathVariables()),
                "$path is not supposed to serve $url"
            );
            if ($plan['ambiguous']) {
                // Replayed routes resolve against the path, so a path the route
                // does not serve has no normalized route either.
                $this->assertNull(
                    EndpointCatalog::normalizedRouteFromCacheEntry($entry, $url),
                    "normalized route for $path with unserved $url"
                );
            }
        }
    }

    public function testAmbiguousPlansAlwaysEndWithTheirOptionalVariable()
    {
        // normalizedRouteFromCacheEntry() takes the name of the last variable
        // from the end of the path template, which holds only because a route
        // is ambiguous precisely when its last path element is an optional
        // variable. Pin that, since the derivation is silent if it breaks.
        foreach ($this->optionalRouteShapes() as $name => $case) {
            list($path, $defaults, $requirements, $host) = $case;
            $route = new Route($path, $defaults, $requirements, [], $host);
            $compiled = $route->compile();
            $plan = EndpointCatalog::normalizationPlanFromGeneratorData([
                $compiled->getVariables(),
                $defaults,
                $requirements,
                $compiled->getTokens(),
                $compiled->getHostTokens(),
                [],
            ]);

            if ($plan !== null && $plan['ambiguous']) {
                $this->assertStringEndsWith('}', $plan['path'], "ambiguous plan not ending in a variable: $name");
            }
        }
    }

    /**
     * The entry routeCacheEntry() would store for this plan. Only the cache
     * directory and the generator file are left out; they are what a unit test
     * cannot supply.
     *
     * @param string|null $regex
     * @return string
     */
    private function cacheEntry(array $plan, $regex)
    {
        if (!$plan['ambiguous']) {
            $normalization = (string) RouteNormalizer::normalizeFromSymfony($plan['path'], $plan['matchedParams']);
        } else {
            $normalization = $regex === null ? '' : EndpointCatalog::REPLAY_MARKER . $regex;
        }

        return $plan['path'] . "\0" . $normalization;
    }

    /**
     * The variables the integration reports as having taken part: replayed for
     * routes with optionals, taken from the declaration otherwise (where every
     * variable is mandatory, so a match implies all of them).
     *
     * @param string|null $regex
     * @return array<string, bool>
     */
    private function participationFromIntegration(array $plan, $regex, $url)
    {
        if (!$plan['ambiguous']) {
            $params = $plan['matchedParams'];
            ksort($params);
            return $params;
        }

        $lastVariable = substr($plan['path'], strrpos($plan['path'], '{') + 1, -1);
        $params = EndpointCatalog::matchedParamsFromReplay($regex, $lastVariable, $url);
        $this->assertNotNull($params, "replay found no match for $url");
        ksort($params);

        return $params;
    }

    /**
     * Whether this symfony/routing knows important variables ('{!name}').
     * They arrived in 4.1; before that compilePattern() matches variables with
     * '#\{\w+\}#', so the '!' leaves the whole placeholder as static text and
     * the route compiles with no variable at all. On PHP 7.0 the newest
     * resolvable version is 3.4, since 4.x requires PHP 7.1.3.
     *
     * @return bool
     */
    private static function supportsImportantVariables()
    {
        $route = new Route('/{!x}');

        return $route->compile()->getPathVariables() === ['x'];
    }

    /**
     * Which variables Symfony's matcher binds from its own compiled regex for
     * this path: a capture that is set in the match took part
     * (CompiledUrlMatcherTrait::doMatch()). preg_match() leaves out captures
     * after the last participating one and leaves earlier ones as ''.
     *
     * This uses the compiler's regex, not the matcher's slash-tolerant form, so
     * "does not match" here means the route itself does not describe the path —
     * a trailing slash the route never declared makes it a redirect for
     * Symfony, and an omitted tag for us.
     *
     * @return array<string, bool>|null null when the route does not match
     */
    private static function symfonyParticipation($regex, $url, array $pathVariables)
    {
        $matches = [];
        if (!preg_match($regex, rawurldecode($url) ?: '/', $matches)) {
            return null;
        }

        $participated = [];
        foreach ($pathVariables as $name) {
            if (isset($matches[$name])) {
                $participated[$name] = true;
            }
        }
        ksort($participated);

        return $participated;
    }
}
