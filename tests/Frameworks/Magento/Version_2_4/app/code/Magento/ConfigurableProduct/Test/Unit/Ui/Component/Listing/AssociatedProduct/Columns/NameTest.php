<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Test\Unit\Ui\Component\Listing\AssociatedProduct\Columns;

use Magento\ConfigurableProduct\Ui\Component\Listing\AssociatedProduct\Columns\Name as NameColumn;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor as UiElementProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NameTest extends TestCase
{
    /**
     * @var NameColumn
     */
    private $nameColumn;

    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var UrlInterface|MockObject
     */
    private $urlBuilderMock;

    /**
     * @var ContextInterface|MockObject
     */
    private $contextMock;

    /**
     * @var UiElementProcessor|MockObject
     */
    private $uiElementProcessorMock;

    protected function setUp(): void
    {
        $this->urlBuilderMock = $this->getMockBuilder(UrlInterface::class)
            ->getMockForAbstractClass();
        $this->contextMock = $this->getMockBuilder(ContextInterface::class)
            ->getMockForAbstractClass();
        $this->uiElementProcessorMock = $this->getMockBuilder(UiElementProcessor::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->contextMock->expects(static::never())
            ->method('getProcessor')
            ->willReturn($this->uiElementProcessorMock);

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->nameColumn = $this->objectManagerHelper->getObject(
            NameColumn::class,
            [
                'urlBuilder' => $this->urlBuilderMock,
                'context' => $this->contextMock
            ]
        );
    }

    public function testPrepareDataSource()
    {
        $fieldName = 'special_field';
        $dataSource = [
            'data' => [
                'items' => [
                    [
                        $fieldName => 'special_value1',
                        'entity_id' => null
                    ],
                    [
                        'entity_id' => '2'
                    ],
                    [
                        $fieldName => 'special_value3',
                        'entity_id' => '3'
                    ]
                ]
            ]
        ];
        $result = [
            'data' => [
                'items' => [
                    [
                        $fieldName => 'special_value1',
                        'entity_id' => null
                    ],
                    [
                        'entity_id' => '2'
                    ],
                    [
                        $fieldName => 'special_value3',
                        'entity_id' => '3',
                        'product_link' => '<a href="/catalog/product/edit/id/3" target="_blank">special_value3</a>'
                    ]
                ]
            ]
        ];

        $this->urlBuilderMock->expects(static::any())
            ->method('getUrl')
            ->willReturnMap(
                [
                    ['catalog/product/edit', ['id' => '3'], '/catalog/product/edit/id/3']
                ]
            );

        $this->nameColumn->setData('name', $fieldName);

        $this->assertSame($result, $this->nameColumn->prepareDataSource($dataSource));
    }
}
