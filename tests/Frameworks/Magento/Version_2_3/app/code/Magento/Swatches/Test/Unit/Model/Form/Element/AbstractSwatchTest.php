<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Swatches\Test\Unit\Model\Form\Element;

class AbstractSwatchTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Swatches\Model\Form\Element\AbstractSwatch|\PHPUnit\Framework\MockObject\MockObject */
    private $swatch;

    /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute|\PHPUnit\Framework\MockObject\MockObject */
    private $attribute;

    /** @var \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource|\PHPUnit\Framework\MockObject\MockObject */
    private $source;

    protected function setUp(): void
    {
        $this->source = $this->getMockBuilder(\Magento\Eav\Model\Entity\Attribute\Source\AbstractSource::class)
            ->getMockForAbstractClass();

        $this->attribute = $this->getMockBuilder(\Magento\Catalog\Model\ResourceModel\Eav\Attribute::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->swatch = $this->getMockBuilder(\Magento\Swatches\Model\Form\Element\AbstractSwatch::class)
            ->disableOriginalConstructor()
            ->setMethods(['getData'])
            ->getMockForAbstractClass();
    }

    public function testGetValues()
    {
        $expected = [1, 2, 3];

        $this->source->expects($this->once())->method('getAllOptions')
            ->with(true, true)
            ->willReturn($expected);
        $this->attribute->expects($this->once())->method('getSource')
            ->willReturn($this->source);
        $this->swatch->expects($this->once())->method('getData')
            ->with('entity_attribute')
            ->willReturn($this->attribute);

        $method = new \ReflectionMethod(\Magento\Swatches\Model\Form\Element\AbstractSwatch::class, 'getValues');
        $method->setAccessible(true);

        $this->assertEquals($expected, $method->invoke($this->swatch));
    }

    public function testGetValuesEmpty()
    {
        $this->swatch->expects($this->once())->method('getData')
            ->with('entity_attribute')
            ->willReturn(null);

        $method = new \ReflectionMethod(\Magento\Swatches\Model\Form\Element\AbstractSwatch::class, 'getValues');
        $method->setAccessible(true);

        $this->assertEmpty($method->invoke($this->swatch));
    }
}
