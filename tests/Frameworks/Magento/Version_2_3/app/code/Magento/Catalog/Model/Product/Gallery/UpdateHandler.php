<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Product\Gallery;

use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\EntityManager\Operation\ExtensionInterface;

/**
 * Update handler for catalog product gallery.
 *
 * @api
 * @since 101.0.0
 */
class UpdateHandler extends \Magento\Catalog\Model\Product\Gallery\CreateHandler
{
    /**
     * @inheritdoc
     *
     * @since 101.0.0
     */
    protected function processDeletedImages($product, array &$images)
    {
        $filesToDelete = [];
        $recordsToDelete = [];
        $picturesInOtherStores = [];

        foreach ($this->resourceModel->getProductImages($product, $this->extractStoreIds($product)) as $image) {
            $picturesInOtherStores[$image['filepath']] = true;
        }

        foreach ($images as &$image) {
            if (!empty($image['removed'])) {
                if (!empty($image['value_id'])) {
                    $recordsToDelete[] = $image['value_id'];
                    $catalogPath = $this->mediaConfig->getBaseMediaPath();
                    $filePath = $this->mediaDirectory->getRelativePath($catalogPath . $image['file']);
                    $isFile = $this->mediaDirectory->isFile($filePath);
                    // only delete physical files if they are not used by any other products and if this file exist
                    if ($isFile && !($this->resourceModel->countImageUses($image['file']) > 1)) {
                        $filesToDelete[] = ltrim($image['file'], '/');
                    }
                }
            }
        }

        $this->resourceModel->deleteGallery($recordsToDelete);

        $this->removeDeletedImages($filesToDelete);
    }

    /**
     * @inheritdoc
     *
     * @since 101.0.0
     */
    protected function processNewImage($product, array &$image)
    {
        $data = [];

        if (empty($image['value_id'])) {
            $data['value'] = $image['file'];
            $data['attribute_id'] = $this->getAttribute()->getAttributeId();

            if (!empty($image['media_type'])) {
                $data['media_type'] = $image['media_type'];
            }

            $image['value_id'] = $this->resourceModel->insertGallery($data);

            $this->resourceModel->bindValueToEntity(
                $image['value_id'],
                $product->getData($this->metadata->getLinkField())
            );
        } elseif (!empty($image['recreate'])) {
            $data['value_id'] = $image['value_id'];
            $data['value'] = $image['file'];
            $data['attribute_id'] = $this->getAttribute()->getAttributeId();

            if (!empty($image['media_type'])) {
                $data['media_type'] = $image['media_type'];
            }

            $this->resourceModel->saveDataRow(Gallery::GALLERY_TABLE, $data);
        }

        return $data;
    }

    /**
     * Retrieve store ids from product.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return array
     * @since 101.0.0
     */
    protected function extractStoreIds($product)
    {
        $storeIds = $product->getStoreIds();
        $storeIds[] = \Magento\Store\Model\Store::DEFAULT_STORE_ID;

        // Removing current storeId.
        $storeIds = array_flip($storeIds);
        unset($storeIds[$product->getStoreId()]);
        $storeIds = array_keys($storeIds);

        return $storeIds;
    }

    /**
     * Remove deleted images.
     *
     * @param array $files
     * @return null
     * @throws \Magento\Framework\Exception\FileSystemException
     * @since 101.0.0
     */
    protected function removeDeletedImages(array $files)
    {
        $catalogPath = $this->mediaConfig->getBaseMediaPath();

        foreach ($files as $filePath) {
            $this->mediaDirectory->delete($catalogPath . '/' . $filePath);
        }

        return null;
    }
}
