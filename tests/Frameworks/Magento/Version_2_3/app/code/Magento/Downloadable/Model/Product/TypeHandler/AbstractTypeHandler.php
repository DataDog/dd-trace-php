<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Downloadable\Model\Product\TypeHandler;

use Magento\Catalog\Model\Product;
use Magento\Downloadable\Helper\File;
use Magento\Downloadable\Model\ComponentInterface;
use Magento\Framework\Json\Helper\Data;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\App\ObjectManager;

/**
 * Class AbstractTypeHandler
 */
abstract class AbstractTypeHandler
{
    const FIELD_IS_DELETE = 'is_delete';

    const FIELD_FILE = 'file';

    /**
     * @var array
     */
    protected $deletedItems = [];

    /**
     * @var Data
     */
    protected $jsonHelper;

    /**
     * @var File
     */
    protected $downloadableFile;

    /**
     * @var MetadataPool
     */
    protected $metadataPool;

    /**
     * @param Data $jsonHelper
     * @param File $downloadableFile
     */
    public function __construct(
        Data $jsonHelper,
        File $downloadableFile
    ) {
        $this->jsonHelper = $jsonHelper;
        $this->downloadableFile = $downloadableFile;
    }

    /**
     * @return string
     */
    abstract public function getDataKey();

    /**
     * @return string
     */
    abstract public function getIdentifierKey();

    /**
     * @param Product $product
     * @param array $data
     * @return $this
     */
    public function save(Product $product, array $data)
    {
        $this->clear();
        if (isset($data[$this->getDataKey()])) {
            foreach ($data[$this->getDataKey()] as $item) {
                if ($this->isDelete($item)) {
                    $this->addToDeleteQueue($item);
                } else {
                    $this->saveItem($product, $item);
                }
            }
            $this->processDelete();
        }
        return $this;
    }

    /**
     * @return ComponentInterface
     */
    abstract protected function createItem();

    /**
     * @param ComponentInterface $model
     * @param array $data
     * @param Product $product
     * @return void
     */
    abstract protected function setDataToModel(
        ComponentInterface $model,
        array $data,
        Product $product
    );

    /**
     * @param ComponentInterface $model
     * @param Product $product
     * @return void
     */
    abstract protected function linkToProduct(ComponentInterface $model, Product $product);

    /**
     * @param array $item
     * @return array
     */
    protected function prepareItem(array $item)
    {
        unset($item[self::FIELD_IS_DELETE], $item[self::FIELD_FILE]);
        if (isset($item[$this->getIdentifierKey()]) && !$item[$this->getIdentifierKey()]) {
            unset($item[$this->getIdentifierKey()]);
        }
        return $item;
    }

    /**
     * @return void
     */
    abstract protected function processDelete();

    /**
     * @param array $item
     * @return bool
     */
    protected function isDelete(array $item)
    {
        return isset($item[self::FIELD_IS_DELETE]) && '1' == $item[self::FIELD_IS_DELETE];
    }

    /**
     * @param array $item
     * @return array
     */
    protected function getFiles(array $item)
    {
        $files = [];
        if (isset($item[self::FIELD_FILE]) && $item[self::FIELD_FILE]) {
            $files = $this->jsonHelper->jsonDecode($item[self::FIELD_FILE]);
        }
        return $files;
    }

    /**
     * @param ComponentInterface $model
     * @param array $files
     * @return void
     */
    abstract protected function setFiles(ComponentInterface $model, array $files);

    /**
     * @param Product $product
     * @param array $item
     * @return array
     */
    protected function saveItem(Product $product, array $item)
    {
        $files = $this->getFiles($item);
        $item = $this->prepareItem($item);

        $model = $this->createItem();
        $this->setDataToModel($model, $item, $product);
        $this->setFiles($model, $files);
        $model->save();
        $this->linkToProduct($model, $product);
        return $item;
    }

    /**
     * @param array $item
     * @return void
     */
    protected function addToDeleteQueue(array $item)
    {
        if (!empty($item[$this->getIdentifierKey()])) {
            $this->deletedItems[] = $item[$this->getIdentifierKey()];
        }
    }

    /**
     * Clear type state
     *
     * @return void
     */
    protected function clear()
    {
        $this->deletedItems = [];
    }

    /**
     * Get MetadataPool instance
     * @return MetadataPool
     */
    protected function getMetadataPool()
    {
        if (!$this->metadataPool) {
            $this->metadataPool = ObjectManager::getInstance()->get(MetadataPool::class);
        }
        return $this->metadataPool;
    }
}
