<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Product additional info block
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 */
namespace Magento\Catalog\Block\Product\View;

/**
 * @api
 * @since 100.0.2
 */
class Additional extends \Magento\Framework\View\Element\Template
{
    /**
     * @var array
     */
    protected $_list;

    /**
     * @var string
     */
    protected $_template = 'Magento_Catalog::product/view/additional.phtml';

    /**
     * @return array
     */
    public function getChildHtmlList()
    {
        if ($this->_list === null) {
            $this->_list = [];
            $layout = $this->getLayout();
            foreach ($this->getChildNames() as $name) {
                $this->_list[] = $layout->renderElement($name);
            }
        }
        return $this->_list;
    }
}
