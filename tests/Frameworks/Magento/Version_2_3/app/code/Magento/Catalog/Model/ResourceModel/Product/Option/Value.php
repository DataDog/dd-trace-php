<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\ResourceModel\Product\Option;

use Magento\Catalog\Model\Product\Option\Value as OptionValue;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Helper\Data;

/**
 * Catalog product custom option resource model
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Value extends AbstractDb
{
    /**
     * Store manager
     *
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * Currency factory
     *
     * @var CurrencyFactory
     */
    protected $_currencyFactory;

    /**
     * Core config model
     *
     * @var ScopeConfigInterface
     */
    protected $_config;

    /**
     * @var FormatInterface
     */
    private $localeFormat;

    /**
     * @var Data
     */
    private $dataHelper;

    /**
     * Class constructor
     *
     * @param Context $context
     * @param CurrencyFactory $currencyFactory
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $config
     * @param string $connectionName
     * @param Data $dataHelper
     */
    public function __construct(
        Context $context,
        CurrencyFactory $currencyFactory,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $config,
        $connectionName = null,
        Data $dataHelper = null
    ) {
        $this->_currencyFactory = $currencyFactory;
        $this->_storeManager = $storeManager;
        $this->_config = $config;
        $this->dataHelper = $dataHelper ?: ObjectManager::getInstance()
            ->get(Data::class);
        parent::__construct($context, $connectionName);
    }

    /**
     * Define main table and initialize connection
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('catalog_product_option_type_value', 'option_type_id');
    }

    /**
     * Proceed operations after object is saved
     *
     * Save options store data
     *
     * @param AbstractModel $object
     * @return AbstractDb
     */
    protected function _afterSave(AbstractModel $object)
    {
        $this->_saveValuePrices($object);
        $this->_saveValueTitles($object);

        return parent::_afterSave($object);
    }

    /**
     * Save option value price data
     *
     * @param AbstractModel $object
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function _saveValuePrices(AbstractModel $object)
    {
        $objectPrice = $object->getPrice();
        $priceTable = $this->getTable('catalog_product_option_type_price');
        $formattedPrice = $this->getLocaleFormatter()->getNumber($objectPrice);

        $price = (double)sprintf('%F', $formattedPrice);
        $priceType = $object->getPriceType();

        if (isset($objectPrice) && $priceType) {
            //save for store_id = 0
            $select = $this->getConnection()->select()->from(
                $priceTable,
                'option_type_id'
            )->where(
                'option_type_id = ?',
                (int)$object->getId()
            )->where(
                'store_id = ?',
                Store::DEFAULT_STORE_ID
            );
            $optionTypeId = $this->getConnection()->fetchOne($select);

            if ($optionTypeId) {
                if ($object->getStoreId() == '0' || $this->dataHelper->isPriceGlobal()) {
                    $bind = ['price' => $price, 'price_type' => $priceType];
                    $where = [
                        'option_type_id = ?' => $optionTypeId,
                        'store_id = ?' => Store::DEFAULT_STORE_ID,
                    ];

                    $this->getConnection()->update($priceTable, $bind, $where);
                }
            } else {
                $bind = [
                    'option_type_id' => (int)$object->getId(),
                    'store_id' => Store::DEFAULT_STORE_ID,
                    'price' => $price,
                    'price_type' => $priceType,
                ];
                $this->getConnection()->insert($priceTable, $bind);
            }
        }

        $scope = (int)$this->_config->getValue(
            Store::XML_PATH_PRICE_SCOPE,
            ScopeInterface::SCOPE_STORE
        );

        if ($scope == Store::PRICE_SCOPE_WEBSITE
            && $priceType
            && isset($objectPrice)
            && $object->getStoreId() != Store::DEFAULT_STORE_ID
        ) {
            $website  = $this->_storeManager->getStore($object->getStoreId())->getWebsite();

            $websiteBaseCurrency = $this->_config->getValue(
                Currency::XML_PATH_CURRENCY_BASE,
                ScopeInterface::SCOPE_WEBSITE,
                $website
            );

            $storeIds = $website->getStoreIds();
            if (is_array($storeIds)) {
                foreach ($storeIds as $storeId) {
                    if ($priceType == 'fixed') {
                        $storeCurrency = $this->_storeManager->getStore($storeId)->getBaseCurrencyCode();
                        /** @var $currencyModel Currency */
                        $currencyModel = $this->_currencyFactory->create();
                        $currencyModel->load($websiteBaseCurrency);
                        $rate = $currencyModel->getRate($storeCurrency);
                        if (!$rate) {
                            $rate = 1;
                        }
                        $newPrice = $price * $rate;
                    } else {
                        $newPrice = $price;
                    }

                    $select = $this->getConnection()->select()->from(
                        $priceTable,
                        'option_type_id'
                    )->where(
                        'option_type_id = ?',
                        (int)$object->getId()
                    )->where(
                        'store_id = ?',
                        (int)$storeId
                    );
                    $optionTypeId = $this->getConnection()->fetchOne($select);

                    if ($optionTypeId) {
                        $bind = ['price' => $newPrice, 'price_type' => $priceType];
                        $where = ['option_type_id = ?' => (int)$optionTypeId, 'store_id = ?' => (int)$storeId];

                        $this->getConnection()->update($priceTable, $bind, $where);
                    } else {
                        $bind = [
                            'option_type_id' => (int)$object->getId(),
                            'store_id' => (int)$storeId,
                            'price' => $newPrice,
                            'price_type' => $priceType,
                        ];

                        $this->getConnection()->insert($priceTable, $bind);
                    }
                }
            }
        } else {
            if ($scope == Store::PRICE_SCOPE_WEBSITE
                && !isset($objectPrice)
                && !$priceType
            ) {
                $storeIds = $this->_storeManager->getStore($object->getStoreId())->getWebsite()->getStoreIds();
                foreach ($storeIds as $storeId) {
                    $where = [
                        'option_type_id = ?' => (int)$object->getId(),
                        'store_id = ?' => $storeId,
                    ];
                    $this->getConnection()->delete($priceTable, $where);
                }
            }
        }
    }

    /**
     * Save option value title data
     *
     * @param AbstractModel $object
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _saveValueTitles(AbstractModel $object)
    {
        foreach ([Store::DEFAULT_STORE_ID, $object->getStoreId()] as $storeId) {
            $titleTable = $this->getTable('catalog_product_option_type_title');
            $select = $this->getConnection()->select()->from(
                $titleTable,
                ['option_type_id']
            )->where(
                'option_type_id = ?',
                (int)$object->getId()
            )->where(
                'store_id = ?',
                (int)$storeId
            );
            $optionTypeId = $this->getConnection()->fetchOne($select);
            $existInCurrentStore = $this->getOptionIdFromOptionTable($titleTable, (int)$object->getId(), (int)$storeId);

            if ($storeId != Store::DEFAULT_STORE_ID && $object->getData('is_delete_store_title')) {
                $object->unsetData('title');
            }

            /*** Checking whether title is not null ***/
            if ($object->getTitle()!= null) {
                if ($existInCurrentStore) {
                    if ($storeId == $object->getStoreId()) {
                        $where = [
                            'option_type_id = ?' => (int)$optionTypeId,
                            'store_id = ?' => $storeId,
                        ];
                        $bind = ['title' => $object->getTitle()];
                        $this->getConnection()->update($titleTable, $bind, $where);
                    }
                } else {
                    $existInDefaultStore = $this->getOptionIdFromOptionTable(
                        $titleTable,
                        (int)$object->getId(),
                        Store::DEFAULT_STORE_ID
                    );
                    // we should insert record into not default store only of if it does not exist in default store
                    if (($storeId == Store::DEFAULT_STORE_ID && !$existInDefaultStore)
                        || ($storeId != Store::DEFAULT_STORE_ID && !$existInCurrentStore)
                    ) {
                        $bind = [
                            'option_type_id' => (int)$object->getId(),
                            'store_id' => $storeId,
                            'title' => $object->getTitle(),
                        ];
                        $this->getConnection()->insert($titleTable, $bind);
                    }
                }
            } else {
                if ($storeId
                    && $optionTypeId
                    && $object->getStoreId() > Store::DEFAULT_STORE_ID
                ) {
                    $where = [
                        'option_type_id = ?' => (int)$optionTypeId,
                        'store_id = ?' => $storeId,
                    ];
                    $this->getConnection()->delete($titleTable, $where);
                }
            }
        }
    }

    /**
     * Get first col from first row for option table
     *
     * @param string $tableName
     * @param int $optionId
     * @param int $storeId
     * @return string
     */
    protected function getOptionIdFromOptionTable($tableName, $optionId, $storeId)
    {
        $connection = $this->getConnection();
        $select = $connection->select()->from(
            $tableName,
            ['option_type_id']
        )->where(
            'option_type_id = ?',
            $optionId
        )->where(
            'store_id = ?',
            (int)$storeId
        );
        return $connection->fetchOne($select);
    }

    /**
     * Delete values by option id
     *
     * @param int $optionId
     * @return $this
     */
    public function deleteValue($optionId)
    {
        $statement = $this->getConnection()->select()->from(
            $this->getTable('catalog_product_option_type_value')
        )->where(
            'option_id = ?',
            $optionId
        );

        $rowSet = $this->getConnection()->fetchAll($statement);

        foreach ($rowSet as $optionType) {
            $this->deleteValues($optionType['option_type_id']);
        }

        $this->getConnection()->delete($this->getMainTable(), ['option_id = ?' => $optionId]);

        return $this;
    }

    /**
     * Delete values by option type
     *
     * @param int $optionTypeId
     * @return void
     */
    public function deleteValues($optionTypeId)
    {
        $condition = ['option_type_id = ?' => $optionTypeId];

        $this->getConnection()->delete($this->getTable('catalog_product_option_type_price'), $condition);

        $this->getConnection()->delete($this->getTable('catalog_product_option_type_title'), $condition);
    }

    /**
     * Duplicate product options value
     *
     * @param OptionValue $object
     * @param int $oldOptionId
     * @param int $newOptionId
     * @return OptionValue
     */
    public function duplicate(OptionValue $object, $oldOptionId, $newOptionId)
    {
        $connection = $this->getConnection();
        $select = $connection->select()->from($this->getMainTable())->where('option_id = ?', $oldOptionId);
        $valueData = $connection->fetchAll($select);

        $valueCond = [];

        foreach ($valueData as $data) {
            $optionTypeId = $data[$this->getIdFieldName()];
            unset($data[$this->getIdFieldName()]);
            $data['option_id'] = $newOptionId;

            $connection->insert($this->getMainTable(), $data);
            $valueCond[$optionTypeId] = $connection->lastInsertId($this->getMainTable());
        }

        unset($valueData);

        foreach ($valueCond as $oldTypeId => $newTypeId) {
            // price
            $priceTable = $this->getTable('catalog_product_option_type_price');
            $columns = [new \Zend_Db_Expr($newTypeId), 'store_id', 'price', 'price_type'];

            $select = $connection->select()->from(
                $priceTable,
                []
            )->where(
                'option_type_id = ?',
                $oldTypeId
            )->columns(
                $columns
            );
            $insertSelect = $connection->insertFromSelect(
                $select,
                $priceTable,
                ['option_type_id', 'store_id', 'price', 'price_type']
            );
            $connection->query($insertSelect);

            // title
            $titleTable = $this->getTable('catalog_product_option_type_title');
            $columns = [new \Zend_Db_Expr($newTypeId), 'store_id', 'title'];

            $select = $this->getConnection()->select()->from(
                $titleTable,
                []
            )->where(
                'option_type_id = ?',
                $oldTypeId
            )->columns(
                $columns
            );
            $insertSelect = $connection->insertFromSelect(
                $select,
                $titleTable,
                ['option_type_id', 'store_id', 'title']
            );
            $connection->query($insertSelect);
        }

        return $object;
    }

    /**
     * Get FormatInterface to convert price from string to number format
     *
     * @return FormatInterface
     * @deprecated 101.0.8
     */
    private function getLocaleFormatter()
    {
        if ($this->localeFormat === null) {
            $this->localeFormat = ObjectManager::getInstance()
                ->get(FormatInterface::class);
        }
        return $this->localeFormat;
    }
}
