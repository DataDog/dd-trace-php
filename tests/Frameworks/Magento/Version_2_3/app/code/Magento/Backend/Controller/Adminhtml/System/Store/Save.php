<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Controller\Adminhtml\System\Store;

use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;

/**
 * Class Save
 *
 * Save controller for system entities such as: Store, StoreGroup, Website
 */
class Save extends \Magento\Backend\Controller\Adminhtml\System\Store implements HttpPostActionInterface
{
    /**
     * Process Website model save
     *
     * @param array $postData
     * @return array
     */
    private function processWebsiteSave($postData)
    {
        $postData['website']['name'] = $this->filterManager->removeTags($postData['website']['name']);
        $websiteModel = $this->_objectManager->create(\Magento\Store\Model\Website::class);
        if ($postData['website']['website_id']) {
            $websiteModel->load($postData['website']['website_id']);
        }
        $websiteModel->setData($postData['website']);
        if ($postData['website']['website_id'] == '') {
            $websiteModel->setId(null);
        }

        $websiteModel->save();
        $this->messageManager->addSuccessMessage(__('You saved the website.'));

        return $postData;
    }

    /**
     * Process Store model save
     *
     * @param array $postData
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return array
     */
    private function processStoreSave($postData)
    {
        /** @var \Magento\Store\Model\Store $storeModel */
        $storeModel = $this->_objectManager->create(\Magento\Store\Model\Store::class);
        $postData['store']['name'] = $this->filterManager->removeTags($postData['store']['name']);
        if ($postData['store']['store_id']) {
            $storeModel->load($postData['store']['store_id']);
        }
        $storeModel->setData($postData['store']);
        if ($postData['store']['store_id'] == '') {
            $storeModel->setId(null);
        }
        $groupModel = $this->_objectManager->create(
            \Magento\Store\Model\Group::class
        )->load(
            $storeModel->getGroupId()
        );
        $storeModel->setWebsiteId($groupModel->getWebsiteId());
        if (!$storeModel->isActive() && $storeModel->isDefault()) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('The default store cannot be disabled')
            );
        }
        $storeModel->save();
        $this->messageManager->addSuccessMessage(__('You saved the store view.'));

        return $postData;
    }

    /**
     * Process StoreGroup model save
     *
     * @param array $postData
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return array
     */
    private function processGroupSave($postData)
    {
        $postData['group']['name'] = $this->filterManager->removeTags($postData['group']['name']);
        /** @var \Magento\Store\Model\Group $groupModel */
        $groupModel = $this->_objectManager->create(\Magento\Store\Model\Group::class);
        if ($postData['group']['group_id']) {
            $groupModel->load($postData['group']['group_id']);
        }
        $groupModel->setData($postData['group']);
        if ($postData['group']['group_id'] == '') {
            $groupModel->setId(null);
        }
        if (!$this->isSelectedDefaultStoreActive($postData, $groupModel)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('An inactive store view cannot be saved as default store view')
            );
        }
        $groupModel->save();
        $this->messageManager->addSuccessMessage(__('You saved the store.'));

        return $postData;
    }

    /**
     * @return \Magento\Backend\Model\View\Result\Redirect
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Redirect $redirectResult */
        $redirectResult = $this->resultRedirectFactory->create();
        if ($this->getRequest()->isPost() && ($postData = $this->getRequest()->getPostValue())) {
            if (empty($postData['store_type']) || empty($postData['store_action'])) {
                $redirectResult->setPath('adminhtml/*/');
                return $redirectResult;
            }
            try {
                switch ($postData['store_type']) {
                    case 'website':
                        $postData = $this->processWebsiteSave($postData);
                        break;
                    case 'group':
                        $postData = $this->processGroupSave($postData);
                        break;
                    case 'store':
                        $postData = $this->processStoreSave($postData);
                        break;
                    default:
                        $redirectResult->setPath('adminhtml/*/');
                        return $redirectResult;
                }
                $redirectResult->setPath('adminhtml/*/');
                return $redirectResult;
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                $this->_getSession()->setPostData($postData);
            } catch (\Exception $e) {
                $this->messageManager->addExceptionMessage(
                    $e,
                    __('Something went wrong while saving. Please review the error log.')
                );
                $this->_getSession()->setPostData($postData);
            }
            $redirectResult->setUrl($this->_redirect->getRedirectUrl($this->getUrl('*')));
            return $redirectResult;
        }
        $redirectResult->setPath('adminhtml/*/');
        return $redirectResult;
    }

    /**
     * Verify if selected default store is active
     *
     * @param array $postData
     * @param \Magento\Store\Model\Group $groupModel
     * @return bool
     */
    private function isSelectedDefaultStoreActive(array $postData, \Magento\Store\Model\Group $groupModel)
    {
        if (!empty($postData['group']['default_store_id'])) {
            $defaultStoreId = $postData['group']['default_store_id'];
            if (!empty($groupModel->getStores()[$defaultStoreId]) &&
                !$groupModel->getStores()[$defaultStoreId]->isActive()
            ) {
                return false;
            }
        }
        return true;
    }
}
