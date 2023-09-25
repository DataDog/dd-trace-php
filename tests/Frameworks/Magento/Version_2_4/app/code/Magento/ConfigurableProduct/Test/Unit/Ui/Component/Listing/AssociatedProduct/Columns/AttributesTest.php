<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Test\Unit\Ui\Component\Listing\AssociatedProduct\Columns;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\Data\ProductAttributeSearchResultsInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\ConfigurableProduct\Ui\Component\Listing\AssociatedProduct\Columns\Attributes as AttributesColumn;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor as UiElementProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AttributesTest extends TestCase
{
    /**
     * @var AttributesColumn
     */
    private $attributesColumn;

    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var ContextInterface|MockObject
     */
    private $contextMock;

    /**
     * @var ProductAttributeRepositoryInterface|MockObject
     */
    private $attributeRepositoryMock;

    /**
     * @var SearchCriteriaBuilder|MockObject
     */
    private $searchCriteriaBuilderMock;

    /**
     * @var UiElementProcessor|MockObject
     */
    private $uiElementProcessorMock;

    /**
     * @var SearchCriteria|MockObject
     */
    private $searchCriteriaMock;

    /**
     * @var ProductAttributeSearchResultsInterface|MockObject
     */
    private $searchResultsMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->getMockBuilder(ContextInterface::class)
            ->getMockForAbstractClass();
        $this->attributeRepositoryMock = $this->getMockBuilder(ProductAttributeRepositoryInterface::class)
            ->getMockForAbstractClass();
        $this->searchCriteriaBuilderMock = $this->getMockBuilder(SearchCriteriaBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->uiElementProcessorMock = $this->getMockBuilder(UiElementProcessor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->searchCriteriaMock = $this->getMockBuilder(SearchCriteria::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->searchResultsMock = $this->getMockBuilder(ProductAttributeSearchResultsInterface::class)
            ->getMockForAbstractClass();

        $this->contextMock->expects(static::never())
            ->method('getProcessor')
            ->willReturn($this->uiElementProcessorMock);
        $this->searchCriteriaBuilderMock->expects(static::any())
            ->method('addFilter')
            ->willReturnSelf();
        $this->searchCriteriaBuilderMock->expects(static::any())
            ->method('create')
            ->willReturn($this->searchCriteriaMock);

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->attributesColumn = $this->objectManagerHelper->getObject(
            AttributesColumn::class,
            [
                'context' => $this->contextMock,
                'attributeRepository' => $this->attributeRepositoryMock,
                'searchCriteriaBuilder' => $this->searchCriteriaBuilderMock
            ]
        );
    }

    public function testPrepareDataSource()
    {
        $name = 'some_name';
        $initialData = [
            'data' => [
                'totalRecords' => 4,
                'items' => [
                    ['attribute1_1_code' => 'attribute1_1_option2', 'required_options' => '0'],
                    ['attribute2_1_code' => 'attribute2_1_option3', 'required_options' => '0'],
                    ['attribute3_1_code' => 'attribute3_1_option3', 'attribute3_2_code' => 'attribute3_2_option1',
                        'required_options' => '0'],
                    ['attribute4_1_code' => 'attribute4_1_option1', 'required_options' => '1']
                ]
            ]
        ];
        $attributes = [
            $this->createAttributeMock(
                'attribute1_1_code',
                'attribute1_1_label',
                [
                    $this->createAttributeOptionMock('attribute1_1_option1', 'attribute1_1_option1_label'),
                    $this->createAttributeOptionMock('attribute1_1_option2', 'attribute1_1_option2_label')
                ]
            ),
            $this->createAttributeMock(
                'attribute2_1_code',
                'attribute2_1_label',
                [
                    $this->createAttributeOptionMock('attribute2_1_option1', 'attribute2_1_option1_label'),
                    $this->createAttributeOptionMock('attribute2_1_option2', 'attribute2_1_option2_label')
                ]
            ),
            $this->createAttributeMock(
                'attribute3_1_code',
                'attribute3_1_label',
                [
                    $this->createAttributeOptionMock('attribute3_1_option1', 'attribute3_1_option1_label'),
                    $this->createAttributeOptionMock('attribute3_1_option2', 'attribute3_1_option2_label'),
                    $this->createAttributeOptionMock('attribute3_1_option3', 'attribute3_1_option3_label')
                ]
            ),
            $this->createAttributeMock(
                'attribute3_2_code',
                'attribute3_2_label',
                [
                    $this->createAttributeOptionMock('attribute3_2_option1', 'attribute3_2_option1_label'),
                    $this->createAttributeOptionMock('attribute3_2_option2', 'attribute3_2_option2_label'),
                    $this->createAttributeOptionMock('attribute3_2_option3', 'attribute3_2_option3_label')
                ]
            ),
            $this->createAttributeMock(
                'attribute4_1_code',
                'attribute4_1_label'
            )
        ];
        $resultData = [
            'data' => [
                'totalRecords' => 3,
                'items' => [
                    [
                        'attribute1_1_code' => 'attribute1_1_option2',
                        'required_options' => '0',
                        $name => 'attribute1_1_label: attribute1_1_option2_label'
                    ],
                    [
                        'attribute2_1_code' => 'attribute2_1_option3',
                        'required_options' => '0',
                        $name => ''
                    ],
                    [
                        'attribute3_1_code' => 'attribute3_1_option3',
                        'attribute3_2_code' => 'attribute3_2_option1',
                        'required_options' => '0',
                        $name => 'attribute3_1_label: attribute3_1_option3_label,'
                            . ' attribute3_2_label: attribute3_2_option1_label'
                    ]
                ]
            ]
        ];

        $this->attributesColumn->setData('name', $name);

        $this->attributeRepositoryMock->expects(static::any())
            ->method('getList')
            ->with($this->searchCriteriaMock)
            ->willReturn($this->searchResultsMock);
        $this->searchResultsMock->expects(static::any())
            ->method('getItems')
            ->willReturn($attributes);

        $actualResultItems = $this->attributesColumn->prepareDataSource($initialData);
        $this->assertSame($resultData['data']['items'], $actualResultItems['data']['items']);
        $this->assertSame($resultData['data']['totalRecords'], count($actualResultItems['data']['items']));
    }

    /**
     * Create product attribute mock object
     *
     * @param string $attributeCode
     * @param string $defaultFrontendLabel
     * @param array $options
     * @return ProductAttributeInterface|MockObject
     */
    private function createAttributeMock($attributeCode, $defaultFrontendLabel, array $options = [])
    {
        $attributeMock = $this->getMockBuilder(ProductAttributeInterface::class)
            ->getMockForAbstractClass();

        $attributeMock->expects(static::any())
            ->method('getAttributeCode')
            ->willReturn($attributeCode);
        $attributeMock->expects(static::any())
            ->method('getDefaultFrontendLabel')
            ->willReturn($defaultFrontendLabel);
        $attributeMock->expects(static::any())
            ->method('getOptions')
            ->willReturn($options);

        return $attributeMock;
    }

    /**
     * Create attribute option mock object
     *
     * @param string $value
     * @param string $label
     * @return AttributeOptionInterface|MockObject
     */
    private function createAttributeOptionMock($value, $label)
    {
        $attributeOptionMock = $this->getMockBuilder(AttributeOptionInterface::class)
            ->getMockForAbstractClass();

        $attributeOptionMock->expects(static::any())
            ->method('getValue')
            ->willReturn($value);
        $attributeOptionMock->expects(static::any())
            ->method('getLabel')
            ->willReturn($label);

        return $attributeOptionMock;
    }
}
