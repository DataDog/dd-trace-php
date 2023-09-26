<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Newsletter\Controller\Adminhtml\Template;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Newsletter\Controller\Adminhtml\Template;

/**
 * View a rendered template.
 */
class Preview extends Template implements HttpPostActionInterface, HttpGetActionInterface
{
    /**
     * Preview Newsletter template
     *
     * @return void|$this
     */
    public function execute()
    {
        $this->_view->loadLayout();

        $data = $this->getRequest()->getParams();
        $isEmptyRequestData = empty($data) || !isset($data['id']);
        $isEmptyPreviewData = !$this->_getSession()->hasPreviewData() || empty($this->_getSession()->getPreviewData());

        if ($isEmptyRequestData && $isEmptyPreviewData) {
            $this->_forward('noroute');
            return $this;
        }

        // set default value for selected store
        /** @var \Magento\Store\Model\StoreManager $storeManager */
        $storeManager = $this->_objectManager->get(\Magento\Store\Model\StoreManager::class);
        $defaultStore = $storeManager->getDefaultStoreView();
        if (!$defaultStore) {
            $allStores = $storeManager->getStores();
            if (isset($allStores[0])) {
                $defaultStore = $allStores[0];
            }
        }
        $data['preview_store_id'] = $defaultStore ? $defaultStore->getId() : null;
        $this->_view->getLayout()->getBlock('preview_form')->setFormData($data);
        $this->_view->getPage()->getConfig()->getTitle()->prepend(__('Newsletter Templates'));
        $this->_view->renderLayout();
    }
}
