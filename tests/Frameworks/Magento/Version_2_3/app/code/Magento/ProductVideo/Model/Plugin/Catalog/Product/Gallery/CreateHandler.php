<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ProductVideo\Model\Plugin\Catalog\Product\Gallery;

use Magento\ProductVideo\Model\Product\Attribute\Media\ExternalVideoEntryConverter;

/**
 * Plugin for catalog product gallery create/update handlers.
 */
class CreateHandler extends AbstractHandler
{
    /**
     * Key to store additional data from other stores
     */
    const ADDITIONAL_STORE_DATA_KEY = 'additional_store_data';

    /**
     * Execute before Plugin
     *
     * @param \Magento\Catalog\Model\Product\Gallery\CreateHandler $mediaGalleryCreateHandler
     * @param \Magento\Catalog\Model\Product $product
     * @param array $arguments
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeExecute(
        \Magento\Catalog\Model\Product\Gallery\CreateHandler $mediaGalleryCreateHandler,
        \Magento\Catalog\Model\Product $product,
        array $arguments = []
    ) {
        /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute */
        $attribute = $mediaGalleryCreateHandler->getAttribute();
        $mediaCollection = $this->getMediaEntriesDataCollection($product, $attribute);
        if (!empty($mediaCollection)) {
            $storeDataCollection = $this->loadStoreViewVideoData($mediaCollection, $product->getStoreId());
            $mediaCollection = $this->addAdditionalStoreData($mediaCollection, $storeDataCollection);
            $product->setData(
                $attribute->getAttributeCode(),
                $mediaCollection
            );
        }
    }

    /**
     * Execute plugin
     *
     * @param \Magento\Catalog\Model\Product\Gallery\CreateHandler $mediaGalleryCreateHandler
     * @param \Magento\Catalog\Model\Product $product
     * @return \Magento\Catalog\Model\Product
     */
    public function afterExecute(
        \Magento\Catalog\Model\Product\Gallery\CreateHandler $mediaGalleryCreateHandler,
        \Magento\Catalog\Model\Product $product
    ) {
        $mediaCollection = $this->getMediaEntriesDataCollection(
            $product,
            $mediaGalleryCreateHandler->getAttribute()
        );

        if (!empty($mediaCollection)) {
            if ($product->getIsDuplicate() === true) {
                $mediaCollection = $this->makeAllNewVideos($product->getId(), $mediaCollection);
            }
            $newVideoCollection = $this->collectNewVideos($mediaCollection);
            $this->saveVideoData($newVideoCollection, 0);

            $videoDataCollection = $this->collectVideoData($mediaCollection);
            $this->saveVideoData($videoDataCollection, $product->getStoreId());
            $this->saveAdditionalStoreData($videoDataCollection);
        }

        return $product;
    }

    /**
     * Saves video data
     *
     * @param array $videoDataCollection
     * @param int $storeId
     * @return void
     */
    protected function saveVideoData(array $videoDataCollection, $storeId)
    {
        foreach ($videoDataCollection as $item) {
            $item['store_id'] = $storeId;
            $this->saveVideoValuesItem($item);
        }
    }

    /**
     * Saves additioanal video data
     *
     * @param array $videoDataCollection
     * @return void
     */
    protected function saveAdditionalStoreData(array $videoDataCollection)
    {
        foreach ($videoDataCollection as $mediaItem) {
            if (!empty($mediaItem[self::ADDITIONAL_STORE_DATA_KEY])) {
                foreach ($mediaItem[self::ADDITIONAL_STORE_DATA_KEY] as $additionalStoreItem) {
                    $additionalStoreItem['value_id'] = $mediaItem['value_id'];
                    $this->saveVideoValuesItem($additionalStoreItem);
                }
            }
        }
    }

    /**
     * Saves video data
     *
     * @param array $item
     * @return void
     */
    protected function saveVideoValuesItem(array $item)
    {
        $this->resourceModel->saveDataRow(
            'catalog_product_entity_media_gallery_value_video',
            $this->prepareVideoRowDataForSave($item)
        );
    }

    /**
     * Excludes current store data
     *
     * @param array $mediaCollection
     * @param int $currentStoreId
     * @return array
     */
    protected function excludeCurrentStoreRecord(array $mediaCollection, $currentStoreId)
    {
        return array_filter(
            $mediaCollection,
            function ($item) use ($currentStoreId) {
                return $item['store_id'] == $currentStoreId ? false : true;
            }
        );
    }

    /**
     * Prepare video data for saving
     *
     * @param array $rowData
     * @return array
     */
    protected function prepareVideoRowDataForSave(array $rowData)
    {
        foreach ($this->videoPropertiesDbMapping as $sourceKey => $dbKey) {
            if (array_key_exists($sourceKey, $rowData) && $sourceKey != $dbKey) {
                $rowData[$dbKey] = $rowData[$sourceKey];
                unset($rowData[$sourceKey]);
            }
        }
        $rowData = array_intersect_key($rowData, array_flip($this->videoPropertiesDbMapping));

        return $rowData;
    }

    /**
     * Loads video data
     *
     * @param array $mediaCollection
     * @param int $excludedStore
     * @return array
     */
    protected function loadStoreViewVideoData(array $mediaCollection, $excludedStore)
    {
        $ids = $this->collectVideoEntriesIdsToAdditionalLoad($mediaCollection);
        $result = [];
        if (!empty($ids)) {
            $result = $this->resourceModel->loadDataFromTableByValueId(
                'catalog_product_entity_media_gallery_value_video',
                $ids,
                null,
                $this->videoPropertiesDbMapping
            );
            $result = $this->excludeCurrentStoreRecord($result, $excludedStore);
        }

        return $result;
    }

    /**
     * Collect video data
     *
     * @param array $mediaCollection
     * @return array
     */
    protected function collectVideoData(array $mediaCollection)
    {
        $videoDataCollection = [];
        foreach ($mediaCollection as $item) {
            if ($this->isVideoItem($item)) {
                $videoData = $this->extractVideoDataFromRowData($item);
                $videoDataCollection[] = $videoData;
            }
        }

        return $videoDataCollection;
    }

    /**
     * Extract video data
     *
     * @param array $rowData
     * @return array
     */
    protected function extractVideoDataFromRowData(array $rowData)
    {
        return array_intersect_key(
            $rowData,
            array_merge($this->videoPropertiesDbMapping, [self::ADDITIONAL_STORE_DATA_KEY => ''])
        );
    }

    /**
     * Collect items for additional data adding
     *
     * @param array $mediaCollection
     * @return array
     */
    protected function collectVideoEntriesIdsToAdditionalLoad(array $mediaCollection)
    {
        $ids = [];
        foreach ($mediaCollection as $item) {
            if ($this->isVideoItem($item) && isset($item['save_data_from'])) {
                $ids[] = $item['save_data_from'];
            }
        }
        return $ids;
    }

    /**
     * Add additional data
     *
     * @param array $mediaCollection
     * @param array $data
     * @return array
     */
    protected function addAdditionalStoreData(array $mediaCollection, array $data): array
    {
        $return = [];
        foreach ($mediaCollection as $key => $mediaItem) {
            if (!empty($mediaItem['save_data_from'])) {
                $additionalData = $this->createAdditionalStoreDataCollection($data, $mediaItem['save_data_from']);
                if (!empty($additionalData)) {
                    $mediaItem[self::ADDITIONAL_STORE_DATA_KEY] = $additionalData;
                }
            }
            $return[$key] = $mediaItem;
        }
        return ['images' => $return];
    }

    /**
     * Creates additional video data
     *
     * @param array $storeData
     * @param int $valueId
     * @return array
     */
    protected function createAdditionalStoreDataCollection(array $storeData, $valueId): array
    {
        $result = [];
        foreach ($storeData as $item) {
            if ($item['value_id'] == $valueId) {
                unset($item['value_id']);
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Collect new videos
     *
     * @param array $mediaCollection
     * @return array
     */
    private function collectNewVideos(array $mediaCollection): array
    {
        $return = [];
        foreach ($mediaCollection as $item) {
            if ($this->isVideoItem($item) && $this->isNewVideo($item)) {
                $return[] = $this->extractVideoDataFromRowData($item);
            }
        }
        return $return;
    }

    /**
     * Checks if gallery item is video
     *
     * @param array $item
     * @return bool
     */
    private function isVideoItem(array $item): bool
    {
        return !empty($item['media_type'])
            && empty($item['removed'])
            && $item['media_type'] == ExternalVideoEntryConverter::MEDIA_TYPE_CODE;
    }

    /**
     * Checks if video is new
     *
     * @param array $item
     * @return bool
     */
    private function isNewVideo(array $item): bool
    {
        return !isset($item['video_url_default'], $item['video_title_default'])
            || empty($item['video_url_default'])
            || empty($item['video_title_default']);
    }

    /**
     * Mark all videos as new
     *
     * @param int $entityId
     * @param array $mediaCollection
     * @return array
     */
    private function makeAllNewVideos($entityId, array $mediaCollection): array
    {
        foreach ($mediaCollection as $key => $video) {
            if ($this->isVideoItem($video)) {
                unset($video['video_url_default'], $video['video_title_default']);
                $video['entity_id'] = $entityId;
                $mediaCollection[$key] = $video;
            }
        }
        return $mediaCollection;
    }
}
