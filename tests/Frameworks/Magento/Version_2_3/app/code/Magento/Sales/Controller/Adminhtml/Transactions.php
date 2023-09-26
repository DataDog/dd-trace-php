<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\Result\LayoutFactory;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;

/**
 * Adminhtml sales transactions controller
 *
 * @author Magento Core Team <core@magentocommerce.com>
 */
abstract class Transactions extends \Magento\Backend\App\Action
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Sales::transactions';

    /**
     * Core registry
     *
     * @var Registry
     */
    protected $_coreRegistry = null;

    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var LayoutFactory
     */
    protected $resultLayoutFactory;

    /**
     * @var OrderPaymentRepositoryInterface
     */
    protected $orderPaymentRepository;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param Registry $coreRegistry
     * @param PageFactory $resultPageFactory
     * @param LayoutFactory $resultLayoutFactory
     * @param OrderPaymentRepositoryInterface $orderPaymentRepository
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        Registry $coreRegistry,
        PageFactory $resultPageFactory,
        LayoutFactory $resultLayoutFactory,
        OrderPaymentRepositoryInterface $orderPaymentRepository
    ) {
        $this->_coreRegistry = $coreRegistry;
        $this->resultPageFactory = $resultPageFactory;
        $this->resultLayoutFactory = $resultLayoutFactory;
        $this->orderPaymentRepository = $orderPaymentRepository;
        parent::__construct($context);
    }

    /**
     * Initialize payment transaction model
     *
     * @return \Magento\Sales\Model\Order\Payment\Transaction|bool
     */
    protected function _initTransaction()
    {
        $txn = $this->_objectManager->create(
            \Magento\Sales\Model\Order\Payment\Transaction::class
        )->load(
            $this->getRequest()->getParam('txn_id')
        );

        if (!$txn->getId()) {
            $this->messageManager->addErrorMessage(__('Please correct the transaction ID and try again.'));
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
            return false;
        }
        $orderId = $this->getRequest()->getParam('order_id');
        if ($orderId) {
            $txn->setOrderUrl($this->getUrl('sales/order/view', ['order_id' => $orderId]));
        }

        $this->_coreRegistry->register('current_transaction', $txn);
        return $txn;
    }
}
