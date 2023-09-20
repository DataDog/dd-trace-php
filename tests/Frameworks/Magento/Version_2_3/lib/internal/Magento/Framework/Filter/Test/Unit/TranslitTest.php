<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Filter\Test\Unit;

/**
 * Translit test.
 */
class TranslitTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Filter\Translit
     */
    protected $model;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(\Magento\Framework\Filter\Translit::class);
    }

    /**
     * @param string $testString
     * @param string $result
     * @param string $resultIconv
     * @param bool $isIconv
     * @dataProvider filterDataProvider
     */
    public function testFilter($testString, $result, $resultIconv, $isIconv)
    {
        if ($isIconv) {
            $this->assertEquals($resultIconv, $this->model->filter($testString));
        } else {
            $this->assertEquals($result, $this->model->filter($testString));
        }
    }

    /**
     * @return array
     */
    public function filterDataProvider()
    {
        $isIconv = '"libiconv"' == ICONV_IMPL;
        return [
            ['test', 'test', 'test', $isIconv],
            ['привет мир', 'privet mir', 'privet mir', $isIconv],
            [
                'Weiß, Goldmann, Göbel, Weiss, Göthe, Goethe und Götz',
                'Weiss, Goldmann, Gobel, Weiss, Gothe, Goethe und Gotz',
                'Weiss, Goldmann, Gobel, Weiss, Gothe, Goethe und Gotz',
                $isIconv
            ],
            [
                '❤ ☀ ☆ ☂ ☻ ♞ ☯ ☭ ☢ € → ☎ ❄ ♫ ✂ ▷ ✇ ♎ ⇧ ☮',
                '❤ ☀ ☆ ☂ ☻ ♞ ☯ ☭ ☢ € → ☎ ❄ ♫ ✂ ▷ ✇ ♎ ⇧ ☮',
                '         EUR ->         ',
                $isIconv
            ],
            ['™', 'tm', 'tm', $isIconv]
        ];
    }

    public function testFilterConfigured()
    {
        $config = $this->getMockBuilder(
            \Magento\Framework\App\Config\ScopeConfigInterface::class
        )->disableOriginalConstructor()->setMethods(
            ['getValue', 'setValue', 'isSetFlag']
        )->getMock();

        $config->expects(
            $this->once()
        )->method(
            'getValue'
        )->with(
            'url/convert',
            'default'
        )->willReturn(
            ['char8482' => ['from' => '™', 'to' => 'TM']]
        );

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(\Magento\Framework\Filter\Translit::class, ['config' => $config]);

        $this->assertEquals('TM', $this->model->filter('™'));
    }
}
