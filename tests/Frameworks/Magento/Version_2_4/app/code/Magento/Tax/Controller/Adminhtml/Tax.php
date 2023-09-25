<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Tax\Controller\Adminhtml;

use Magento\Framework\Exception\InputException;

/**
 * Adminhtml common tax class controller
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 */
abstract class Tax extends \Magento\Backend\App\Action
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Tax::manage_tax';

    /**
     * @var \Magento\Tax\Api\TaxClassRepositoryInterface
     */
    protected $taxClassRepository;

    /**
     * @var \Magento\Tax\Api\Data\TaxClassInterfaceFactory
     */
    protected $taxClassDataObjectFactory;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Tax\Api\TaxClassRepositoryInterface $taxClassService
     * @param \Magento\Tax\Api\Data\TaxClassInterfaceFactory $taxClassDataObjectFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Tax\Api\TaxClassRepositoryInterface $taxClassService,
        \Magento\Tax\Api\Data\TaxClassInterfaceFactory $taxClassDataObjectFactory
    ) {
        $this->taxClassRepository = $taxClassService;
        $this->taxClassDataObjectFactory = $taxClassDataObjectFactory;
        parent::__construct($context);
    }

    /**
     * Validate/Filter Tax Class Name
     *
     * @param string $className
     * @return string processed class name
     * @throws \Magento\Framework\Exception\InputException
     */
    protected function _processClassName($className)
    {
        $className = trim($className);
        if ($className == '') {
            throw new InputException(__('Invalid name of tax class specified.'));
        }
        return $className;
    }
}
