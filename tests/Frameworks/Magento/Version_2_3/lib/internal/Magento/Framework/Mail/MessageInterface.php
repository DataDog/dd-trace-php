<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Mail;

/**
 * Mail Message interface
 *
 * @api
 * @deprecated 102.0.0 in favor of MailMessageInterface to avoid temporal coupling (setMessageType + setBody)
 * @see \Magento\Framework\Mail\MailMessageInterface
 * @since 100.0.2
 */
interface MessageInterface
{
    /**
     * Types of message
     * @deprecated
     */
    const TYPE_TEXT = 'text/plain';

    /**
     * @deprecated
     */
    const TYPE_HTML = 'text/html';

    /**
     * Set message subject
     *
     * @param string $subject
     * @return $this
     */
    public function setSubject($subject);

    /**
     * Get message subject
     *
     * @return string
     */
    public function getSubject();

    /**
     * Set message body
     *
     * @param mixed $body
     * @return $this
     *
     * @deprecated 102.0.0
     * @see \Magento\Framework\Mail\MailMessageInterface::setBodyHtml
     * @see \Magento\Framework\Mail\MailMessageInterface::setBodyText()
     */
    public function setBody($body);

    /**
     * Get message body
     *
     * @return string
     */
    public function getBody();

    /**
     * Set from address
     *
     * @param string|array $fromAddress
     * @return $this
     */
    public function setFrom($fromAddress);

    /**
     * Add to address
     *
     * @param string|array $toAddress
     * @return $this
     */
    public function addTo($toAddress);

    /**
     * Add cc address
     *
     * @param string|array $ccAddress
     * @return $this
     */
    public function addCc($ccAddress);

    /**
     * Add bcc address
     *
     * @param string|array $bccAddress
     * @return $this
     */
    public function addBcc($bccAddress);

    /**
     * Set reply-to address
     *
     * @param string|array $replyToAddress
     * @return $this
     */
    public function setReplyTo($replyToAddress);

    /**
     * Set message type
     *
     * @param string $type
     * @return $this
     *
     * @deprecated 102.0.0
     * @see \Magento\Framework\Mail\MailMessageInterface::setBodyHtml
     * @see \Magento\Framework\Mail\MailMessageInterface::getBodyHtml
     * @see \Magento\Framework\Mail\MailMessageInterface::setBodyText()
     * @see \Magento\Framework\Mail\MailMessageInterface::getBodyText()
     */
    public function setMessageType($type);
}
