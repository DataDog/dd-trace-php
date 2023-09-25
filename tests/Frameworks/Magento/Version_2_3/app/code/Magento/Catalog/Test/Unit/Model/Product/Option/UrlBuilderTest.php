<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Product\Option;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class UrlBuilderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Product\Option\UrlBuilder
     */
    private $model;

    public function testGetUrl()
    {
        $this->assertEquals('testResult', $this->model->getUrl('router', []));
    }

    protected function setUp(): void
    {
        $mockedFrontendUrlBuilder = $this->getMockedFrontendUrlBuilder();
        $helper = new ObjectManager($this);
        $this->model = $helper->getObject(
            \Magento\Catalog\Model\Product\Option\UrlBuilder::class,
            ['frontendUrlBuilder' => $mockedFrontendUrlBuilder]
        );
    }

    /**
     * @return \Magento\Framework\UrlInterface
     */
    private function getMockedFrontendUrlBuilder()
    {
        $mockBuilder = $this->getMockBuilder(\Magento\Framework\UrlInterface::class)
            ->disableOriginalConstructor();
        $mock = $mockBuilder->getMockForAbstractClass();

        $mock->expects($this->any())
            ->method('getUrl')
            ->willReturn('testResult');

        return $mock;
    }
}
