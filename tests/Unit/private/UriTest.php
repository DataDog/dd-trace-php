<?php

namespace DDTrace\Tests\Unit\Private_;

use DDTrace\Tests\Common\BaseTestCase;

class UriTest extends BaseTestCase
{
    protected function ddSetUp()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING',
            'DD_TRACE_RESOURCE_URI_MAPPING',
        ]);
        parent::ddSetUp();
    }

    protected function ddTearDown()
    {
        parent::ddTearDown();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING',
            'DD_TRACE_RESOURCE_URI_MAPPING',
        ]);
    }

    public function testLegacyIsStillAppliedIfNewSettingsNotDefined()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_MAPPING=/user/*',
        ]);
        $this->assertSame(
            '/user/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/user/123/nested/path')
        );
        $this->assertSame(
            '/user/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/user/123/nested/path')
        );
    }

    public function testLegacyIsIgnoredIfAtLeastOneNewSettingIsDefined()
    {
        // When DD_TRACE_RESOURCE_URI_MAPPING_INCOMING is also set
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_MAPPING=/user/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=nested/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING',
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX',
        ]);
        $this->assertSame(
            '/user/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/user/123/nested/path')
        );
        $this->assertSame(
            '/user/?/nested/path',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/user/123/nested/path')
        );

        // When DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING is also set
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_MAPPING=/user/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=nested/*',
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX',
        ]);
        $this->assertSame(
            '/user/?/nested/path',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/user/123/nested/path')
        );
        $this->assertSame(
            '/user/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/user/123/nested/path')
        );

        // When DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX is also set
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_MAPPING=/user/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING',
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=^path$',
        ]);
        $this->assertSame(
            '/user/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/user/123/nested/path')
        );
        $this->assertSame(
            '/user/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/user/123/nested/path')
        );
    }

    public function testIncomingConfigurationDoesNotImpactOutgoing()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=before/*']);
        $this->assertSame(
            '/before/something/after',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/before/something/after')
        );
        $this->assertSame(
            '/before/?/after',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/before/something/after')
        );
    }

    public function testOutgoingConfigurationDoesNotImpactIncoming()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=before/*']);
        $this->assertSame(
            '/before/something/after',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/before/something/after')
        );
        $this->assertSame(
            '/before/?/after',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/before/something/after')
        );
    }

    public function testWrongIncomingConfigurationResultsInMissedPathNormalizationButDefaultStillWorks()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=no_asterisk,']);
        $this->assertSame(
            '/no_asterisk/?/after',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/no_asterisk/123/after')
        );
    }

    public function testWrongOutgoingConfigurationResultsInMissedPathNormalizationButDefaultStillWorks()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=no_asterisk,']);
        $this->assertSame(
            '/no_asterisk/?/after',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/no_asterisk/123/after')
        );
    }

    public function testMixingFragmentRegexAndPatternMatchingIncoming()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=name/*']);
        $this->assertSame(
            '/numeric/?/name/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/numeric/123/name/some_name')
        );
    }

    public function testMixingFragmentRegexAndPatternMatchingOutgoing()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=name/*']);
        $this->assertSame(
            '/numeric/?/name/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/numeric/123/name/some_name')
        );
    }

    /**
     * @dataProvider defaultPathNormalizationScenarios
     */
    public function testDefaultPathFragmentsNormalizationIncoming($uri, $expected)
    {
        $this->assertSame(\DDtrace\Private_\util_uri_normalize_incoming_path($uri), $expected);
    }

    /**
     * @dataProvider defaultPathNormalizationScenarios
     */
    public function testDefaultPathFragmentsNormalizationOutgoing($uri, $expected)
    {
        $this->assertSame(\DDtrace\Private_\util_uri_normalize_outgoing_path($uri), $expected);
    }

    public function defaultPathNormalizationScenarios()
    {
        return [
            // Defaults, no custom configuration
            'empty' => ['', '/'],
            'root' => ['/', '/'],

            'only_digits' => ['/123', '/?'],
            'starts_with_digits' => ['/123/path', '/?/path'],
            'ends_with_digits' => ['/path/123', '/path/?'],
            'has_digits' => ['/before/123/path', '/before/?/path'],

            'only_hex' => ['/0123456789abcdef', '/?'],
            'starts_with_hex' => ['/0123456789abcdef/path', '/?/path'],
            'ends_with_hex' => ['/path/0123456789abcdef', '/path/?'],
            'has_hex' => ['/before/0123456789abcdef/path', '/before/?/path'],

            'only_uuid' => ['/b968fb04-2be9-494b-8b26-efb8a816e7a5', '/?'],
            'starts_with_uuid' => ['/b968fb04-2be9-494b-8b26-efb8a816e7a5/path', '/?/path'],
            'ends_with_uuid' => ['/path/b968fb04-2be9-494b-8b26-efb8a816e7a5', '/path/?'],
            'has_uuid' => ['/before/b968fb04-2be9-494b-8b26-efb8a816e7a5/path', '/before/?/path'],

            'only_uuid_no_dash' => ['/b968fb042be9494b8b26efb8a816e7a5', '/?'],
            'starts_with_uuid_no_dash' => ['/b968fb042be9494b8b26efb8a816e7a5/path', '/?/path'],
            'ends_with_uuid_no_dash' => ['/path/b968fb042be9494b8b26efb8a816e7a5', '/path/?'],
            'has_uuid_no_dash' => ['/before/b968fb042be9494b8b26efb8a816e7a5/path', '/before/?/path'],

            'multiple_patterns' => ['/int/1/uuid/b968fb042be9494b8b26efb8a816e7a5/int/2', '/int/?/uuid/?/int/?'],

            // Case insensitivity
            'hex_case_insensitive' => ['/some/path/b968Fb04-2bE9-494B-8b26-Efb8A816e7a5/after', '/some/path/?/after'],
            'uuid_case_insensitive' => ['/some/path/0123456789AbCdEf/after', '/some/path/?/after'],
        ];
    }

    public function testProvidedFragmentRegexAreAdditiveToDefaultFragmentRegexes()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=^some_name$',
        ]);

        $this->assertSame(
            '/int/?/name/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123/name/some_name')
        );
        $this->assertSame(
            '/int/?/name/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123/name/some_name')
        );
    }

    public function testProvidedFragmentRegexHasOptionalLeadingAndTrailingSlash()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=^some_name$',
        ]);

        $this->assertSame(
            '/name/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/name/some_name')
        );
        $this->assertSame(
            '/name/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/name/some_name')
        );
    }

    public function testProvidedFragmentRegexCanHaveLeadingAndTrailingSlash()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=/^some_name$/',
        ]);

        $this->assertSame(
            '/name/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/name/some_name')
        );
        $this->assertSame(
            '/name/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/name/some_name')
        );
    }

    public function testProvidedFragmentRegexCanHaveLeadingAndTrailingSpaces()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=^some_name$    ,       ^other$     ',
        ]);

        $this->assertSame(
            '/name/?/age/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/name/some_name/age/other')
        );
        $this->assertSame(
            '/name/?/age/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/name/some_name/age/other')
        );
    }

    public function testWrongFragmentNormalizationRegexDoesNotCauseError()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=/(((((]]]]]]wrong_regex$/',
        ]);

        $this->assertSame(
            '/int/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123')
        );
        $this->assertSame(
            '/int/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123')
        );
    }

    public function testWrongFragmentNormalizationRegexDoesNotImpactOtherRegexes()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=(((((]]]]]]wrong_regex$,valid',
        ]);

        $this->assertSame(
            '/int/?/path/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123/path/valid')
        );
        $this->assertSame(
            '/int/?/path/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123/path/valid')
        );
    }

    public function testProvidedPathIsAddedLeadingSlashIfMissing()
    {
        $this->assertSame(
            '/int/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('int/123')
        );
        $this->assertSame(
            '/int/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('int/123')
        );
    }

    public function testUriAcceptsTrailingSlash()
    {
        $this->assertSame(
            '/int/?/',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123/')
        );
        $this->assertSame(
            '/int/?/',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123/')
        );
    }

    public function testSamePatternMultipleLocations()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=path/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=path/*',
        ]);

        $this->assertSame(
            '/int/?/path/?/int/?/path/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123/path/one/int/456/path/two')
        );
        $this->assertSame(
            '/int/?/path/?/int/?/path/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123/path/one/int/456/path/two')
        );
    }

    public function testPartialMatching()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=path/*-something',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=path/*-something',
        ]);

        $this->assertSame(
            '/int/?/path/?-something/path/two-else',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123/path/one-something/path/two-else')
        );
        $this->assertSame(
            '/int/?/path/?-something/path/two-else',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123/path/one-something/path/two-else')
        );
    }

    public function testComplexPatterns()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=path/*/*/then/something/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=path/*/*/then/something/*',
        ]);

        $this->assertSame(
            '/int/?/path/?/?/then/something/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123/path/one/two/then/something/else')
        );
        $this->assertSame(
            '/int/?/path/?/?/then/something/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123/path/one/two/then/something/else')
        );
    }

    public function testPatternCanNormalizeSingleFragment()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=*-something',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=*-something',
        ]);

        $this->assertSame(
            '/int/?/path/?-something/else',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123/path/one-something/else')
        );
        $this->assertSame(
            '/int/?/path/?-something/else',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123/path/one-something/else')
        );
    }

    public function testItWorksWithHttpFulllUrls()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=^abc$',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=nested/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=nested/*',
        ]);

        $this->assertSame(
            'http://example.com/int/?/path/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('http://example.com/int/123/path/abc/nested/some')
        );
        $this->assertSame(
            'http://example.com/int/?/path/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('http://example.com/int/123/path/abc/nested/some')
        );
    }

    public function testItWorksWithHttpsFulllUrls()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=^abc$',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=nested/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=nested/*',
        ]);

        $this->assertSame(
            'https://example.com/int/?/path/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('https://example.com/int/123/path/abc/nested/some')
        );
        $this->assertSame(
            'https://example.com/int/?/path/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('https://example.com/int/123/path/abc/nested/some')
        );
    }

    public function testItWorksWithComplexSchemePatternAsDefinedByRFC3986()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=^abc$',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=nested/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=nested/*',
        ]);

        // https://tools.ietf.org/html/rfc3986#page-17
        $rfc3986CompliantScheme = 'letter+1-2-3.CAPITAL.123';

        $this->assertSame(
            "$rfc3986CompliantScheme://example.com/int/?/path/?/nested/?",
            \DDtrace\Private_\util_uri_normalize_incoming_path(
                "$rfc3986CompliantScheme://example.com/int/123/path/abc/nested/some"
            )
        );
        $this->assertSame(
            "$rfc3986CompliantScheme://example.com/int/?/path/?/nested/?",
            \DDtrace\Private_\util_uri_normalize_outgoing_path(
                "$rfc3986CompliantScheme://example.com/int/123/path/abc/nested/some"
            )
        );
    }

    public function testItWorksWithHttpFulllUrlsIncludingPort()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=^abc$',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=nested/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=nested/*',
        ]);

        $this->assertSame(
            'http://example.com:8888/int/?/path/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_incoming_path('http://example.com:8888/int/123/path/abc/nested/some')
        );
        $this->assertSame(
            'http://example.com:8888/int/?/path/?/nested/?',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('http://example.com:8888/int/123/path/abc/nested/some')
        );
    }

    public function testCaseSensitivity()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=nEsTeD/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=nEsTeD/*',
        ]);

        $this->assertSame(
            '/int/?/nested/some',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123/nested/some')
        );
        $this->assertSame(
            '/int/?/nested/some',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123/nested/some')
        );
    }

    public function testQueryStringIsRemoved()
    {
        $this->assertSame(
            '/int/?/nested/some',
            \DDtrace\Private_\util_uri_normalize_incoming_path('/int/123/nested/some?key=value')
        );
        $this->assertSame(
            '/int/?/nested/some',
            \DDtrace\Private_\util_uri_normalize_outgoing_path('/int/123/nested/some?key=value')
        );
    }

    /**
     * @dataProvider dataProviderSanitizeNoDropUserinfo
     * @param string $url
     * @param string $expected
     * @return void
     */
    public function testSanitizeNoDropUserinfo($url, $expected)
    {
        /* This test is an exact replica of the same method in tests/Unit/Http/UrlsTest.php and has to be kept in sync
         * until the current test will be removed as part of the PHP->C migration
         */
        $this->assertSame($expected, \DDtrace\Private_\util_url_sanitize($url));
    }

    public function dataProviderSanitizeNoDropUserinfo()
    {
        return [
            // empty
            [null, ''],
            ['', ''],

            // with schema
            ['https://some_url.com/path/', 'https://some_url.com/path/'],

            // with no schema
            ['some_url.com/path/', 'some_url.com/path/'],

            // query and fragment
            ['some_url.com/path/?some=value', 'some_url.com/path/'],
            ['some_url.com/path/?some=value#fragment', 'some_url.com/path/'],

            // userinfo
            ['my_user:my_password@some_url.com/path/', '?:?@some_url.com/path/'],
            ['my_user:@some_url.com/path/', '?:@some_url.com/path/'],
            ['my_user:@some_url.com/path/?key=value', '?:@some_url.com/path/'],
            ['https://my_user:my_password@some_url.com/path/', 'https://?:?@some_url.com/path/'],
            ['https://my_user:@some_url.com/path/', 'https://?:@some_url.com/path/'],

            // idempotency
            ['https://?:@some_url.com/path/?key=value', 'https://?:@some_url.com/path/'],
            ['?:?@some_url.com/path/', '?:?@some_url.com/path/'],
            ['?:@some_url.com/path/?some=?#fragment', '?:@some_url.com/path/'],

            // false positives that should not be sanitized, but we accept this lack of correctness to reduce complexity
            ['https://my_user:@some_url.com/before/a:b@/after', 'https://?:@some_url.com/before/?:?@/after'],
            [
                'https://my_user:my_passwords@some_url.com/before/a:b@/after',
                'https://?:?@some_url.com/before/?:?@/after',
            ],
        ];
    }

    /**
     * @dataProvider dataProviderSanitizeDropUserinfo
     * @param string $url
     * @param string $expected
     * @return void
     */
    public function testSanitizeDropUserinfo($url, $expected)
    {
        /* This test is an exact replica of the same method in tests/Unit/Http/UrlsTest.php and has to be kept in sync
         * until the current test will be removed as part of the PHP->C migration
         */
        $this->assertSame($expected, \DDtrace\Private_\util_url_sanitize($url, true));
    }

    public function dataProviderSanitizeDropUserinfo()
    {
        return [
            // empty
            [null, ''],
            ['', ''],

            // with schema
            ['https://some_url.com/path/', 'https://some_url.com/path/'],

            // with no schema
            ['some_url.com/path/', 'some_url.com/path/'],

            // query and fragment
            ['some_url.com/path/?some=value', 'some_url.com/path/'],
            ['some_url.com/path/?some=value#fragment', 'some_url.com/path/'],

            // userinfo
            ['my_user:my_password@some_url.com/path/', 'some_url.com/path/'],
            ['my_user:@some_url.com/path/', 'some_url.com/path/'],
            ['my_user:@some_url.com/path/?key=value', 'some_url.com/path/'],
            ['https://my_user:my_password@some_url.com/path/', 'https://some_url.com/path/'],
            ['https://my_user:@some_url.com/path/', 'https://some_url.com/path/'],
            ['https://my_user:@some_url.com/path/?key=value', 'https://some_url.com/path/'],

            // idempotency
            ['https://?:@some_url.com/path/?key=value', 'https://some_url.com/path/'],
            ['?:?@some_url.com/path/', 'some_url.com/path/'],
            ['?:@some_url.com/path/?some=?#fragment', 'some_url.com/path/'],

            // false positives that should not be sanitized, but we accept this lack of correctness to reduce complexity
            ['https://my_user:@some_url.com/before/a:b@/after', 'https://some_url.com/before//after'],
            [
                'https://my_user:my_passwords@some_url.com/before//after',
                'https://some_url.com/before//after',
            ],
        ];
    }
}
