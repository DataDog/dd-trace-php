<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\Asset\PreProcessor;

use Magento\Framework\View\Asset\PreProcessor\Chain;

/**
 * Class ChainTest
 *
 * @package Magento\Framework\View\Asset\PreProcessor
 */
class ChainTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Asset\LocalInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $asset;

    /**
     * @var Chain
     */
    private $object;

    protected function setUp(): void
    {
        $this->asset = $this->getMockForAbstractClass(\Magento\Framework\View\Asset\LocalInterface::class);
        $this->asset->expects($this->once())->method('getContentType')->willReturn('assetType');
        $this->object = new Chain($this->asset, 'origContent', 'origType', 'origPath');
    }

    public function testGetAsset()
    {
        $this->assertSame($this->asset, $this->object->getAsset());
    }

    public function testGettersAndSetters()
    {
        $this->assertEquals('origType', $this->object->getOrigContentType());
        $this->assertEquals('origType', $this->object->getContentType());
        $this->assertEquals('origContent', $this->object->getOrigContent());
        $this->assertEquals('origContent', $this->object->getContent());
        $this->assertEquals('assetType', $this->object->getTargetContentType());

        $this->object->setContent('anotherContent');
        $this->object->setContentType('anotherType');

        $this->assertEquals('origType', $this->object->getOrigContentType());
        $this->assertEquals('anotherType', $this->object->getContentType());
        $this->assertEquals('origContent', $this->object->getOrigContent());
        $this->assertEquals('anotherContent', $this->object->getContent());
        $this->assertEquals('assetType', $this->object->getTargetContentType());
    }

    /**
     */
    public function testAssertValid()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The requested asset type was \'assetType\', but ended up with \'type\'');

        $this->object->setContentType('type');
        $this->object->assertValid();
    }

    /**
     * @param string $content
     * @param string $type
     * @param bool $expected
     * @dataProvider isChangedDataProvider
     */
    public function testIsChanged($content, $type, $expected)
    {
        $this->object->setContent($content);
        $this->object->setContentType($type);
        $this->assertEquals($expected, $this->object->isChanged());
    }

    /**
     * @return array
     */
    public function isChangedDataProvider()
    {
        return [
            ['origContent', 'origType', false],
            ['anotherContent', 'origType', true],
            ['origContent', 'anotherType', true],
            ['anotherContent', 'anotherType', true],
        ];
    }
}
