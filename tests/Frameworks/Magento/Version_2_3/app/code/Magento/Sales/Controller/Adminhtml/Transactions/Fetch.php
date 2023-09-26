<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Controller\Adminhtml\Transactions;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

class Fetch extends \Magento\Sales\Controller\Adminhtml\Transactions
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Sales::transactions_fetch';

    /**
     * Fetch transaction details action
     *
     * @return Redirect
     */
    public function execute()
    {
        $txn = $this->_initTransaction();
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        if (!$txn) {
            return $resultRedirect->setPath('sales/*/');
        }
        try {
            $this->orderPaymentRepository
                ->get($txn->getPaymentId())
                ->setOrder($txn->getOrder())
                ->importTransactionInfo($txn);
            $txn->save();
            $this->messageManager->addSuccessMessage(__('The transaction details have been updated.'));
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('We can\'t update the transaction details.'));
            $this->_objectManager->get(\Psr\Log\LoggerInterface::class)->critical($e);
        }

        return $resultRedirect->setPath('sales/transactions/view', ['_current' => true]);
    }
}
