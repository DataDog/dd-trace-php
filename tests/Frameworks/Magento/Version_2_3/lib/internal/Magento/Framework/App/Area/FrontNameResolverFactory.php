<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Area;

/**
 * Application area front name resolver factory
 *
 * Since front-name resolver is a service, a Pool object would suit better than factory.
 * Keeping it for backward compatibility
 *
 * @api
 * @since 100.0.2
 */
class FrontNameResolverFactory
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(\Magento\Framework\ObjectManagerInterface $objectManager)
    {
        $this->_objectManager = $objectManager;
    }

    /**
     * Create front name resolver
     *
     * @param string $className
     * @return FrontNameResolverInterface
     */
    public function create($className)
    {
        return $this->_objectManager->create($className);
    }
}
