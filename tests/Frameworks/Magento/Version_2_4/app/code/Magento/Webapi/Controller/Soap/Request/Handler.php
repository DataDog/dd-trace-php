<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Webapi\Controller\Soap\Request;

use InvalidArgumentException;
use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\Api\MetadataObjectInterface;
use Magento\Framework\Api\SimpleDataObjectConverter;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Webapi\Authorization;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Framework\Webapi\Request as WebapiRequest;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Validator\EntityArrayValidator\InputArraySizeLimitValue;
use Magento\Webapi\Controller\Rest\ParamsOverrider;
use Magento\Webapi\Model\Soap\Config as SoapConfig;
use Magento\Framework\Reflection\MethodsMap;
use Magento\Webapi\Model\ServiceMetadata;

/**
 * Handler of requests to SOAP server.
 *
 * The main responsibility is to instantiate proper action controller (service) and execute requested method on it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Handler
{
    public const RESULT_NODE_NAME = 'result';

    /**
     * @var WebapiRequest
     */
    protected $_request;

    /**
     * @var ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var SoapConfig
     */
    protected $_apiConfig;

    /**
     * @var Authorization
     */
    protected $authorization;

    /**
     * @var SimpleDataObjectConverter
     */
    protected $_dataObjectConverter;

    /**
     * @var ServiceInputProcessor
     */
    protected $serviceInputProcessor;

    /**
     * @var DataObjectProcessor
     */
    protected $_dataObjectProcessor;

    /**
     * @var MethodsMap
     */
    protected $methodsMapProcessor;

    /**
     * @var ParamsOverrider
     */
    private $paramsOverrider;

    /**
     * @var InputArraySizeLimitValue
     */
    private $inputArraySizeLimitValue;

    /**
     * Initialize dependencies.
     *
     * @param WebapiRequest $request
     * @param ObjectManagerInterface $objectManager
     * @param SoapConfig $apiConfig
     * @param Authorization $authorization
     * @param SimpleDataObjectConverter $dataObjectConverter
     * @param ServiceInputProcessor $serviceInputProcessor
     * @param DataObjectProcessor $dataObjectProcessor
     * @param MethodsMap $methodsMapProcessor
     * @param ParamsOverrider|null $paramsOverrider
     * @param InputArraySizeLimitValue|null $inputArraySizeLimitValue
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        WebapiRequest  $request,
        ObjectManagerInterface $objectManager,
        SoapConfig $apiConfig,
        Authorization $authorization,
        SimpleDataObjectConverter $dataObjectConverter,
        ServiceInputProcessor $serviceInputProcessor,
        DataObjectProcessor $dataObjectProcessor,
        MethodsMap $methodsMapProcessor,
        ?ParamsOverrider $paramsOverrider = null,
        ?InputArraySizeLimitValue $inputArraySizeLimitValue = null
    ) {
        $this->_request = $request;
        $this->_objectManager = $objectManager;
        $this->_apiConfig = $apiConfig;
        $this->authorization = $authorization;
        $this->_dataObjectConverter = $dataObjectConverter;
        $this->serviceInputProcessor = $serviceInputProcessor;
        $this->_dataObjectProcessor = $dataObjectProcessor;
        $this->methodsMapProcessor = $methodsMapProcessor;
        $this->paramsOverrider = $paramsOverrider ?? ObjectManager::getInstance()->get(ParamsOverrider::class);
        $this->inputArraySizeLimitValue = $inputArraySizeLimitValue ?? ObjectManager::getInstance()
                ->get(InputArraySizeLimitValue::class);
    }

    /**
     * Handler for all SOAP operations.
     *
     * @param string $operation
     * @param array $arguments
     * @return array
     * @throws WebapiException
     * @throws \LogicException
     * @throws AuthorizationException
     */
    public function __call($operation, $arguments)
    {
        $requestedServices = $this->_request->getRequestedServices();
        $serviceMethodInfo = $this->_apiConfig->getServiceMethodInfo($operation, $requestedServices);
        $serviceClass = $serviceMethodInfo[ServiceMetadata::KEY_CLASS];
        $serviceMethod = $serviceMethodInfo[ServiceMetadata::KEY_METHOD];

        // check if the operation is a secure operation & whether the request was made in HTTPS
        if ($serviceMethodInfo[ServiceMetadata::KEY_IS_SECURE] && !$this->_request->isSecure()) {
            throw new WebapiException(__("Operation allowed only in HTTPS"));
        }

        if (!$this->authorization->isAllowed($serviceMethodInfo[ServiceMetadata::KEY_ACL_RESOURCES])) {
            throw new AuthorizationException(
                __(
                    "The consumer isn't authorized to access %resources.",
                    ['resources' => implode(', ', $serviceMethodInfo[ServiceMetadata::KEY_ACL_RESOURCES])]
                )
            );
        }
        $service = $this->_objectManager->get($serviceClass);
        $inputData = $this->prepareOperationInput($serviceClass, $serviceMethodInfo, $arguments);
        $outputData = $this->runServiceMethod($service, $serviceMethod, $inputData);
        return $this->_prepareResponseData($outputData, $serviceClass, $serviceMethod);
    }

    /**
     * Runs service method
     *
     * @param object $service
     * @param string $serviceMethod
     * @param array $inputData
     * @return false|mixed
     */
    private function runServiceMethod($service, $serviceMethod, $inputData)
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return call_user_func_array([$service, $serviceMethod], $inputData);
    }

    /**
     * Convert arguments received from SOAP server to arguments to pass to a service.
     *
     * @param string $serviceClass
     * @param array $methodMetadata
     * @param array $arguments
     * @return array
     * @throws WebapiException
     */
    private function prepareOperationInput(string $serviceClass, array $methodMetadata, array $arguments): array
    {
        /** SoapServer wraps parameters into array. Thus this wrapping should be removed to get access to parameters. */
        $arguments = reset($arguments);
        $arguments = $this->_dataObjectConverter->convertStdObjectToArray($arguments, true);
        $arguments = $this->paramsOverrider->override($arguments, $methodMetadata[ServiceMetadata::KEY_ROUTE_PARAMS]);
        $this->inputArraySizeLimitValue->set($methodMetadata[ServiceMetadata::KEY_INPUT_ARRAY_SIZE_LIMIT]);

        return $this->serviceInputProcessor->process(
            $serviceClass,
            $methodMetadata[ServiceMetadata::KEY_METHOD],
            $arguments
        );
    }

    /**
     * Convert SOAP operation arguments into format acceptable by service method.
     *
     * @param string $serviceClass
     * @param string $serviceMethod
     * @param array $arguments
     * @return array
     * @throws WebapiException
     * @see Handler::prepareOperationInput()
     * @deprecated 100.3.2
     */
    protected function _prepareRequestData($serviceClass, $serviceMethod, $arguments)
    {
        return $this->prepareOperationInput(
            $serviceClass,
            [ServiceMetadata::KEY_METHOD => $serviceMethod, ServiceMetadata::KEY_ROUTE_PARAMS => []],
            $arguments
        );
    }

    /**
     * Convert service response into format acceptable by SoapServer.
     *
     * @param object|array|string|int|float|null $data
     * @param string $serviceClassName
     * @param string $serviceMethodName
     * @return array
     * @throws InvalidArgumentException
     */
    protected function _prepareResponseData($data, $serviceClassName, $serviceMethodName)
    {
        /** @var string $dataType */
        $dataType = $this->methodsMapProcessor->getMethodReturnType($serviceClassName, $serviceMethodName);
        $result = null;
        if (is_object($data)) {
            $result = $this->_dataObjectConverter
                ->convertKeysToCamelCase($this->_dataObjectProcessor->buildOutputDataArray($data, $dataType));
        } elseif (is_array($data)) {
            $dataType = substr((string)$dataType, 0, -2);
            foreach ($data as $key => $value) {
                if ($value instanceof $dataType
                    // the following two options are supported for backward compatibility
                    || $value instanceof ExtensibleDataInterface
                    || $value instanceof MetadataObjectInterface
                ) {
                    $result[] = $this->_dataObjectConverter
                        ->convertKeysToCamelCase($this->_dataObjectProcessor->buildOutputDataArray($value, $dataType));
                } else {
                    $result[$key] = $value;
                }
            }
        } elseif (is_scalar($data) || $data === null) {
            $result = $data;
        } else {
            throw new InvalidArgumentException("Service returned result in invalid format.");
        }
        return [self::RESULT_NODE_NAME => $result];
    }
}
