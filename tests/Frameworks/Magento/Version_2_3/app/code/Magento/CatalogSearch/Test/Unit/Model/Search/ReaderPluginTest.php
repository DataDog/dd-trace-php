<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogSearch\Test\Unit\Model\Search;

class ReaderPluginTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\CatalogSearch\Model\Search\RequestGenerator|\PHPUnit\Framework\MockObject\MockObject */
    protected $requestGenerator;

    /** @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager  */
    protected $objectManagerHelper;

    /** @var \Magento\CatalogSearch\Model\Search\ReaderPlugin */
    protected $object;

    protected function setUp(): void
    {
        $this->requestGenerator = $this->getMockBuilder(\Magento\CatalogSearch\Model\Search\RequestGenerator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->object = $this->objectManagerHelper->getObject(
            \Magento\CatalogSearch\Model\Search\ReaderPlugin::class,
            ['requestGenerator' => $this->requestGenerator]
        );
    }

    public function testAfterRead()
    {
        $readerConfig = ['test' => 'b', 'd' => 'e'];
        $this->requestGenerator->expects($this->once())
            ->method('generate')
            ->willReturn(['test' => 'a']);

        $result = $this->object->afterRead(
            $this->getMockBuilder(\Magento\Framework\Config\ReaderInterface::class)
                ->disableOriginalConstructor()->getMock(),
            $readerConfig,
            null
        );

        $this->assertEquals(['test' => ['b', 'a'], 'd' => 'e'], $result);
    }
}
