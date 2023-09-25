<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Product\Attribute\Backend\GroupPrice;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AbstractTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\Backend\GroupPrice\AbstractGroupPrice
     */
    protected $_model;

    /**
     * Catalog helper
     *
     * @var \Magento\Catalog\Helper\Data|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_helper;

    protected function setUp(): void
    {
        $this->_helper = $this->createPartialMock(\Magento\Catalog\Helper\Data::class, ['isPriceGlobal']);
        $this->_helper->expects($this->any())->method('isPriceGlobal')->willReturn(true);

        $currencyFactoryMock = $this->createPartialMock(\Magento\Directory\Model\CurrencyFactory::class, ['create']);
        $storeManagerMock = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $productTypeMock = $this->createMock(\Magento\Catalog\Model\Product\Type::class);
        $configMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $localeFormatMock = $this->createMock(\Magento\Framework\Locale\FormatInterface::class);
        $groupManagement = $this->createMock(\Magento\Customer\Api\GroupManagementInterface::class);
        $scopeOverriddenValue = $this->createMock(\Magento\Catalog\Model\Attribute\ScopeOverriddenValue::class);
        $this->_model = $this->getMockForAbstractClass(
            \Magento\Catalog\Model\Product\Attribute\Backend\GroupPrice\AbstractGroupPrice::class,
            [
                'currencyFactory' => $currencyFactoryMock,
                'storeManager' => $storeManagerMock,
                'catalogData' => $this->_helper,
                'config' => $configMock,
                'localeFormat' => $localeFormatMock,
                'catalogProductType' => $productTypeMock,
                'groupManagement' => $groupManagement,
                'scopeOverriddenValue' => $scopeOverriddenValue
            ]
        );
        $resource = $this->createPartialMock(\stdClass::class, ['getMainTable']);
        $resource->expects($this->any())->method('getMainTable')->willReturn('table');

        $this->_model->expects($this->any())->method('_getResource')->willReturn($resource);
    }

    public function testGetAffectedFields()
    {
        $valueId = 10;
        $attributeId = 42;

        $attribute = $this->createPartialMock(
            \Magento\Eav\Model\Entity\Attribute\AbstractAttribute::class,
            ['getBackendTable', 'isStatic', 'getAttributeId', 'getName', '__wakeup']
        );
        $attribute->expects($this->any())->method('getAttributeId')->willReturn($attributeId);
        $attribute->expects($this->any())->method('isStatic')->willReturn(false);
        $attribute->expects($this->any())->method('getBackendTable')->willReturn('table');
        $attribute->expects($this->any())->method('getName')->willReturn('tier_price');
        $this->_model->setAttribute($attribute);

        $object = new \Magento\Framework\DataObject();
        $object->setTierPrice([['price_id' => 10]]);
        $object->setId(555);

        $this->assertEquals(
            [
                'table' => [
                    ['value_id' => $valueId, 'attribute_id' => $attributeId, 'entity_id' => $object->getId()]
                ]
            ],
            $this->_model->getAffectedFields($object)
        );
    }
}
