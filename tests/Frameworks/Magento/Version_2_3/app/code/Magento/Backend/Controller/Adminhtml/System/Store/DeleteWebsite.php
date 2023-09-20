<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Controller\Adminhtml\System\Store;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;

class DeleteWebsite extends \Magento\Backend\Controller\Adminhtml\System\Store implements HttpGetActionInterface
{
    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $itemId = $this->getRequest()->getParam('item_id', null);
        if (!($model = $this->_objectManager->create(\Magento\Store\Model\Website::class)->load($itemId))) {
            $this->messageManager->addErrorMessage(__('Something went wrong. Please try again.'));
            /** @var \Magento\Backend\Model\View\Result\Redirect $redirectResult */
            $redirectResult = $this->resultRedirectFactory->create();
            return $redirectResult->setPath('adminhtml/*/');
        }
        if (!$model->isCanDelete()) {
            $this->messageManager->addErrorMessage(__('This website cannot be deleted.'));
            /** @var \Magento\Backend\Model\View\Result\Redirect $redirectResult */
            $redirectResult = $this->resultRedirectFactory->create();
            return $redirectResult->setPath('adminhtml/*/editWebsite', ['website_id' => $itemId]);
        }

        $this->_addDeletionNotice('website');

        $resultPage = $this->createPage();
        $resultPage->getConfig()->getTitle()->prepend(__('Delete Web Site'));
        $resultPage->addBreadcrumb(__('Delete Web Site'), __('Delete Web Site'))
            ->addContent(
                $resultPage->getLayout()->createBlock(\Magento\Backend\Block\System\Store\Delete::class)
                    ->setFormActionUrl($this->getUrl('adminhtml/*/deleteWebsitePost'))
                    ->setBackUrl($this->getUrl('adminhtml/*/editWebsite', ['website_id' => $itemId]))
                    ->setStoreTypeTitle(__('Web Site'))->setDataObject($model)
            );
        return $resultPage;
    }
}
