<?php

namespace DDTrace\Tests\Unit\Util\Normalizer;

use DDTrace\Tests\Common\BaseTestCase;

class NormalizerTest extends BaseTestCase
{
        /**
         * @dataProvider dataProviderDynamicUrl
         * @param string $url
         * @param string $expected
         * @return void
         */
        public function testDynamicUrlNormalizer($url, $expected)
        {
            $this->assertSame($expected, \DDTrace\Util\Normalizer::normalizeDynamicUrl($url));
        }

        public function dataProviderDynamicUrl()
        {
            return [
                    // empty
                    [null, ''],
                    ['', ''],
                    ['dynamic_route/{param01}/static/{param02?}', '/dynamic_route/:param01/static/:param02?'],
                    ['dynamic_route/{param01}/static/{param02?}/', '/dynamic_route/:param01/static/:param02?/'],
                    //Url without anything to change except for adding the first slash
                    ['dynamic_route/param01/static/param02/', '/dynamic_route/param01/static/param02/'],
                    ['/dynamic_route/{param01}/static/{param02?}', '/dynamic_route/:param01/static/:param02?'],
                    ['/dynamic_route/{param01}/static/{param02?}/', '/dynamic_route/:param01/static/:param02?/'],
                    ['/dynamic_route/param01/static/param02/', '/dynamic_route/param01/static/param02/'],
                    ['/posts/{post:slug}/images/{image?:size}/', '/posts/:post/images/:image?/'],
            ];
        }
}
