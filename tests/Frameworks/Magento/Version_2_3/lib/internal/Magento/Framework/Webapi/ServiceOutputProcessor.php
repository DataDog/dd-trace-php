<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Webapi;

use Magento\Framework\Api\AbstractExtensibleObject;
use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Reflection\MethodsMap;
use Magento\Framework\Reflection\TypeProcessor;
use Zend\Code\Reflection\ClassReflection;

/**
 * Data object converter
 *
 * @api
 * @since 100.0.2
 */
class ServiceOutputProcessor implements ServicePayloadConverterInterface
{
    /**
     * @var DataObjectProcessor
     */
    protected $dataObjectProcessor;

    /**
     * @var MethodsMap
     */
    protected $methodsMapProcessor;

    /**
     * @var TypeProcessor|null
     */
    private $typeProcessor;

    /**
     * @param DataObjectProcessor $dataObjectProcessor
     * @param MethodsMap $methodsMapProcessor
     * @param TypeProcessor|null $typeProcessor
     */
    public function __construct(
        DataObjectProcessor $dataObjectProcessor,
        MethodsMap $methodsMapProcessor,
        TypeProcessor $typeProcessor = null
    ) {
        $this->dataObjectProcessor = $dataObjectProcessor;
        $this->methodsMapProcessor = $methodsMapProcessor;
        $this->typeProcessor = $typeProcessor ?: ObjectManager::getInstance()->get(TypeProcessor::class);
    }

    /**
     * Converts the incoming data into scalar or an array of scalars format.
     *
     * If the data provided is null, then an empty array is returned.  Otherwise, if the data is an object, it is
     * assumed to be a Data Object and converted to an associative array with keys representing the properties of the
     * Data Object.
     * Nested Data Objects are also converted.  If the data provided is itself an array, then we iterate through the
     * contents and convert each piece individually.
     *
     * @param mixed $data
     * @param string $serviceClassName
     * @param string $serviceMethodName
     * @return array|int|string|bool|float Scalar or array of scalars
     */
    public function process($data, $serviceClassName, $serviceMethodName)
    {
        /** @var string $dataType */
        $dataType = $this->methodsMapProcessor->getMethodReturnType($serviceClassName, $serviceMethodName);

        if (class_exists($serviceClassName) || interface_exists($serviceClassName)) {
            $sourceClass = new ClassReflection($serviceClassName);
            $dataType = $this->typeProcessor->resolveFullyQualifiedClassName($sourceClass, $dataType);
        }

        return $this->convertValue($data, $dataType);
    }

    /**
     * Convert data object to array and process available custom attributes
     *
     * @param array $dataObjectArray
     * @return array
     */
    protected function processDataObject($dataObjectArray)
    {
        if (isset($dataObjectArray[AbstractExtensibleObject::CUSTOM_ATTRIBUTES_KEY])) {
            $dataObjectArray = ExtensibleDataObjectConverter::convertCustomAttributesToSequentialArray(
                $dataObjectArray
            );
        }
        //Check for nested custom_attributes
        foreach ($dataObjectArray as $key => $value) {
            if (is_array($value)) {
                $dataObjectArray[$key] = $this->processDataObject($value);
            }
        }
        return $dataObjectArray;
    }

    /**
     * Convert associative array into proper data object.
     *
     * @param array $data
     * @param string $type
     * @return array|object
     */
    public function convertValue($data, $type)
    {
        if (is_array($data)) {
            $result = [];
            $arrayElementType = substr($type, 0, -2);
            foreach ($data as $datum) {
                if (is_object($datum)) {
                    $datum = $this->processDataObject(
                        $this->dataObjectProcessor->buildOutputDataArray($datum, $arrayElementType)
                    );
                }
                $result[] = $datum;
            }
            return $result;
        } elseif (is_object($data)) {
            return $this->processDataObject(
                $this->dataObjectProcessor->buildOutputDataArray($data, $type)
            );
        } elseif ($data === null) {
            return [];
        } else {
            /** No processing is required for scalar types */
            return $data;
        }
    }
}
