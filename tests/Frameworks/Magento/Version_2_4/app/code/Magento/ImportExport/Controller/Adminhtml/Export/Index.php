<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ImportExport\Controller\Adminhtml\Export;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;
use Magento\ImportExport\Controller\Adminhtml\Export as ExportController;
use Magento\Framework\Controller\ResultFactory;

class Index extends ExportController implements HttpGetActionInterface
{
    /**
     * Index action.
     *
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Magento_ImportExport::system_convert_export');
        $resultPage->getConfig()->getTitle()->prepend(__('Import/Export'));
        $resultPage->getConfig()->getTitle()->prepend(__('Export'));
        $resultPage->addBreadcrumb(__('Export'), __('Export'));
        return $resultPage;
    }
}
