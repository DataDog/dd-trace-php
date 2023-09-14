<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Api\Data;

/**
 * Interface CommentInterface
 *
 * @api
 * @since 100.1.2
 */
interface CommentInterface
{
    /*
     * Is-visible-on-storefront flag.
     */
    const IS_VISIBLE_ON_FRONT = 'is_visible_on_front';

    /*
     * Comment.
     */
    const COMMENT = 'comment';

    /**
     * Gets the comment text.
     *
     * @return string Comment.
     * @since 100.1.2
     */
    public function getComment();

    /**
     * Sets the comment text.
     *
     * @param string $comment
     * @return $this
     * @since 100.1.2
     */
    public function setComment($comment);

    /**
     * Gets the is-visible-on-storefront flag value for the comment.
     *
     * @return int Is-visible-on-storefront flag value.
     * @since 100.1.2
     */
    public function getIsVisibleOnFront();

    /**
     * Sets the is-visible-on-storefront flag value for the comment.
     *
     * @param int $isVisibleOnFront
     * @return $this
     * @since 100.1.2
     */
    public function setIsVisibleOnFront($isVisibleOnFront);
}
