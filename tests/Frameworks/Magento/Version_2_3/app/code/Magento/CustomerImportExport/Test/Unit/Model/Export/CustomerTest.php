<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CustomerImportExport\Test\Unit\Model\Export;

use Magento\CustomerImportExport\Model\Export\Customer;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CustomerTest extends \PHPUnit\Framework\TestCase
{
    /**#@+
     * Test attribute code
     */
    const ATTRIBUTE_CODE = 'code1';

    /**#@-*/

    /**
     * Websites array (website id => code)
     *
     * @var array
     */
    protected $_websites = [\Magento\Store\Model\Store::DEFAULT_STORE_ID => 'admin', 1 => 'website1'];

    /**
     * Stores array (store id => code)
     *
     * @var array
     */
    protected $_stores = [0 => 'admin', 1 => 'store1'];

    /**
     * Attributes array
     *
     * @var array
     */
    protected $_attributes = [['attribute_id' => 1, 'attribute_code' => self::ATTRIBUTE_CODE]];

    /**
     * Customer data
     *
     * @var array
     */
    protected $_customerData = ['website_id' => 1, 'store_id' => 1, self::ATTRIBUTE_CODE => 1];

    /**
     * Customer export model
     *
     * @var Customer
     */
    protected $_model;

    protected function setUp(): void
    {
        $storeManager = $this->createMock(\Magento\Store\Model\StoreManager::class);

        $storeManager->expects(
            $this->any()
        )->method(
            'getWebsites'
        )->willReturnCallback(
            [$this, 'getWebsites']
        );

        $storeManager->expects(
            $this->any()
        )->method(
            'getStores'
        )->willReturnCallback(
            [$this, 'getStores']
        );

        $this->_model = new \Magento\CustomerImportExport\Model\Export\Customer(
            $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class),
            $storeManager,
            $this->createMock(\Magento\ImportExport\Model\Export\Factory::class),
            $this->createMock(\Magento\ImportExport\Model\ResourceModel\CollectionByPagesIteratorFactory::class),
            $this->createMock(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class),
            $this->createMock(\Magento\Eav\Model\Config::class),
            $this->createMock(\Magento\Customer\Model\ResourceModel\Customer\CollectionFactory::class),
            $this->_getModelDependencies()
        );
    }

    protected function tearDown(): void
    {
        unset($this->_model);
    }

    /**
     * Create mocks for all $this->_model dependencies
     *
     * @return array
     */
    protected function _getModelDependencies()
    {
        $translator = $this->createMock(\stdClass::class);

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $attributeCollection = new \Magento\Framework\Data\Collection(
            $this->createMock(\Magento\Framework\Data\Collection\EntityFactory::class)
        );
        foreach ($this->_attributes as $attributeData) {
            $arguments = $objectManagerHelper->getConstructArguments(
                \Magento\Eav\Model\Entity\Attribute\AbstractAttribute::class,
                ['eavTypeFactory' => $this->createMock(\Magento\Eav\Model\Entity\TypeFactory::class)]
            );
            $arguments['data'] = $attributeData;
            $attribute = $this->getMockForAbstractClass(
                \Magento\Eav\Model\Entity\Attribute\AbstractAttribute::class,
                $arguments,
                '',
                true,
                true,
                true,
                ['_construct']
            );
            $attributeCollection->addItem($attribute);
        }

        $data = [
            'translator' => $translator,
            'attribute_collection' => $attributeCollection,
            'page_size' => 1,
            'collection_by_pages_iterator' => 'not_used',
            'entity_type_id' => 1,
            'customer_collection' => 'not_used',
        ];

        return $data;
    }

    /**
     * Get websites
     *
     * @param bool $withDefault
     * @return array
     */
    public function getWebsites($withDefault = false)
    {
        $websites = [];
        if (!$withDefault) {
            unset($websites[0]);
        }
        foreach ($this->_websites as $id => $code) {
            if (!$withDefault && $id == \Magento\Store\Model\Store::DEFAULT_STORE_ID) {
                continue;
            }
            $websiteData = ['id' => $id, 'code' => $code];
            $websites[$id] = new \Magento\Framework\DataObject($websiteData);
        }

        return $websites;
    }

    /**
     * Get stores
     *
     * @param bool $withDefault
     * @return array
     */
    public function getStores($withDefault = false)
    {
        $stores = [];
        if (!$withDefault) {
            unset($stores[0]);
        }
        foreach ($this->_stores as $id => $code) {
            if (!$withDefault && $id == 0) {
                continue;
            }
            $storeData = ['id' => $id, 'code' => $code];
            $stores[$id] = new \Magento\Framework\DataObject($storeData);
        }

        return $stores;
    }

    /**
     * Test for method exportItem()
     *
     * @covers \Magento\CustomerImportExport\Model\Export\Customer::exportItem
     */
    public function testExportItem()
    {
        /** @var $writer \Magento\ImportExport\Model\Export\Adapter\AbstractAdapter */
        $writer = $this->getMockForAbstractClass(
            \Magento\ImportExport\Model\Export\Adapter\AbstractAdapter::class,
            [],
            '',
            false,
            false,
            true,
            ['writeRow']
        );

        $writer->expects(
            $this->once()
        )->method(
            'writeRow'
        )->willReturnCallback(
            [$this, 'validateWriteRow']
        );

        $this->_model->setWriter($writer);

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $arguments = $objectManagerHelper->getConstructArguments(\Magento\Framework\Model\AbstractModel::class);
        $arguments['data'] = $this->_customerData;
        $item = $this->getMockForAbstractClass(\Magento\Framework\Model\AbstractModel::class, $arguments);

        $this->_model->exportItem($item);
    }

    /**
     * Validate data passed to writer's writeRow() method
     *
     * @param array $row
     */
    public function validateWriteRow(array $row)
    {
        $websiteColumn = Customer::COLUMN_WEBSITE;
        $storeColumn = Customer::COLUMN_STORE;
        $this->assertEquals($this->_websites[$this->_customerData['website_id']], $row[$websiteColumn]);
        $this->assertEquals($this->_stores[$this->_customerData['store_id']], $row[$storeColumn]);
        $this->assertEquals($this->_customerData[self::ATTRIBUTE_CODE], $row[self::ATTRIBUTE_CODE]);
    }
}
