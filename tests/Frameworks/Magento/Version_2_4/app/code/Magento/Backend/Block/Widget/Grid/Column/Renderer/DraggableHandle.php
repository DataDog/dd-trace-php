<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Block\Widget\Grid\Column\Renderer;

/**
 * @api
 * @deprecated 100.2.0 in favour of UI component implementation
 * @since 100.0.2
 */
class DraggableHandle extends \Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer
{
    /**
     * Render grid row
     *
     * @param \Magento\Framework\DataObject $row
     * @return string
     */
    public function render(\Magento\Framework\DataObject $row)
    {
        return '<span class="' .
            $this->getColumn()->getInlineCss() .
            '"></span>' .
            '<input type="hidden" name="entity_id" value="' .
            $row->getData(
                $this->getColumn()->getIndex()
            ) . '"/>' . '<input type="hidden" name="position" value=""/>';
    }
}
