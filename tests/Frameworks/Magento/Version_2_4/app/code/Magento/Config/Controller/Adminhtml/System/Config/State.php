<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Config\Controller\Adminhtml\System\Config;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * Save current state of open tabs. GET is allowed for legacy reasons.
 */
class State extends AbstractScopeConfig implements HttpPostActionInterface, HttpGetActionInterface
{
    /**
     * @var \Magento\Framework\Controller\Result\RawFactory
     */
    protected $resultRawFactory;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Config\Model\Config\Structure $configStructure
     * @param \Magento\Config\Controller\Adminhtml\System\ConfigSectionChecker $sectionChecker
     * @param \Magento\Config\Model\Config $backendConfig
     * @param \Magento\Framework\Controller\Result\RawFactory $resultRawFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Config\Model\Config\Structure $configStructure,
        \Magento\Config\Controller\Adminhtml\System\ConfigSectionChecker $sectionChecker,
        \Magento\Config\Model\Config $backendConfig,
        \Magento\Framework\Controller\Result\RawFactory $resultRawFactory
    ) {
        parent::__construct($context, $configStructure, $sectionChecker, $backendConfig);
        $this->resultRawFactory = $resultRawFactory;
    }

    /**
     * Save fieldset state through AJAX
     *
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function execute()
    {
        if ($this->getRequest()->getParam('isAjax')
            && $this->getRequest()->getParam('container') != ''
            && $this->getRequest()->getParam('value') != ''
        ) {
            $configState = [$this->getRequest()->getParam('container') => $this->getRequest()->getParam('value')];
            $this->_saveState($configState);
            /** @var \Magento\Framework\Controller\Result\Raw $resultRaw */
            $resultRaw = $this->resultRawFactory->create();
            return $resultRaw->setContents('success');
        }
    }
}
