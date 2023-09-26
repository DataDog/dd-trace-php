<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Model\SignifydGateway\Response;

use Magento\Framework\App\Request\Http;

/**
 *  Reads Signifyd webhook request data.
 *
 * @deprecated 100.3.5 Starting from Magento 2.3.5 Signifyd core integration is deprecated in favor of
 * official Signifyd integration available on the marketplace
 */
class WebhookRequest
{
    /**
     * @var Http
     */
    private $request;

    /**
     * @param Http $request
     */
    public function __construct(
        Http $request
    ) {
        $this->request = $request;
    }

    /**
     * Returns Base64 encoded output of the HMAC SHA256 encoding of the JSON body of the message.
     *
     * @return string
     */
    public function getHash()
    {
        return (string)$this->request->getHeader('X-SIGNIFYD-SEC-HMAC-SHA256');
    }

    /**
     * Returns event topic identifier.
     *
     * @return string
     */
    public function getEventTopic()
    {
        return (string)$this->request->getHeader('X-SIGNIFYD-TOPIC');
    }

    /**
     * Returns raw data from the request body.
     *
     * @return string
     */
    public function getBody()
    {
        return (string)$this->request->getContent();
    }
}
