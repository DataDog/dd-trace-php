<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Sales\Ui\Component\Listing\Column\ViewAction;

/**
 * Class ViewActionTest
 */
class ViewActionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ViewAction
     */
    protected $model;

    /**
     * @var UrlInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $urlBuilder;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->urlBuilder = $this->getMockForAbstractClass(\Magento\Framework\UrlInterface::class);
    }

    /**
     * @param array $data
     * @param array $dataSourceItems
     * @param array $expectedDataSourceItems
     * @param string $expectedUrlPath
     * @param array $expectedUrlParam
     * @dataProvider prepareDataSourceDataProvider
     */
    public function testPrepareDataSource(
        $data,
        $dataSourceItems,
        $expectedDataSourceItems,
        $expectedUrlPath,
        $expectedUrlParam
    ) {
        $contextMock = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponent\ContextInterface::class)
            ->getMockForAbstractClass();
        $processor = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponent\Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $contextMock->expects($this->never())->method('getProcessor')->willReturn($processor);
        $this->model = $this->objectManager->getObject(
            \Magento\Sales\Ui\Component\Listing\Column\ViewAction::class,
            [
                'urlBuilder' => $this->urlBuilder,
                'data' => $data,
                'context' => $contextMock,
            ]
        );

        $this->urlBuilder->expects($this->once())
            ->method('getUrl')
            ->with($expectedUrlPath, $expectedUrlParam)
            ->willReturn('url');

        $dataSource = [
            'data' => [
                'items' => $dataSourceItems
            ]
        ];
        $dataSource = $this->model->prepareDataSource($dataSource);
        $this->assertEquals($expectedDataSourceItems, $dataSource['data']['items']);
    }

    /**
     * Data provider for testPrepareDataSource
     * @return array
     */
    public function prepareDataSourceDataProvider()
    {
        return [
            [
                ['name' => 'itemName', 'config' => []],
                [['itemName' => '', 'entity_id' => 1]],
                [['itemName' => ['view' => ['href' => 'url', 'label' => __('View')]], 'entity_id' => 1]],
                '#',
                ['entity_id' => 1]
            ],
            [
                ['name' => 'itemName', 'config' => ['viewUrlPath' => 'url_path', 'urlEntityParamName' => 'order_id']],
                [['itemName' => '', 'entity_id' => 2]],
                [['itemName' => ['view' => ['href' => 'url', 'label' => __('View')]], 'entity_id' => 2]],
                'url_path',
                ['order_id' => 2]
            ]
        ];
    }
}
