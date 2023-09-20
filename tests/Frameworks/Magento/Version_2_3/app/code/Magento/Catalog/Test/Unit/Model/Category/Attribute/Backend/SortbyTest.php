<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Test\Unit\Model\Category\Attribute\Backend;

class SortbyTest extends \PHPUnit\Framework\TestCase
{
    const DEFAULT_ATTRIBUTE_CODE = 'attribute_name';

    /**
     * @var \Magento\Catalog\Model\Category\Attribute\Backend\Sortby
     */
    protected $_model;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $_objectHelper;

    /**
     * @var \Magento\Eav\Model\Entity\Attribute\AbstractAttribute
     */
    protected $_attribute;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    protected function setUp(): void
    {
        $this->markTestSkipped('Due to MAGETWO-48956');
        $this->_objectHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->_model = $this->_objectHelper->getObject(
            \Magento\Catalog\Model\Category\Attribute\Backend\Sortby::class,
            ['scopeConfig' => $this->_scopeConfig]
        );
        $this->_attribute = $this->createPartialMock(
            \Magento\Eav\Model\Entity\Attribute\AbstractAttribute::class,
            [
                'getName',
                '__call',
                'isValueEmpty',
                'getEntity',
                'getFrontend',
                '__wakeup',
                'getIsRequired',
                'getIsUnique'
            ]
        );

        $this->_model->setAttribute($this->_attribute);
    }

    /**
     * @param $attributeCode
     * @param $data
     * @param $expected
     * @dataProvider beforeSaveDataProvider
     */
    public function testBeforeSave($attributeCode, $data, $expected)
    {
        $this->_attribute->expects($this->any())->method('getName')->willReturn($attributeCode);
        $object = new \Magento\Framework\DataObject($data);
        $this->_model->beforeSave($object);
        $this->assertTrue($object->hasData($attributeCode));
        $this->assertSame($expected, $object->getData($attributeCode));
    }

    /**
     * @return array
     */
    public function beforeSaveDataProvider()
    {
        return [
            'attribute with specified value' => [
                self::DEFAULT_ATTRIBUTE_CODE,
                [self::DEFAULT_ATTRIBUTE_CODE => 'test_value'],
                'test_value',
            ],
            'attribute with default value' => [
                self::DEFAULT_ATTRIBUTE_CODE,
                [self::DEFAULT_ATTRIBUTE_CODE => null],
                null,
            ],
            'attribute does not exist' => [
                self::DEFAULT_ATTRIBUTE_CODE,
                [],
                null,
            ],
            'attribute sort by empty' => [
                'available_sort_by',
                ['available_sort_by' => null],
                null,
            ],
            'attribute sort by' => [
                'available_sort_by',
                ['available_sort_by' => ['test', 'value']],
                'test,value',
            ]
        ];
    }

    /**
     * @param $attributeCode
     * @param $data
     * @param $expected
     * @dataProvider afterLoadDataProvider
     */
    public function testAfterLoad($attributeCode, $data, $expected)
    {
        $this->_attribute->expects($this->any())->method('getName')->willReturn($attributeCode);
        $object = new \Magento\Framework\DataObject($data);
        $this->_model->afterLoad($object);
        $this->assertTrue($object->hasData($attributeCode));
        $this->assertSame($expected, $object->getData($attributeCode));
    }

    /**
     * @return array
     */
    public function afterLoadDataProvider()
    {
        return [
            'attribute with specified value' => [
                self::DEFAULT_ATTRIBUTE_CODE,
                [self::DEFAULT_ATTRIBUTE_CODE => 'test_value'],
                'test_value',
            ],
            'attribute sort by empty' => [
                'available_sort_by',
                ['available_sort_by' => null],
                null,
            ],
            'attribute sort by' => [
                'available_sort_by',
                ['available_sort_by' => 'test,value'],
                ['test', 'value'],
            ]
        ];
    }

    /**
     * @param $attributeData
     * @param $data
     * @param $expected
     * @dataProvider validateDataProvider
     */
    public function testValidate($attributeData, $data, $expected)
    {
        $this->_attribute->expects($this->any())->method('getName')->willReturn($attributeData['code']);
        $this->_attribute
            ->expects($this->at(1))
            ->method('getIsRequired')
            ->willReturn($attributeData['isRequired']);
        $this->_attribute
            ->expects($this->any())
            ->method('isValueEmpty')
            ->willReturn($attributeData['isValueEmpty']);
        $object = new \Magento\Framework\DataObject($data);
        $this->assertSame($expected, $this->_model->validate($object));
    }

