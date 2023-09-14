<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Test\Unit\Ui\Component\Listing;

use Magento\Customer\Model\Attribute;
use Magento\Customer\Ui\Component\ColumnFactory;
use Magento\Customer\Ui\Component\Listing\AttributeRepository;
use Magento\Customer\Ui\Component\Listing\Column\InlineEditUpdater;
use Magento\Customer\Ui\Component\Listing\Columns;
use Magento\Customer\Ui\Component\Listing\Filter\FilterConfigProviderInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Ui\Component\Listing\Columns\ColumnInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ColumnsTest extends TestCase
{
    /**
     * @var ContextInterface|MockObject
     */
    protected $context;

    /**
     * @var ColumnFactory|MockObject
     */
    protected $columnFactory;

    /**
     * @var AttributeRepository|MockObject
     */
    protected $attributeRepository;

    /**
     * @var Attribute|MockObject
     */
    protected $attribute;

    /**
     * @var ColumnInterface|MockObject
     */
    protected $column;

    /**
     * @var InlineEditUpdater|MockObject
     */
    protected $inlineEditUpdater;

    /**
     * @var Columns
     */
    protected $component;

    /**
     * @var FilterConfigProviderInterface|MockObject
     */
    private $textFilterConfigProvider;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->context = $this->getMockBuilder(ContextInterface::class)
            ->getMockForAbstractClass();
        $processor = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->context->expects($this->atLeastOnce())->method('getProcessor')->willReturn($processor);
        $this->columnFactory = $this->createPartialMock(
            ColumnFactory::class,
            ['create']
        );
        $this->attributeRepository = $this->createMock(
            AttributeRepository::class
        );
        $this->attribute = $this->createMock(Attribute::class);
        $this->column = $this->getMockForAbstractClass(
            ColumnInterface::class,
            [],
            '',
            false
        );

        $this->inlineEditUpdater = $this->getMockBuilder(
            InlineEditUpdater::class
        )->disableOriginalConstructor()
            ->getMock();

        $this->textFilterConfigProvider = $this->getMockForAbstractClass(FilterConfigProviderInterface::class);
        $this->textFilterConfigProvider->method('getConfig')
            ->willReturn(
                [
                    'conditionType' => 'like'
                ]
            );

        $this->component = new Columns(
            $this->context,
            $this->columnFactory,
            $this->attributeRepository,
            $this->inlineEditUpdater,
            [],
            [],
            [
                'text' => $this->textFilterConfigProvider
            ]
        );
    }

    /**
     * @return void
     */
    public function testPrepareWithAddColumn(): void
    {
        $attributeCode = 'attribute_code';

        $this->attributeRepository->expects($this->atLeastOnce())
            ->method('getList')
            ->willReturn(
                [
                    $attributeCode => [
                        'attribute_code' => 'billing_attribute_code',
                        'frontend_input' => 'frontend-input',
                        'frontend_label' => 'frontend-label',
                        'backend_type' => 'backend-type',
                        'options' => [
                            [
                                'label' => 'Label',
                                'value' => 'Value'
                            ]
                        ],
                        'is_used_in_grid' => true,
                        'is_visible_in_grid' => true,
                        'is_filterable_in_grid' => true,
                        'is_searchable_in_grid' => true,
                        'validation_rules' => [],
                        'required'=> false,
                        'entity_type_code' => 'customer_address'
                    ]
                ]
            );
        $this->columnFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->column);
        $this->column->expects($this->once())
            ->method('prepare');

        $this->component->prepare();
    }

    /**
     * @return void
     */
    public function testPrepareWithUpdateColumn(): void
    {
        $attributeCode = 'billing_attribute_code';
        $backendType = 'backend-type';
        $attributeData = [
            'attribute_code' => 'billing_attribute_code',
            'frontend_input' => 'text',
            'frontend_label' => 'frontend-label',
            'backend_type' => 'backend-type',
            'options' => [
                [
                    'label' => 'Label',
                    'value' => 'Value'
                ]
            ],
            'is_used_in_grid' => true,
            'is_visible_in_grid' => true,
            'is_filterable_in_grid' => true,
            'is_searchable_in_grid' => true,
            'validation_rules' => [],
            'required'=> false,
            'entity_type_code' => 'customer'
        ];

        $this->attributeRepository->expects($this->atLeastOnce())
            ->method('getList')
            ->willReturn([$attributeCode => $attributeData]);
        $this->columnFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->column);
        $this->column->expects($this->once())
            ->method('prepare');
        $this->column->expects($this->atLeastOnce())
            ->method('getData')
            ->with('config')
            ->willReturn([]);
        $this->column
            ->method('setData')
            ->withConsecutive(
                [
                    'config',
                    [
                        'options' => [
                            [
                                'label' => 'Label',
                                'value' => 'Value'
                            ]
                        ]
                    ]
                ],
                [
                    'config',
                    [
                        'name' => $attributeCode,
                        'dataType' => $backendType,
                        'filter' => [
                            'filterType' => 'text',
                            'conditionType' => 'like',
                        ],
                        'visible' => true
                    ]
                ]
            );

        $this->component->addColumn($attributeData, $attributeCode);
        $this->component->prepare();
    }

    /**
     * @return void
     */
    public function testPrepareWithUpdateStaticColumn(): void
    {
        $attributeCode = 'billing_attribute_code';
        $backendType = 'static';
        $attributeData = [
            'attribute_code' => 'billing_attribute_code',
            'frontend_input' => 'text',
            'frontend_label' => 'frontend-label',
            'backend_type' => $backendType,
            'options' => [
                [
                    'label' => 'Label',
                    'value' => 'Value'
                ]
            ],
            'is_used_in_grid' => true,
            'is_visible_in_grid' => true,
            'is_filterable_in_grid' => true,
            'is_searchable_in_grid' => true,
            'validation_rules' => [],
            'required'=> false,
            'entity_type_code' => 'customer'
        ];
        $this->inlineEditUpdater->expects($this->once())
            ->method('applyEditing')
            ->with($this->column, 'text', [], false);

        $this->attributeRepository->expects($this->atLeastOnce())
            ->method('getList')
            ->willReturn([$attributeCode => $attributeData]);
        $this->columnFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->column);
        $this->column->expects($this->once())
            ->method('prepare');
        $this->column->expects($this->atLeastOnce())
            ->method('getData')
            ->with('config')
            ->willReturn(['editor' => 'text']);
        $this->column
            ->method('setData')
            ->withConsecutive(
                [
                    'config',
                    [
                        'editor' => 'text',
                        'options' => [
                            [
                                'label' => 'Label',
                                'value' => 'Value'
                            ]
                        ]
                    ]
                ],
                [
                    'config',
                    [
                        'editor' => 'text',
                        'visible' => true
                    ]
                ]
            );

        $this->component->addColumn($attributeData, $attributeCode);
        $this->component->prepare();
    }
}
