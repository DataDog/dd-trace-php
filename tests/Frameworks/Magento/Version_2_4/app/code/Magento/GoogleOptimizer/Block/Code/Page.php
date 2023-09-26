<?php
/**
 * Google Optimizer Page Block
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\GoogleOptimizer\Block\Code;

/**
 * @api
 * @since 100.0.2
 */
class Page extends \Magento\GoogleOptimizer\Block\AbstractCode
{
    /**
     * @var \Magento\Cms\Model\Page
     */
    protected $_page;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\GoogleOptimizer\Helper\Data $helper
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\GoogleOptimizer\Helper\Code $codeHelper
     * @param \Magento\Cms\Model\Page $page
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\GoogleOptimizer\Helper\Data $helper,
        \Magento\Framework\Registry $registry,
        \Magento\GoogleOptimizer\Helper\Code $codeHelper,
        \Magento\Cms\Model\Page $page,
        array $data = []
    ) {
        // \Magento\Cms\Model\Page is singleton
        $this->_page = $page;
        parent::__construct($context, $helper, $registry, $codeHelper, $data);
    }

    /**
     * Get cms page entity
     *
     * @return \Magento\Cms\Model\Page
     */
    protected function _getEntity()
    {
        return $this->_page;
    }
}
