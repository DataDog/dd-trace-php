<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Reports\Controller\Adminhtml\Report\Statistics;

use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;
use Magento\Reports\Controller\Adminhtml\Report\Statistics;

/**
 * Refresh statistics action.
 */
class RefreshLifetime extends Statistics implements HttpPostActionInterface
{
    /**
     * Refresh statistics for all period
     *
     * @return void
     */
    public function execute()
    {
        try {
            $collectionsNames = $this->_getCollectionNames();
            foreach ($collectionsNames as $collectionName) {
                $this->_objectManager->create($collectionName)->aggregate();
            }
            $this->messageManager->addSuccess(__('You refreshed lifetime statistics.'));
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addError($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addError(__('We can\'t refresh lifetime statistics.'));
            $this->_objectManager->get(\Psr\Log\LoggerInterface::class)->critical($e);
        }

        if ($this->_getSession()->isFirstPageAfterLogin()) {
            $this->_redirect('adminhtml/*');
        } else {
            $this->getResponse()->setRedirect($this->_redirect->getRedirectUrl('*/*'));
        }
    }
}
