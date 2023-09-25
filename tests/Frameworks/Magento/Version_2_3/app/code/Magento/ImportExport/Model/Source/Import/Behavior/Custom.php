<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ImportExport\Model\Source\Import\Behavior;

/**
 * Import behavior source model
 *
 * @api
 * @since 100.0.2
 */
class Custom extends \Magento\ImportExport\Model\Source\Import\AbstractBehavior
{
    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        return [
            \Magento\ImportExport\Model\Import::BEHAVIOR_ADD_UPDATE => __('Add/Update Complex Data'),
            \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE => __('Delete Entities'),
            \Magento\ImportExport\Model\Import::BEHAVIOR_CUSTOM => __('Custom Action')
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getCode()
    {
        return 'custom';
    }
}
