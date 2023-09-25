<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Sales\Setup;

use Magento\Eav\Model\Entity\Setup\Context;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * Sales module setup class
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @codeCoverageIgnore
 * @api
 */
class SalesSetup extends EavSetup
{
    /**
     * This should be set explicitly
     */
    public const ORDER_ENTITY_TYPE_ID = 5;

    /**
     * This should be set explicitly
     */
    public const INVOICE_PRODUCT_ENTITY_TYPE_ID = 6;

    /**
     * This should be set explicitly
     */
    public const CREDITMEMO_PRODUCT_ENTITY_TYPE_ID = 7;

    /**
     * This should be set explicitly
     */
    public const SHIPMENT_PRODUCT_ENTITY_TYPE_ID = 8;

    /**
     * @var ScopeConfigInterface
     */
    protected $config;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var string
     */
    private static $connectionName = 'sales';

    /**
     * Constructor
     *
     * @param ModuleDataSetupInterface $setup
     * @param Context $context
     * @param CacheInterface $cache
     * @param CollectionFactory $attrGroupCollectionFactory
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        ModuleDataSetupInterface $setup,
        Context $context,
        CacheInterface $cache,
        CollectionFactory $attrGroupCollectionFactory,
        ScopeConfigInterface $config
    ) {
        $this->config = $config;
        $this->encryptor = $context->getEncryptor();
        parent::__construct($setup, $context, $cache, $attrGroupCollectionFactory);
    }

    /**
     * List of entities converted from EAV to flat data structure
     *
     * @var $_flatEntityTables array
     */
    protected $_flatEntityTables = [
        'order' => 'sales_order',
        'order_payment' => 'sales_order_payment',
        'order_item' => 'sales_order_item',
        'order_address' => 'sales_order_address',
        'order_status_history' => 'sales_order_status_history',
        'invoice' => 'sales_invoice',
        'invoice_item' => 'sales_invoice_item',
        'invoice_comment' => 'sales_invoice_comment',
        'creditmemo' => 'sales_creditmemo',
        'creditmemo_item' => 'sales_creditmemo_item',
        'creditmemo_comment' => 'sales_creditmemo_comment',
        'shipment' => 'sales_shipment',
        'shipment_item' => 'sales_shipment_item',
        'shipment_track' => 'sales_shipment_track',
        'shipment_comment' => 'sales_shipment_comment',
    ];

    /**
     * List of entities used with separate grid table
     *
     * @var string[] $_flatEntitiesGrid
     */
    protected $_flatEntitiesGrid = ['order', 'invoice', 'shipment', 'creditmemo'];

    /**
     * Check if table exist for flat entity
     *
     * @param string $table
     * @return bool
     */
    protected function _flatTableExist($table)
    {
        $tablesList = $this->getConnection()
            ->listTables();
        return in_array(
            strtoupper($this->getTable($table)),
            array_map('strtoupper', $tablesList)
        );
    }

    /**
     * Add entity attribute. Overwritten for flat entities support
     *
     * @param int|string $entityTypeId
     * @param string $code
     * @param array $attr
     * @return $this
     */
    public function addAttribute($entityTypeId, $code, array $attr)
    {
        if (isset(
            $this->_flatEntityTables[$entityTypeId]
        ) && $this->_flatTableExist(
            $this->_flatEntityTables[$entityTypeId]
        )
        ) {
            $this->_addFlatAttribute($this->_flatEntityTables[$entityTypeId], $code, $attr);
            $this->_addGridAttribute($this->_flatEntityTables[$entityTypeId], $code, $attr, $entityTypeId);
        } else {
            parent::addAttribute($entityTypeId, $code, $attr);
        }
        return $this;
    }

    /**
     * Add attribute as separate column in the table
     *
     * @param string $table
     * @param string $attribute
     * @param array $attr
     * @return $this
     */
    protected function _addFlatAttribute($table, $attribute, $attr)
    {
        $tableInfo = $this->getConnection()
            ->describeTable($this->getTable($table));
        if (isset($tableInfo[$attribute])) {
            return $this;
        }
        $columnDefinition = $this->_getAttributeColumnDefinition($attribute, $attr);
        $this->getConnection()->addColumn(
            $this->getTable($table),
            $attribute,
            $columnDefinition
        );
        return $this;
    }

    /**
     * Add attribute to grid table if necessary
     *
     * @param string $table
     * @param string $attribute
     * @param array $attr
     * @param string $entityTypeId
     * @return $this
     */
    protected function _addGridAttribute($table, $attribute, $attr, $entityTypeId)
    {
        if (in_array($entityTypeId, $this->_flatEntitiesGrid) && !empty($attr['grid'])) {
            $columnDefinition = $this->_getAttributeColumnDefinition($attribute, $attr);
            $this->getConnection()->addColumn(
                $this->getTable($table . '_grid'),
                $attribute,
                $columnDefinition
            );
        }
        return $this;
    }

