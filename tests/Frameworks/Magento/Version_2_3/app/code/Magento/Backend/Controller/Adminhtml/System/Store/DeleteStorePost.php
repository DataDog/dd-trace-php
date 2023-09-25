<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Controller\Adminhtml\System\Store;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Delete store view.
 */
class DeleteStorePost extends \Magento\Backend\Controller\Adminhtml\System\Store implements HttpPostActionInterface
{
    /**
     * Delete store view post action
     *
     * @return \Magento\Backend\Model\View\Result\Redirect
     */
    public function execute()
    {
        $itemId = $this->getRequest()->getParam('item_id');

        /** @var \Magento\Backend\Model\View\Result\Redirect $redirectResult */
        $redirectResult = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        if (!($model = $this->_objectManager->create(\Magento\Store\Model\Store::class)->load($itemId))) {
            $this->messageManager->addErrorMessage(__('Something went wrong. Please try again.'));
            return $redirectResult->setPath('adminhtml/*/');
        }
        if (!$model->isCanDelete()) {
            $this->messageManager->addErrorMessage(__('This store view cannot be deleted.'));
            return $redirectResult->setPath('adminhtml/*/editStore', ['store_id' => $model->getId()]);
        }

        if (!$this->_backupDatabase()) {
            return $redirectResult->setPath('*/*/editStore', ['store_id' => $itemId]);
        }

        try {
            $model->delete();

            $this->messageManager->addSuccessMessage(__('You deleted the store view.'));
            return $redirectResult->setPath('adminhtml/*/');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager
                ->addExceptionMessage($e, __('Unable to delete the store view. Please try again later.'));
        }
        return $redirectResult->setPath('adminhtml/*/editStore', ['store_id' => $itemId]);
    }
}
