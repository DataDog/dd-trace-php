<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Controller\Adminhtml\Product;

use Magento\Framework\App\Action\HttpGetActionInterface;

/**
 * Get specified tab grid controller.
 */
class GridOnly extends \Magento\Catalog\Controller\Adminhtml\Product implements HttpGetActionInterface
{
    /**
     * @var \Magento\Framework\Controller\Result\RawFactory
     */
    protected $resultRawFactory;

    /**
     * @var \Magento\Framework\View\LayoutFactory
     */
    protected $layoutFactory;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Catalog\Controller\Adminhtml\Product\Builder $productBuilder
     * @param \Magento\Framework\Controller\Result\RawFactory $resultRawFactory
     * @param \Magento\Framework\View\LayoutFactory $layoutFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Catalog\Controller\Adminhtml\Product\Builder $productBuilder,
        \Magento\Framework\Controller\Result\RawFactory $resultRawFactory,
        \Magento\Framework\View\LayoutFactory $layoutFactory
    ) {
        parent::__construct($context, $productBuilder);
        $this->resultRawFactory = $resultRawFactory;
        $this->layoutFactory = $layoutFactory;
    }

    /**
     * Get specified tab grid
     *
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function execute()
    {
        $this->_view->getPage()->getConfig()->getTitle()->prepend(__('Products'));

        $this->productBuilder->build($this->getRequest());

        $block = $this->getRequest()->getParam('gridOnlyBlock');
        $blockClassSuffix = ucwords($block, '_');

        /** @var \Magento\Framework\Controller\Result\Raw $resultRaw */
        $resultRaw = $this->resultRawFactory->create();
        return $resultRaw->setContents(
            $this->layoutFactory->create()->createBlock(
                'Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\\' . $blockClassSuffix
            )->toHtml()
        );
    }
}
