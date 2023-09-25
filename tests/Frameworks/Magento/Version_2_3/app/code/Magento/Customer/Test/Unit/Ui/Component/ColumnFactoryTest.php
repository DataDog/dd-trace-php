<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Ui\Component;

use Magento\Customer\Ui\Component\ColumnFactory;

/**
 * Test ColumnFactory Class
 */
class ColumnFactoryTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Customer\Api\Data\OptionInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $attributeOption;

    /** @var \Magento\Framework\View\Element\UiComponent\ContextInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $context;

    /** @var \Magento\Framework\View\Element\UiComponentFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $componentFactory;

    /** @var \Magento\Customer\Api\Data\AttributeMetadataInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $attributeMetadata;

    /** @var \Magento\Ui\Component\Listing\Columns\ColumnInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $column;

    /** @var \Magento\Customer\Ui\Component\Listing\Column\InlineEditUpdater|\PHPUnit\Framework\MockObject\MockObject */
    protected $inlineEditUpdater;

    /** @var ColumnFactory */
    protected $columnFactory;

    protected function setUp(): void
    {
        $this->context = $this->getMockForAbstractClass(
            \Magento\Framework\View\Element\UiComponent\ContextInterface::class,
            [],
            '',
            false
        );
        $this->componentFactory = $this->createPartialMock(
            \Magento\Framework\View\Element\UiComponentFactory::class,
            ['create']
        );
        $this->attributeMetadata = $this->getMockForAbstractClass(
            \Magento\Customer\Api\Data\AttributeMetadataInterface::class,
            [],
            '',
            false
        );
        $this->column = $this->getMockForAbstractClass(
            \Magento\Ui\Component\Listing\Columns\ColumnInterface::class,
            [],
            '',
            false
        );
        $this->attributeOption = $this->getMockForAbstractClass(
            \Magento\Customer\Api\Data\OptionInterface::class,
            [],
            '',
            false
        );

        $this->inlineEditUpdater = $this->getMockBuilder(
            \Magento\Customer\Ui\Component\Listing\Column\InlineEditUpdater::class
        )
            ->disableOriginalConstructor()
            ->getMock();

        $this->columnFactory = new ColumnFactory($this->componentFactory, $this->inlineEditUpdater);
    }

    public function testCreate()
    {
        $columnName = 'created_at';
        $config = [
            'data' => [
                'js_config' => [
                    'component' => 'Magento_Ui/js/grid/columns/column',
                ],
                'config' => [
                    'label' => __('Label'),
                    'dataType' => 'text',
                    'align' => 'left',
                    'visible' => true,
                    'options' =>  [
                        [
                            'label' => 'Label',
                            'value' => 'Value'
                        ]
                    ],
                    'component' => 'Magento_Ui/js/grid/columns/column',
                    '__disableTmpl' => 'true'
                ],
            ],
            'context' => $this->context,
        ];
        $attributeData = [
            'attribute_code' => 'billing_attribute_code',
            'frontend_input' => 'text',
            'frontend_label' => 'Label',
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
            'entity_type_code' => 'customer',
            'validation_rules' => [],
            'required' => false,
        ];
        $this->inlineEditUpdater->expects($this->once())
            ->method('applyEditing')
            ->with($this->column, 'text', []);
        $this->componentFactory->expects($this->once())
            ->method('create')
            ->with($columnName, 'column', $config)
            ->willReturn($this->column);

        $this->assertSame(
            $this->column,
            $this->columnFactory->create($attributeData, $columnName, $this->context)
        );
    }
}