    /**
     * @return array
     */
    public function validateDataProvider()
    {
        return [
            'is not required' => [
                ['code' => self::DEFAULT_ATTRIBUTE_CODE, 'isRequired' => false, 'isValueEmpty' => false],
                [],
                true,
            ],
            'required, empty, not use config case 1' => [
                ['code' => self::DEFAULT_ATTRIBUTE_CODE, 'isRequired' => true, 'isValueEmpty' => true],
                [self::DEFAULT_ATTRIBUTE_CODE => [], 'use_post_data_config' => []],
                false,
            ],
            'required, empty, not use config case 2' => [
                ['code' => self::DEFAULT_ATTRIBUTE_CODE, 'isRequired' => true, 'isValueEmpty' => true],
                [self::DEFAULT_ATTRIBUTE_CODE => [], 'use_post_data_config' => ['config']],
                false,
            ],
            'required, empty, use config' => [
                ['code' => self::DEFAULT_ATTRIBUTE_CODE, 'isRequired' => true, 'isValueEmpty' => true],
                [self::DEFAULT_ATTRIBUTE_CODE => [], 'use_post_data_config' => [self::DEFAULT_ATTRIBUTE_CODE]],
                true,
            ],
        ];
    }

    public function testValidateUnique()
    {
        $this->_attribute->expects($this->any())->method('getName')->willReturn('attribute_name');
        $this->_attribute->expects($this->at(1))->method('getIsRequired');
        $this->_attribute->expects($this->at(2))->method('getIsUnique')->willReturn(true);

        $entityMock = $this->getMockForAbstractClass(
            \Magento\Eav\Model\Entity\AbstractEntity::class,
            [],
            '',
            false,
            true,
            true,
            ['checkAttributeUniqueValue']
        );
        $this->_attribute->expects($this->any())->method('getEntity')->willReturn($entityMock);
        $entityMock->expects($this->at(0))->method('checkAttributeUniqueValue')->willReturn(true);
        $this->assertTrue($this->_model->validate(new \Magento\Framework\DataObject()));
    }

    /**
     */
    public function testValidateUniqueException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->_attribute->expects($this->any())->method('getName')->willReturn('attribute_name');
        $this->_attribute->expects($this->at(1))->method('getIsRequired');
        $this->_attribute->expects($this->at(2))->method('getIsUnique')->willReturn(true);

        $entityMock = $this->getMockForAbstractClass(
            \Magento\Eav\Model\Entity\AbstractEntity::class,
            [],
            '',
            false,
            true,
            true,
            ['checkAttributeUniqueValue']
        );
        $frontMock = $this->getMockForAbstractClass(
            \Magento\Eav\Model\Entity\Attribute\Frontend\AbstractFrontend::class,
            [],
            '',
            false,
            true,
            true,
            ['getLabel']
        );
        $this->_attribute->expects($this->any())->method('getEntity')->willReturn($entityMock);
        $this->_attribute->expects($this->any())->method('getFrontend')->willReturn($frontMock);
        $entityMock->expects($this->at(0))->method('checkAttributeUniqueValue')->willReturn(false);
        $this->assertTrue($this->_model->validate(new \Magento\Framework\DataObject()));
    }

    /**
     * @param $attributeCode
     * @param $data
     * @dataProvider validateDefaultSortDataProvider
     */
    public function testValidateDefaultSort($attributeCode, $data)
    {
        $this->_attribute->expects($this->any())->method('getName')->willReturn($attributeCode);
        $this->_scopeConfig->expects($this->any())->method('getValue')->willReturn('value2');
        $object = new \Magento\Framework\DataObject($data);
        $this->assertTrue($this->_model->validate($object));
    }

    /**
     * @return array
     */
    public function validateDefaultSortDataProvider()
    {
        return [
            [
                'default_sort_by',
                [
                    'available_sort_by' => ['value1', 'value2'],
                    'default_sort_by' => 'value2',
                    'use_post_data_config' => []
                ],
            ],
            [
                'default_sort_by',
                [
                    'available_sort_by' => 'value1,value2',
                    'use_post_data_config' => ['default_sort_by']
                ]
            ],
            [
                'default_sort_by',
                [
                    'available_sort_by' => null,
                    'default_sort_by' => null,
                    'use_post_data_config' => ['available_sort_by', 'default_sort_by', 'filter_price_range']
                ]
            ],
        ];
    }

    /**
     * @param $attributeCode
     * @param $data
     * @dataProvider validateDefaultSortException
     */
    public function testValidateDefaultSortException($attributeCode, $data)
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->_attribute->expects($this->any())->method('getName')->willReturn($attributeCode);
        $this->_scopeConfig->expects($this->any())->method('getValue')->willReturn('another value');
        $object = new \Magento\Framework\DataObject($data);
        $this->_model->validate($object);
    }

    /**
     * @return array
     */
    public function validateDefaultSortException()
    {
        return [
            [
                'default_sort_by',
                [
                    'available_sort_by' => null,
                    'use_post_data_config' => ['default_sort_by']
                ],
            ],
            [
                'default_sort_by',
                [
                    'available_sort_by' => null,
                    'use_post_data_config' => []
                ]
            ],
            [
                'default_sort_by',
                [
                    'available_sort_by' => ['value1', 'value2'],
                    'default_sort_by' => 'another value',
                    'use_post_data_config' => []
                ]
            ],
            [
                'default_sort_by',
                [
                    'available_sort_by' => 'value1',
                    'use_post_data_config' => []
                ]
            ],
        ];
    }
}
