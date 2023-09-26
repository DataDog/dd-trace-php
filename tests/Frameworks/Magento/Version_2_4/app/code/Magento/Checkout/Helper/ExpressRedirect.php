<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Checkout\Helper;

use Magento\Checkout\Controller\Express\RedirectLoginInterface;

class ExpressRedirect extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * @var \Magento\Framework\App\ActionFlag
     */
    protected $_actionFlag;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * Customer session
     *
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;

    /**
     * @param \Magento\Framework\App\ActionFlag $actionFlag
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Framework\App\Helper\Context $context
     * @codeCoverageIgnore
     */
    public function __construct(
        \Magento\Framework\App\ActionFlag $actionFlag,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\App\Helper\Context $context
    ) {
        $this->_actionFlag = $actionFlag;
        $this->_objectManager = $objectManager;
        $this->_customerSession = $customerSession;

        parent::__construct($context);
    }

    /**
     * Performs redirect to login for checkout
     * @param RedirectLoginInterface $expressRedirect
     * @param string|null $customerBeforeAuthUrlDefault
     * @return void
     */
    public function redirectLogin(RedirectLoginInterface $expressRedirect, $customerBeforeAuthUrlDefault = null)
    {
        $this->_actionFlag->set('', 'no-dispatch', true);
        foreach ($expressRedirect->getActionFlagList() as $actionKey => $actionFlag) {
            $this->_actionFlag->set('', $actionKey, $actionFlag);
        }

        $expressRedirect->getResponse()->setRedirect(
            $this->_objectManager->get(
                \Magento\Framework\Url\Helper\Data::class
            )->addRequestParam(
                $expressRedirect->getLoginUrl(),
                ['context' => 'checkout']
            )
        );

        $customerBeforeAuthUrl = $customerBeforeAuthUrlDefault;
        if ($expressRedirect->getCustomerBeforeAuthUrl()) {
            $customerBeforeAuthUrl = $expressRedirect->getCustomerBeforeAuthUrl();
        }
        if ($customerBeforeAuthUrl) {
            $this->_customerSession->setBeforeAuthUrl($customerBeforeAuthUrl);
        }
    }
}
