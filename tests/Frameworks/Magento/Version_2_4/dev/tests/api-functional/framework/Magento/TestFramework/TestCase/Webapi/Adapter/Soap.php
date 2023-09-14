<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\TestFramework\TestCase\Webapi\Adapter;

use Magento\Framework\Api\SimpleDataObjectConverter;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Webapi\Controller\Soap\Request\Handler as SoapHandler;

/**
 * Test client for SOAP API testing.
 */
class Soap implements \Magento\TestFramework\TestCase\Webapi\AdapterInterface
{
    const WSDL_BASE_PATH = '/soap';

    /**
     * SOAP client initialized with different WSDLs.
     *
     * @var \Laminas\Soap\Client[]
     */
    protected $_soapClients = ['custom' => [], 'default' => []];

    /**
     * @var \Magento\Webapi\Model\Soap\Config
     */
    protected $_soapConfig;

    /**
     * @var SimpleDataObjectConverter
     */
    protected $_converter;

    /**
     * Initialize dependencies.
     */
    public function __construct()
    {
        /** @var $objectManager \Magento\TestFramework\ObjectManager */
        $objectManager = Bootstrap::getObjectManager();
        $this->_soapConfig = $objectManager->get(\Magento\Webapi\Model\Soap\Config::class);
        $this->_converter = $objectManager->get(\Magento\Framework\Api\SimpleDataObjectConverter::class);
        ini_set('default_socket_timeout', 120);
    }

    /**
     * @inheritdoc
     */
    public function call($serviceInfo, $arguments = [], $storeCode = null, $integration = null)
    {
        $soapOperation = $this->_getSoapOperation($serviceInfo);
        $arguments = $this->_converter->convertKeysToCamelCase($arguments);
        $soapResponse = $this->_getSoapClient($serviceInfo, $storeCode)->$soapOperation($arguments);
        //Convert to snake case for tests to use same assertion data for both SOAP and REST tests
        $result = (is_array($soapResponse) || is_object($soapResponse))
            ? $this->toSnakeCase($this->_converter->convertStdObjectToArray($soapResponse, true))
            : $soapResponse;
        /** Remove result wrappers */
        $result = isset($result[SoapHandler::RESULT_NODE_NAME]) ? $result[SoapHandler::RESULT_NODE_NAME] : $result;
        return $result;
    }

    /**
     * Get proper SOAP client instance that is initialized with WSDL corresponding to requested service interface.
     *
     * @param string $serviceInfo PHP service interface name, should include version if present
     * @param string|null $storeCode
     * @return \Laminas\Soap\Client
     */
    protected function _getSoapClient($serviceInfo, $storeCode = null)
    {
        $wsdlUrl = $this->generateWsdlUrl(
            [$this->_getSoapServiceName($serviceInfo) . $this->_getSoapServiceVersion($serviceInfo)],
            $storeCode
        );
        /** @var \Laminas\Soap\Client $soapClient */
        $soapClient = null;
        if (isset($serviceInfo['soap']['token'])) {
            $token = $serviceInfo['soap']['token'];
            if (array_key_exists($token, $this->_soapClients['custom'])
                && array_key_exists($wsdlUrl, $this->_soapClients['custom'][$token])
            ) {
                $soapClient = $this->_soapClients['custom'][$token][$wsdlUrl];
            } else {
                if (!array_key_exists($token, $this->_soapClients['custom'])) {
                    $this->_soapClients['custom'][$token] = [];
                }
                $soapClient = $this->_soapClients['custom'][$token][$wsdlUrl]
                    = $this->instantiateSoapClient($wsdlUrl, $token);
            }
        } else {
            if (!isset($this->_soapClients[$wsdlUrl])) {
                $this->_soapClients['default'][$wsdlUrl] = $this->instantiateSoapClient($wsdlUrl, null);
            }
            $soapClient = $this->_soapClients['default'][$wsdlUrl];
        }
        return $soapClient;
    }

    /**
     * Create SOAP client instance and initialize it with provided WSDL URL.
     *
     * @param string $wsdlUrl
     * @param string $token Authentication token
     * @return \Laminas\Soap\Client
     */
    public function instantiateSoapClient($wsdlUrl, $token = null)
    {
        $accessCredentials = $token
            ? $token
            : \Magento\TestFramework\Authentication\OauthHelper::getApiAccessCredentials()['key'];
        $opts = ['http' => ['header' => "Authorization: Bearer " . $accessCredentials]];
        $context = stream_context_create($opts);
        $soapClient = new \Laminas\Soap\Client($wsdlUrl);
        $soapClient->setSoapVersion(SOAP_1_2);
        $soapClient->setStreamContext($context);
        if (TESTS_XDEBUG_ENABLED) {
            $soapClient->setCookie('XDEBUG_SESSION', 1);
        }
        return $soapClient;
    }

