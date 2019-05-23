<?php

namespace DDTrace\Tests\Unit\Obfuscation;

use DDTrace\Obfuscation\WildcardToRegex;
use DDTrace\Tests\Unit\BaseTestCase;

final class WildcardToRegexTest extends BaseTestCase
{
    /**
     * @dataProvider wildcardToRegexExamples
     * @param string $withWildcards
     * @param array $regexAndReplacement
     */
    public function testWildcardStringsCanConvertToRegex($withWildcards, array $regexAndReplacement)
    {
        $this->assertSame($regexAndReplacement, WildcardToRegex::convert($withWildcards));
    }

    public function wildcardToRegexExamples()
    {
        return [
            ['/foo/*/bar',                  ['|^/foo/.+/bar$|', '/foo/?/bar']],
            ['/foo/*',                      ['|^/foo/.+$|', '/foo/?']],
            ['/city/*/$*',                  ['|^/city/.+/(.+)$|', '/city/?/${1}']],
            ['/with/%5B$*%5D/percents/*',   ['|^/with/%5B(.+)%5D/percents/.+$|', '/with/%5B${1}%5D/percents/?']],
        ];
    }
}
