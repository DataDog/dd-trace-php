<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Block\Adminhtml\System\Config\Fieldset;

use Magento\Config\Block\System\Config\Form\Fieldset;

/**
 * Fieldset renderer with url attached to comment.
 *
 * @deprecated 100.3.5 Starting from Magento 2.3.5 Signifyd core integration is deprecated in favor of
 * official Signifyd integration available on the marketplace
 */
class Info extends Fieldset
{
    /**
     * @inheritdoc
     */
    protected function _getHeaderCommentHtml($element)
    {
        $groupConfig = $element->getGroup();

        if (!empty($groupConfig['more_url']) && !empty($element->getComment())) {
            $comment = $element->getComment();
            $comment .= '<p><a href="' . $this->escapeUrl($groupConfig['more_url']) . '" target="_blank">' .
                $this->escapeHtml(__('Learn more')) . '</a></p>';
            $element->setComment($comment);
        }

        return parent::_getHeaderCommentHtml($element);
    }
}
