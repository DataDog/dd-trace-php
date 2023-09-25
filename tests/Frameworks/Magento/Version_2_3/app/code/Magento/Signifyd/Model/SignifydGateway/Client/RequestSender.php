<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Model\SignifydGateway\Client;

use Magento\Signifyd\Model\SignifydGateway\Debugger\DebuggerFactory;
use Magento\Signifyd\Model\SignifydGateway\ApiCallException;
use Magento\Framework\HTTP\ZendClient;

/**
 * Class RequestSender
 * Gets HTTP client end sends request to Signifyd API
 *
 * @deprecated 100.3.5 Starting from Magento 2.3.5 Signifyd core integration is deprecated in favor of
 * official Signifyd integration available on the marketplace
 */
class RequestSender
{
    /**
     * @var DebuggerFactory
     */
    private $debuggerFactory;

    /**
     * RequestSender constructor.
     *
     * @param DebuggerFactory $debuggerFactory
     */
    public function __construct(
        DebuggerFactory $debuggerFactory
    ) {
        $this->debuggerFactory = $debuggerFactory;
    }

    /**
     * Sends HTTP request to Signifyd API with configured client.
     *
     * Each request/response pair is handled by debugger.
     * If debug mode for Signifyd integration enabled in configuration
     * debug information is recorded to debug.log.
     *
     * @param ZendClient $client
     * @param int|null $storeId
     * @return \Zend_Http_Response
     * @throws ApiCallException
     */
    public function send(ZendClient $client, $storeId = null): \Zend_Http_Response
    {
        try {
            $response = $client->request();

            $this->debuggerFactory->create($storeId)->success(
                $client->getUri(true),
                $client->getLastRequest(),
                $response->getStatus() . ' ' . $response->getMessage(),
                $response->getBody()
            );

            return $response;
        } catch (\Exception $e) {
            $this->debuggerFactory->create($storeId)->failure(
                $client->getUri(true),
                $client->getLastRequest(),
                $e
            );

            throw new ApiCallException(
                'Unable to process Signifyd API: ' . $e->getMessage(),
                $e->getCode(),
                $e,
                $client->getLastRequest()
            );
        }
    }
}
