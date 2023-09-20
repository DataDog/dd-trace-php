<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Category\Attribute\Source;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class LayoutTest extends \PHPUnit\Framework\TestCase
{
    private $testArray = ['test1', ['test1']];

    /**
     * @var \Magento\Catalog\Model\Category\Attribute\Source\Layout
     */
    private $model;

    public function testGetAllOptions()
    {
        $assertArray = $this->testArray;
        array_unshift($assertArray, ['value' => '', 'label' => __('No layout updates')]);
        $this->assertEquals($assertArray, $this->model->getAllOptions());
    }

    protected function setUp(): void
    {
        $helper = new ObjectManager($this);
        $this->model = $helper->getObject(
            \Magento\Catalog\Model\Category\Attribute\Source\Layout::class,
            [
                'pageLayoutBuilder' => $this->getMockedPageLayoutBuilder()
            ]
        );
    }

    /**
     * @return \Magento\Framework\View\Model\PageLayout\Config\BuilderInterface
     */
    private function getMockedPageLayoutBuilder()
    {
        $mockPageLayoutConfig = $this->getMockBuilder(\Magento\Framework\View\PageLayout\Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockPageLayoutConfig->expects($this->any())
            ->method('toOptionArray')
            ->willReturn($this->testArray);

        $mockPageLayoutBuilder = $this->getMockBuilder(
            \Magento\Framework\View\Model\PageLayout\Config\BuilderInterface::class
        )->disableOriginalConstructor()->getMock();
        $mockPageLayoutBuilder->expects($this->once())
            ->method('getPageLayoutsConfig')
            ->willReturn($mockPageLayoutConfig);

        return $mockPageLayoutBuilder;
    }
}
