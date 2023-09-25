<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Ui\Test\Unit\Model\Export;

use Magento\Framework\Api\Search\DocumentInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\Listing\Columns;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Ui\Model\Export\MetadataProvider;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MetadataProviderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var MetadataProvider
     */
    private $model;

    /**
     * @var Filter | \PHPUnit\Framework\MockObject\MockObject
     */
    private $filter;

    /**
     * @var TimezoneInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $localeDate;

    /**
     * @var ResolverInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $localeResolver;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->filter = $this->getMockBuilder(\Magento\Ui\Component\MassAction\Filter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->localeDate = $this->getMockBuilder(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->localeResolver = $this->getMockBuilder(\Magento\Framework\Locale\ResolverInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->localeResolver->expects($this->any())
            ->method('getLocale')
            ->willReturn(null);

        $objectManager = new ObjectManager($this);
        $this->model = $objectManager->getObject(
            \Magento\Ui\Model\Export\MetadataProvider::class,
            [
                'filter' => $this->filter,
                'localeDate' => $this->localeDate,
                'localeResolver' => $this->localeResolver,
                'data' => ['component_name' => ['field']],
            ]
        );
    }

    /**
     * @param array $columnLabels
     * @param array $expected
     * @return void
     * @dataProvider getColumnsDataProvider
     */
    public function testGetHeaders(array $columnLabels, array $expected): void
    {
        $componentName = 'component_name';
        $columnName = 'column_name';

        $component = $this->prepareColumns($componentName, $columnName, $columnLabels[0]);
        $result = $this->model->getHeaders($component);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function getColumnsDataProvider(): array
    {
        return [
            [['ID'],['ID']],
            [['Name'],['Name']],
            [['Id'],['Id']],
            [['id'],['id']],
            [['IDTEST'],['IDTEST']],
            [['ID TEST'],['ID TEST']],
        ];
    }

    public function testGetFields()
    {
        $componentName = 'component_name';
        $columnName = 'column_name';
        $columnLabel = 'column_label';

        $component = $this->prepareColumns($componentName, $columnName, $columnLabel);

        $result = $this->model->getFields($component);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals($columnName, $result[0]);
    }

    /**
     * @param string $componentName
     * @param string $columnName
     * @param string $columnLabel
     * @param string $columnActionsName
     * @param string $columnActionsLabel
     * @return UiComponentInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected function prepareColumns(
        $componentName,
        $columnName,
        $columnLabel,
        $columnActionsName = 'actions_name',
        $columnActionsLabel = 'actions_label'
    ) {
        /** @var UiComponentInterface|\PHPUnit\Framework\MockObject\MockObject $component */
        $component = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponentInterface::class)
            ->getMockForAbstractClass();

        /** @var Columns|\PHPUnit\Framework\MockObject\MockObject $columns */
        $columns = $this->getMockBuilder(\Magento\Ui\Component\Listing\Columns::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var Column|\PHPUnit\Framework\MockObject\MockObject $column */
        $column = $this->getMockBuilder(\Magento\Ui\Component\Listing\Columns\Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Column|\PHPUnit\Framework\MockObject\MockObject $columnActions */
        $columnActions = $this->getMockBuilder(\Magento\Ui\Component\Listing\Columns\Column::class)
            ->disableOriginalConstructor()
            ->getMock();

        $component->expects($this->any())
            ->method('getName')
            ->willReturn($componentName);
        $component->expects($this->once())
            ->method('getChildComponents')
            ->willReturn([$columns]);

        $columns->expects($this->once())
            ->method('getChildComponents')
            ->willReturn([$column, $columnActions]);

        $column->expects($this->any())
            ->method('getName')
            ->willReturn($columnName);
        $column->expects($this->any())
            ->method('getData')
            ->willReturnMap(
                [
                    ['config/label', null, $columnLabel],
                    ['config/dataType', null, 'data_type'],
                ]
            );

        $columnActions->expects($this->any())
            ->method('getName')
            ->willReturn($columnActionsName);
        $columnActions->expects($this->any())
            ->method('getData')
            ->willReturnMap(
                [
                    ['config/label', null, $columnActionsLabel],
                    ['config/dataType', null, 'actions'],
                ]
            );

        return $component;
    }

    /**
     * @param string $key
     * @param array $fields
     * @param array $options
     * @param array $expected
     * @dataProvider getRowDataProvider
     */
    public function testGetRowData($key, $fields, $options, $expected)
    {
        /** @var DocumentInterface|\PHPUnit\Framework\MockObject\MockObject $document */
        $document = $this->getMockBuilder(\Magento\Framework\Api\Search\DocumentInterface::class)
            ->getMockForAbstractClass();

        $attribute = $this->getMockBuilder(\Magento\Framework\Api\AttributeInterface::class)
            ->getMockForAbstractClass();

        $document->expects($this->once())
            ->method('getCustomAttribute')
            ->with($fields[0])
            ->willReturn($attribute);

        $attribute->expects($this->once())
            ->method('getValue')
            ->willReturn($key);

        $result = $this->model->getRowData($document, $fields, $options);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function getRowDataProvider()
    {
        return [
            [
                'key' => 'key_1',
                'fields' => ['column'],
                'options' => [
                    'column' => [
                        'key_1' => 'value_1',
                    ],
                ],
                'expected' => [
                    'value_1',
                ],
            ],
            [
                'key' => 'key_2',
                'fields' => ['column'],
                'options' => [
                    'column' => [
                        'key_1' => 'value_1',
                    ],
                ],
                'expected' => [
                    '',
                ],
            ],
            [
                'key' => 'key_1',
                'fields' => ['column'],
                'options' => [],
                'expected' => [
                    'key_1',
                ],
            ],
        ];
    }

    /**
     * @param string $filter
     * @param array $options
     * @param array $expected
     * @dataProvider getOptionsDataProvider
     */
    public function testGetOptions($filter, $options, $expected)
    {
        $component = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponentInterface::class)
            ->getMockForAbstractClass();

        $childComponent = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponentInterface::class)
            ->getMockForAbstractClass();

        $filters = $this->getMockBuilder(\Magento\Ui\Component\Filters::class)
            ->disableOriginalConstructor()
            ->getMock();

        $select = $this->getMockBuilder(\Magento\Ui\Component\Filters\Type\Select::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->filter->expects($this->once())
            ->method('getComponent')
            ->willReturn($component);

        $component->expects($this->once())
            ->method('getChildComponents')
            ->willReturn(['listing_top' => $childComponent]);

        $childComponent->expects($this->once())
            ->method('getChildComponents')
            ->willReturn([$filters]);

        $filters->expects($this->once())
            ->method('getChildComponents')
            ->willReturn([$select]);

        $select->expects($this->any())
            ->method('getName')
            ->willReturn($filter);
        $select->expects($this->any())
            ->method('getData')
            ->with('config/options')
            ->willReturn($options);

        $result = $this->model->getOptions();
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function getOptionsDataProvider()
    {
        return [
            [
                'filter' => 'filter_name',
                'options' => [
                    [
                        'value' => 'value_1',
                        'label' => 'label_1',
                    ]
                ],
                'expected' => [
                    'filter_name' => [
                        'value_1' => 'label_1',
                    ],
                ],
            ],
            [
                'filter' => 'filter_name',
                'options' => [
                    [
                        'value' => [
                            [
                                'value' => 'value_2',
                                'label' => 'label_2',
                            ],
                        ],
                        'label' => 'label_1',
                    ]
                ],
                'expected' => [
                    'filter_name' => [
                        'value_2' => 'label_1label_2',
                    ],
                ],
            ],
            [
                'filter' => 'filter_name',
                'options' => [
                    [
                        'value' => [
                            [
                                'value' => [
                                    [
                                        'value' => 'value_3',
                                        'label' => 'label_3',
                                    ]
                                ],
                                'label' => 'label_2',
                            ],
                        ],
                        'label' => 'label_1',
                    ]
                ],
                'expected' => [
                    'filter_name' => [
                        'value_3' => 'label_1label_2label_3',
                    ],
                ],
            ],
        ];
    }

    /**
     * Test for convertDate function
     *
     * @param string $fieldValue
     * @param string $expected
     * @dataProvider convertDateProvider
     * @covers       \Magento\Ui\Model\Export\MetadataProvider::convertDate()
     */
    public function testConvertDate($fieldValue, $expected)
    {
        $componentName = 'component_name';
        /** @var DocumentInterface|\PHPUnit\Framework\MockObject\MockObject $document */
        $document = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->getMock();

        $document->expects($this->once())
            ->method('getData')
            ->with('field')
            ->willReturn($fieldValue);

        $this->localeDate->expects($this->once())
            ->method('date')
            ->willReturn(new \DateTime($fieldValue, new \DateTimeZone('UTC')));

        $document->expects($this->once())
            ->method('setData')
            ->with('field', $expected);

        $this->model->convertDate($document, $componentName);
    }

    /**
     * Data provider for testConvertDate
     *
     * @return array
     */
    public function convertDateProvider()
    {
        return [
            [
                'fieldValue' => '@1534505233',
                'expected' => 'Aug 17, 2018 11:27:13 AM',
            ],
            [
                'fieldValue' => '@1534530000',
                'expected' => 'Aug 17, 2018 06:20:00 PM',
            ],
        ];
    }
}
