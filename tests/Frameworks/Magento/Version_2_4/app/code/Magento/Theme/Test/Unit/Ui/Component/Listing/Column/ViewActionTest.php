<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Theme\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Theme\Ui\Component\Listing\Column\ViewAction;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Class ViewActionTest contains unit tests for \Magento\Theme\Ui\Component\Listing\Column\ViewAction class
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ViewActionTest extends TestCase
{
    /**
     * @var ViewAction
     */
    protected $model;

    /**
     * @var UrlInterface|MockObject
     */
    protected $urlBuilder;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * SetUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->urlBuilder = $this->getMockForAbstractClass(UrlInterface::class);
    }

    /**
     * @param array $data
     * @param array $dataSourceItems
     * @param array $expectedDataSourceItems
     * @param string $expectedUrlPath
     * @param array $expectedUrlParam
     *
     * @dataProvider getPrepareDataSourceDataProvider
     * @return void
     */
    public function testPrepareDataSource(
        $data,
        $dataSourceItems,
        $expectedDataSourceItems,
        $expectedUrlPath,
        $expectedUrlParam
    ) {
        $contextMock = $this->getMockBuilder(ContextInterface::class)
            ->getMockForAbstractClass();
        $processor = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $contextMock->expects($this->never())->method('getProcessor')->willReturn($processor);
        $this->model = $this->objectManager->getObject(
            ViewAction::class,
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
    public function getPrepareDataSourceDataProvider()
    {
        return [
            [
                [
                    'name' => 'itemName',
                    'config' => []
                ],
                [
                    ['itemName' => '', 'entity_id' => 1]
                ],
                [
                    [
                        'itemName' => [
                            'view' => [
                                'href' => 'url',
                                'label' => __('View'),
                            ]
                        ],
                        'entity_id' => 1
                    ]
                ],
                '#',
                ['id' => 1]
            ],
            [
                [
                    'name' => 'itemName',
                    'config' => [
                        'viewUrlPath' => 'url_path',
                        'urlEntityParamName' => 'theme_id',
                        'indexField' => 'theme_id'
                    ]
                ],
                [
                    ['itemName' => '', 'theme_id' => 2]
                ],
                [
                    [
                        'itemName' => [
                            'view' => [
                                'href' => 'url',
                                'label' => __('View'),
                            ]
                        ],
                        'theme_id' => 2
                    ]
                ],
                'url_path',
                ['theme_id' => 2]
            ]
        ];
    }
}
