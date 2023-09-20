<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Model\ResourceModel\Db;

class AbstractTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Model\ResourceModel\Db\AbstractDb
     */
    protected $_model;

    protected function setUp(): void
    {
        $resource = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\App\ResourceConnection::class
        );
        $context = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Framework\Model\ResourceModel\Db\Context::class,
            ['resource' => $resource]
        );
        $this->_model = $this->getMockForAbstractClass(
            \Magento\Framework\Model\ResourceModel\Db\AbstractDb::class,
            ['context' => $context]
        );
    }

    public function testConstruct()
    {
        $resourceProperty = new \ReflectionProperty(get_class($this->_model), '_resources');
        $resourceProperty->setAccessible(true);
        $this->assertInstanceOf(
            \Magento\Framework\App\ResourceConnection::class,
            $resourceProperty->getValue($this->_model)
        );
    }

    public function testSetMainTable()
    {
        $setMainTableMethod = new \ReflectionMethod($this->_model, '_setMainTable');
        $setMainTableMethod->setAccessible(true);

        $tableName = $this->_model->getTable('store_website');
        $idFieldName = 'website_id';

        $setMainTableMethod->invoke($this->_model, $tableName);
        $this->assertEquals($tableName, $this->_model->getMainTable());

        $setMainTableMethod->invoke($this->_model, $tableName, $idFieldName);
        $this->assertEquals($tableName, $this->_model->getMainTable());
        $this->assertEquals($idFieldName, $this->_model->getIdFieldName());
    }

    public function testGetTableName()
    {
        $tableNameOrig = 'store_website';
        $tableSuffix = 'suffix';
        $resource = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Framework\App\ResourceConnection::class,
            ['tablePrefix' => 'prefix_']
        );
        $context = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Framework\Model\ResourceModel\Db\Context::class,
            ['resource' => $resource]
        );

        $model = $this->getMockForAbstractClass(
            \Magento\Framework\Model\ResourceModel\Db\AbstractDb::class,
            ['context' => $context]
        );

        $tableName = $model->getTable([$tableNameOrig, $tableSuffix]);
        $this->assertEquals('prefix_store_website_suffix', $tableName);
    }
}
