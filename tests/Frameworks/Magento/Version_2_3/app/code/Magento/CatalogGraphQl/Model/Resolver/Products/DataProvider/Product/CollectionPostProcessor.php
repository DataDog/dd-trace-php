<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Processing applied to the collection after load
 */
class CollectionPostProcessor
{
    /**
     * Apply processing to loaded product collection
     *
     * @param Collection $collection
     * @param array $attributeNames
     * @return Collection
     */
    public function process(Collection $collection, array $attributeNames): Collection
    {
        if (!$collection->isLoaded()) {
            $collection->load();
        }
        // Methods that perform extra fetches post-load
        if (in_array('media_gallery_entries', $attributeNames)) {
            $collection->addMediaGalleryData();
        }
        if (in_array('media_gallery', $attributeNames)) {
            $collection->addMediaGalleryData();
        }
        if (in_array('options', $attributeNames)) {
            $collection->addOptionsToResult();
        }

        return $collection;
    }
}
