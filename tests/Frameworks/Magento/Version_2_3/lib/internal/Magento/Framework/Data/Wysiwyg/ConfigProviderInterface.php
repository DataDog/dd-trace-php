<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Data\Wysiwyg;

/**
 * Interface ConfigProviderInterface
 * @api
 * @since 102.0.0
 */
interface ConfigProviderInterface
{
    /**
     * @param \Magento\Framework\DataObject $config
     * @return \Magento\Framework\DataObject
     * @since 102.0.0
     */
    public function getConfig(\Magento\Framework\DataObject $config) : \Magento\Framework\DataObject;
}
