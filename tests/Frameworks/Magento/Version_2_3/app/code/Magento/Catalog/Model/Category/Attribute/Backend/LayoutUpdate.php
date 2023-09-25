<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Catalog\Model\Category\Attribute\Backend;

use Magento\Catalog\Model\AbstractModel;
use Magento\Catalog\Model\Attribute\Backend\AbstractLayoutUpdate;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\Attribute\LayoutUpdateManager;

/**
 * Allows to select a layout file to merge when rendering the category's page.
 */
class LayoutUpdate extends AbstractLayoutUpdate
{

    /**
     * @var LayoutUpdateManager
     */
    private $manager;

    /**
     * @param LayoutUpdateManager $manager
     */
    public function __construct(LayoutUpdateManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * @inheritDoc
     *
     * @param AbstractModel|Category $forModel
     */
    protected function listAvailableValues(AbstractModel $forModel): array
    {
        return $this->manager->fetchAvailableFiles($forModel);
    }
}