    /**
     * Retrieve definition of column for create in flat table
     *
     * @param string $code
     * @param array $data
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _getAttributeColumnDefinition($code, $data)
    {
        // Convert attribute type to column info
        $data['type'] = $data['type'] ?? 'varchar';
        $type = null;
        $length = null;
        switch ($data['type']) {
            case 'timestamp':
                $type = \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP;
                break;
            case 'datetime':
                $type = \Magento\Framework\DB\Ddl\Table::TYPE_DATETIME;
                break;
            case 'decimal':
                $type = \Magento\Framework\DB\Ddl\Table::TYPE_DECIMAL;
                $length = '12,4';
                break;
            case 'int':
                $type = \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER;
                break;
            case 'text':
                $type = \Magento\Framework\DB\Ddl\Table::TYPE_TEXT;
                $length = 65536;
                break;
            case 'char':
            case 'varchar':
                $type = \Magento\Framework\DB\Ddl\Table::TYPE_TEXT;
                $length = 255;
                break;
        }
        if ($type !== null) {
            $data['type'] = $type;
            $data['length'] = $length;
        }

        $data['nullable'] = !isset($data['required']) || !$data['required'];
        $data['comment'] = $data['comment'] ?? $code !== null ? ucwords(str_replace('_', ' ', $code)) : '';
        return $data;
    }

    /**
     * Method to get default entities.
     *
     * @return array
     */
    public function getDefaultEntities()
    {
        $entities = [
            'order' => [
                'entity_type_id' => self::ORDER_ENTITY_TYPE_ID,
                'entity_model' => \Magento\Sales\Model\ResourceModel\Order::class,
                'table' => 'sales_order',
                'increment_model' => \Magento\Eav\Model\Entity\Increment\NumericValue::class,
                'increment_per_store' => true,
                'attributes' => [],
            ],
            'invoice' => [
                'entity_type_id' => self::INVOICE_PRODUCT_ENTITY_TYPE_ID,
                'entity_model' => \Magento\Sales\Model\ResourceModel\Order\Invoice::class,
                'table' => 'sales_invoice',
                'increment_model' => \Magento\Eav\Model\Entity\Increment\NumericValue::class,
                'increment_per_store' => true,
                'attributes' => [],
            ],
            'creditmemo' => [
                'entity_type_id' => self::CREDITMEMO_PRODUCT_ENTITY_TYPE_ID,
                'entity_model' => \Magento\Sales\Model\ResourceModel\Order\Creditmemo::class,
                'table' => 'sales_creditmemo',
                'increment_model' => \Magento\Eav\Model\Entity\Increment\NumericValue::class,
                'increment_per_store' => true,
                'attributes' => [],
            ],
            'shipment' => [
                'entity_type_id' => self::SHIPMENT_PRODUCT_ENTITY_TYPE_ID,
                'entity_model' => \Magento\Sales\Model\ResourceModel\Order\Shipment::class,
                'table' => 'sales_shipment',
                'increment_model' => \Magento\Eav\Model\Entity\Increment\NumericValue::class,
                'increment_per_store' => true,
                'attributes' => [],
            ],
        ];
        return $entities;
    }

    /**
     * Get config model
     *
     * @return ScopeConfigInterface
     */
    public function getConfigModel()
    {
        return $this->config;
    }

    /**
     * Method to get encryptor.
     *
     * @return EncryptorInterface
     */
    public function getEncryptor()
    {
        return $this->encryptor;
    }

    /**
     * Method to get connection.
     *
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    public function getConnection()
    {
        return $this->getSetup()->getConnection(self::$connectionName);
    }

    /**
     * Get table name
     *
     * @param string $table
     * @return string
     */
    public function getTable($table)
    {
        return $this->getSetup()->getTable($table, self::$connectionName);
    }

    /**
     * Update entity types
     *
     * @return void
     */
    public function updateEntityTypes()
    {
        $this->updateEntityType(
            \Magento\Sales\Model\Order::ENTITY,
            'entity_model',
            \Magento\Sales\Model\ResourceModel\Order::class
        );
        $this->updateEntityType(
            \Magento\Sales\Model\Order::ENTITY,
            'increment_model',
            \Magento\Eav\Model\Entity\Increment\NumericValue::class
        );
        $this->updateEntityType(
            'invoice',
            'entity_model',
            \Magento\Sales\Model\ResourceModel\Order::class
        );
        $this->updateEntityType(
            'invoice',
            'increment_model',
            \Magento\Eav\Model\Entity\Increment\NumericValue::class
        );
        $this->updateEntityType(
            'creditmemo',
            'entity_model',
            \Magento\Sales\Model\ResourceModel\Order\Creditmemo::class
        );
        $this->updateEntityType(
            'creditmemo',
            'increment_model',
            \Magento\Eav\Model\Entity\Increment\NumericValue::class
        );
        $this->updateEntityType(
            'shipment',
            'entity_model',
            \Magento\Sales\Model\ResourceModel\Order\Shipment::class
        );
        $this->updateEntityType(
            'shipment',
            'increment_model',
            \Magento\Eav\Model\Entity\Increment\NumericValue::class
        );
    }
}
