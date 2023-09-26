<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model\Status;

/**
 * Class ListFactory
 * @internal
 */
class ListFactory
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
     * Create status list instance
     *
     * @param array $arguments
     * @return \Magento\Sales\Model\Status\ListStatus
     */
    public function create(array $arguments = [])
    {
        return $this->_objectManager->create(\Magento\Sales\Model\Status\ListStatus::class, $arguments);
    }
}