    /**
     * Generate WSDL URL.
     *
     * @param array $services e.g.<pre>
     * array(
     *     'catalogProductV1',
     *     'customerV2'
     * );</pre>
     * @param string|null $storeCode
     * @return string
     */
    public function generateWsdlUrl($services, $storeCode = null)
    {
        /** Sort list of services to avoid having different WSDL URLs for the identical lists of services. */
        //TODO: This may change since same resource of multiple versions may be allowed after namespace changes
        ksort($services);
        if ($storeCode == null) {
            $storeCode = Bootstrap::getObjectManager()
                ->get(\Magento\Store\Model\StoreManagerInterface::class)
                ->getStore()
                ->getCode();
        }

        /** TESTS_BASE_URL is initialized in PHPUnit configuration */
        $wsdlUrl = rtrim(TESTS_BASE_URL, '/') . self::WSDL_BASE_PATH . '/' . $storeCode . '?wsdl=1&services=';
        $wsdlResourceArray = [];
        foreach ($services as $serviceName) {
            $wsdlResourceArray[] = $serviceName;
        }
        return $wsdlUrl . implode(",", $wsdlResourceArray);
    }

    /**
     * Retrieve SOAP operation name from available service info.
     *
     * @param array $serviceInfo
     * @return string
     * @throws \LogicException
     */
    protected function _getSoapOperation($serviceInfo)
    {
        if (isset($serviceInfo['soap']['operation'])) {
            $soapOperation = $serviceInfo['soap']['operation'];
        } elseif (isset($serviceInfo['serviceInterface']) && isset($serviceInfo['method'])) {
            $soapOperation = $this->_soapConfig->getSoapOperation(
                $serviceInfo['serviceInterface'],
                $serviceInfo['method']
            );
        } else {
            throw new \LogicException("SOAP operation cannot be identified.");
        }
        return $soapOperation;
    }

    /**
     * Retrieve service version from available service info.
     *
     * @param array $serviceInfo
     * @return string
     * @throws \LogicException
     */
    protected function _getSoapServiceVersion($serviceInfo)
    {
        if (isset($serviceInfo['soap']['operation'])) {
            /*
                TODO: Need to rework this to remove version call for serviceInfo array with 'operation' key
                since version will be part of the service name
            */
            return '';
        } elseif (isset($serviceInfo['serviceInterface'])) {
            preg_match(
                \Magento\Webapi\Model\Config::SERVICE_CLASS_PATTERN,
                $serviceInfo['serviceInterface'],
                $matches
            );
            if (isset($matches[3])) {
                $version = $matches[3];
            } else {
                //TODO: Need to add this temporary version until version is added back for new MSC based services
                $version = 1;
                //throw new \LogicException("Service interface name is invalid.");
            }
        } else {
            throw new \LogicException("Service version cannot be identified.");
        }
        /** Normalize version */
        $version = 'V' . ltrim($version, 'vV');
        return $version;
    }

    /**
     * Retrieve service name from available service info.
     *
     * @param array $serviceInfo
     * @return string
     * @throws \LogicException
     */
    protected function _getSoapServiceName($serviceInfo)
    {
        if (isset($serviceInfo['soap']['service'])) {
            $serviceName = $serviceInfo['soap']['service'];
        } elseif (isset($serviceInfo['serviceInterface'])) {
            $serviceName = $this->_soapConfig->getServiceName($serviceInfo['serviceInterface'], false);
        } else {
            throw new \LogicException("Service name cannot be identified.");
        }
        return $serviceName;
    }

    /**
     * Recursively transform array keys from camelCase to snake_case.
     *
     * Utility method for converting SOAP responses. Webapi framework's SOAP processing outputs
     * snake case Data Object properties(ex. item_id) as camel case(itemId) to adhere to the WSDL.
     * This method allows tests to use the same data for asserting both SOAP and REST responses.
     *
     * @param array $objectData An array of data.
     * @return array The array with all camelCase keys converted to snake_case.
     */
    protected function toSnakeCase(array $objectData)
    {
        $data = [];
        foreach ($objectData as $key => $value) {
            $key = strtolower(preg_replace("/(?<=\\w)(?=[A-Z])/", "_$1", $key));
            if (is_array($value)) {
                $data[$key] = $this->toSnakeCase($value);
            } else {
                $data[$key] = $value;
            }
        }
        return $data;
    }
}
