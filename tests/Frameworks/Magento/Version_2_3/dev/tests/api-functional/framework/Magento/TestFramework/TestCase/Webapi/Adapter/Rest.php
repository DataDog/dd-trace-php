<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\TestFramework\TestCase\Webapi\Adapter;

use Magento\TestFramework\Helper\Bootstrap;
use Magento\Framework\Webapi\Rest\Request;
use Magento\TestFramework\Authentication\OauthHelper;

/**
 * Test client for REST API testing.
 */
class Rest implements \Magento\TestFramework\TestCase\Webapi\AdapterInterface
{
    /** @var \Magento\Webapi\Model\Config */
    protected $_config;

    /** @var \Magento\Integration\Model\Oauth\Consumer */
    protected static $_consumer;

    /** @var \Magento\Integration\Model\Oauth\Token */
    protected static $_token;

    /** @var string */
    protected static $_consumerKey;

    /** @var string */
    protected static $_consumerSecret;

    /** @var string */
    protected static $_verifier;

    /** @var \Magento\TestFramework\TestCase\Webapi\Adapter\Rest\RestClient */
    protected $restClient;

    /** @var \Magento\TestFramework\TestCase\Webapi\Adapter\Rest\DocumentationGenerator */
    protected $documentationGenerator;

    /** @var string */
    protected $defaultStoreCode;

    /**
     * Initialize dependencies.
     */
    public function __construct()
    {
        /** @var $objectManager \Magento\TestFramework\ObjectManager */
        $objectManager = Bootstrap::getObjectManager();
        $this->_config = $objectManager->get(\Magento\Webapi\Model\Config::class);
        $this->restClient = $objectManager->get(\Magento\TestFramework\TestCase\Webapi\Adapter\Rest\RestClient::class);
        $this->documentationGenerator = $objectManager->get(
            \Magento\TestFramework\TestCase\Webapi\Adapter\Rest\DocumentationGenerator::class
        );
        $this->defaultStoreCode = Bootstrap::getObjectManager()
            ->get(\Magento\Store\Model\StoreManagerInterface::class)
            ->getStore()
            ->getCode();
    }

    /**
     * {@inheritdoc}
     * @throws \LogicException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function call($serviceInfo, $arguments = [], $storeCode = null, $integration = null)
    {
        $storeCode = $storeCode !== null ? (string)$storeCode : $this->defaultStoreCode;
        $resourcePath = '/' . $storeCode . $this->_getRestResourcePath($serviceInfo);
        $httpMethod = $this->_getRestHttpMethod($serviceInfo);
        //Get a valid token
        $accessCredentials = OauthHelper::getApiAccessCredentials(null, $integration);
        /** @var $oAuthClient \Magento\TestFramework\Authentication\Rest\OauthClient */
        $oAuthClient = $accessCredentials['oauth_client'];
        $urlFormEncoded = false;
        // we're always using JSON
        $authHeader = [];
        $restServiceInfo = $serviceInfo['rest'];
        if (array_key_exists('token', $restServiceInfo)) {
            $authHeader = $oAuthClient->buildBearerTokenAuthorizationHeader($restServiceInfo['token']);
        } else {
            $authHeader = $oAuthClient->buildOauthAuthorizationHeader(
                $this->restClient->constructResourceUrl($resourcePath),
                $accessCredentials['key'],
                $accessCredentials['secret'],
                ($httpMethod == 'PUT' || $httpMethod == 'POST') && $urlFormEncoded ? $arguments : [],
                $httpMethod
            );
        }
        $authHeader = array_merge($authHeader, ['Accept: application/json', 'Content-Type: application/json']);
        switch ($httpMethod) {
            case Request::HTTP_METHOD_GET:
                $response = $this->restClient->get($resourcePath, [], $authHeader);
                break;
            case Request::HTTP_METHOD_POST:
                $response = $this->restClient->post($resourcePath, $arguments, $authHeader);
                break;
            case Request::HTTP_METHOD_PUT:
                $response = $this->restClient->put($resourcePath, $arguments, $authHeader);
                break;
            case Request::HTTP_METHOD_DELETE:
                $response = $this->restClient->delete($resourcePath, $authHeader);
                break;
            default:
                throw new \LogicException("HTTP method '{$httpMethod}' is not supported.");
        }
        if (defined('GENERATE_REST_DOCUMENTATION') && GENERATE_REST_DOCUMENTATION) {
            $this->documentationGenerator->generateDocumentation($httpMethod, $resourcePath, $arguments, $response);
        }
        return $response;
    }

    /**
     * Retrieve REST endpoint from $serviceInfo array and return it to the caller.
     *
     * @param array $serviceInfo
     * @return string
     * @throws \Exception
     */
    protected function _getRestResourcePath($serviceInfo)
    {
        if (isset($serviceInfo['rest']['resourcePath'])) {
            $resourcePath = $serviceInfo['rest']['resourcePath'];
        }
        if (!isset($resourcePath)) {
            throw new \Exception("REST endpoint cannot be identified.");
        }
        return $resourcePath;
    }

    /**
     * Retrieve HTTP method to be used in REST request.
     *
     * @param array $serviceInfo
     * @return string
     * @throws \Exception
     */
    protected function _getRestHttpMethod($serviceInfo)
    {
        if (isset($serviceInfo['rest']['httpMethod'])) {
            $httpMethod = $serviceInfo['rest']['httpMethod'];
        }
        if (!isset($httpMethod)) {
            throw new \Exception("REST HTTP method cannot be identified.");
        }
        return $httpMethod;
    }
}
