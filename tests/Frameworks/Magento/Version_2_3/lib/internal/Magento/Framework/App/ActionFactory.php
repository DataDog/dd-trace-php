<?php
/**
 * Action Factory
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\App;

/**
 * @api
 * @since 100.0.2
 */
class ActionFactory
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
     * Create action
     *
     * @param string $actionName
     * @return ActionInterface
     * @throws \InvalidArgumentException
     */
    public function create($actionName)
    {
        if (!is_subclass_of($actionName, \Magento\Framework\App\ActionInterface::class)) {
            throw new \InvalidArgumentException(
                'The action name provided is invalid. Verify the action name and try again.'
            );
        }
        return $this->_objectManager->create($actionName);
    }
}
