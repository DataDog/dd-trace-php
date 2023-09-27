<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Factory class for \Magento\Quote\Model\Quote\Address\Total\AbstractTotal
 */
namespace Magento\Quote\Model\Quote\Address;

class TotalFactory
{
    /**
     * Object Manager instance
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager = null;

    /**
     * Quote address factory constructor
     *
     * @param \Magento\Framework\ObjectManagerInterface $objManager
     */
    public function __construct(\Magento\Framework\ObjectManagerInterface $objManager)
    {
        $this->_objectManager = $objManager;
    }

    /**
     * Create class instance with specified parameters
     *
     * @param string $instanceName
     * @param array $data
     * @return Total\AbstractTotal|Total
     */
    public function create($instanceName = Total::class, array $data = [])
    {
        return $this->_objectManager->create($instanceName, $data);
    }
}
