<?php
/**
 * Row Generator Interface
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Model\Widget\Grid\Row;

/**
 * @api
 * @since 100.0.2
 */
interface GeneratorInterface
{
    /**
     * @param \Magento\Framework\DataObject $item
     * @return string
     * @api
     */
    public function getUrl($item);
}
