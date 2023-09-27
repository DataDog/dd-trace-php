<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Controller\Adminhtml\Product\Action\Attribute;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Controller\Adminhtml\Product\Action\Attribute as AttributeAction;

/**
 * Form for mass updatings products' attributes.
 * Can be accessed by GET since it's a form,
 * can be accessed by POST since it's used as a processor of a mass-action button.
 */
class Edit extends AttributeAction implements HttpGetActionInterface, HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    /**
     * MassActions filter
     *
     * @var Filter
     */
    protected $filter;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Catalog\Helper\Product\Edit\Action\Attribute $attributeHelper
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Catalog\Helper\Product\Edit\Action\Attribute $attributeHelper,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        Filter $filter,
        CollectionFactory $collectionFactory
    ) {
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        parent::__construct($context, $attributeHelper);
        $this->resultPageFactory = $resultPageFactory;
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        if ($this->getRequest()->getParam('filters')) {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $this->attributeHelper->setProductIds($collection->getAllIds());
        }

        if (!$this->_validateProducts()) {
            return $this->resultRedirectFactory->create()->setPath('catalog/product/', ['_current' => true]);
        }
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('Update Attributes'));
        return $resultPage;
    }
}
