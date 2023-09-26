<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * System configuration comment model interface
 */
namespace Magento\Config\Model\Config;

/**
 * @api
 * @since 100.0.2
 */
interface CommentInterface
{
    /**
     * Retrieve element comment by element value
     * @param string $elementValue
     * @return string
     */
    public function getCommentText($elementValue);
}
