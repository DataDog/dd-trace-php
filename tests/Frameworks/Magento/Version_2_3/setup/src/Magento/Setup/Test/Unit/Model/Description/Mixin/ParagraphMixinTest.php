<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Setup\Test\Unit\Model\Description\Mixin;

class ParagraphMixinTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Setup\Model\Description\Mixin\ParagraphMixin
     */
    private $mixin;

    protected function setUp(): void
    {
        $this->mixin = new \Magento\Setup\Model\Description\Mixin\ParagraphMixin();
    }

    /**
     * @dataProvider getTestData
     */
    public function testApply($subject, $expectedResult)
    {
        $this->assertEquals($expectedResult, $this->mixin->apply($subject));
    }

    /**
     * @return array
     */
    public function getTestData()
    {
        return [
            ['', '<p></p>'],
            [
                'Lorem ipsum dolor sit amet.' . PHP_EOL
                . 'Consectetur adipiscing elit.' . PHP_EOL
                . 'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',

                '<p>Lorem ipsum dolor sit amet.</p>' . PHP_EOL
                . '<p>Consectetur adipiscing elit.</p>' . PHP_EOL
                . '<p>Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>'
            ]
        ];
    }
}
