<?php
/**
 * Unit test for customer adminhtml model
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\Customer\Model\Backend\Customer testing
 */
namespace Magento\Customer\Test\Unit\Model\Backend;

class CustomerTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Store\Model\StoreManager|\PHPUnit\Framework\MockObject\MockObject */
    protected $_storeManager;

    /** @var \Magento\Customer\Model\Backend\Customer */
    protected $_model;

    /**
     * Create model
     */
    protected function setUp(): void
    {
        $this->_storeManager = $this->createMock(\Magento\Store\Model\StoreManager::class);
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_model = $helper->getObject(
            \Magento\Customer\Model\Backend\Customer::class,
            ['storeManager' => $this->_storeManager]
        );
    }

    /**
     * @dataProvider getStoreDataProvider
     * @param $websiteId
     * @param $websiteStoreId
     * @param $storeId
     * @param $result
     */
    public function testGetStoreId($websiteId, $websiteStoreId, $storeId, $result)
    {
        if ($websiteId * 1) {
            $this->_model->setWebsiteId($websiteId);
            $website = new \Magento\Framework\DataObject(['store_ids' => [$websiteStoreId]]);
            $this->_storeManager->expects($this->once())->method('getWebsite')->willReturn($website);
        } else {
            $this->_model->setStoreId($storeId);
            $this->_storeManager->expects($this->never())->method('getWebsite');
        }
        $this->assertEquals($result, $this->_model->getStoreId());
    }

    /**
     * Data provider for testGetStoreId
     * @return array
     */
    public function getStoreDataProvider()
    {
        return [[1, 10, 5, 10], [0, 10, 5, 5]];
    }
}
