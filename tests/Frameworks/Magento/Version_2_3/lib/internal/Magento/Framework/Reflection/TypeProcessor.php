<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Reflection;

use Magento\Framework\Exception\SerializationException;
use Magento\Framework\Phrase;
use Zend\Code\Reflection\ClassReflection;
use Zend\Code\Reflection\DocBlock\Tag\ParamTag;
use Zend\Code\Reflection\DocBlock\Tag\ReturnTag;
use Zend\Code\Reflection\DocBlockReflection;
use Zend\Code\Reflection\MethodReflection;
use Zend\Code\Reflection\ParameterReflection;

/**
 * Type processor of config reader properties
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) this suppress MUST be removed after removing deprecated methods.
 */
class TypeProcessor
{
    /**#@+
     * Pre-normalized type constants
     */
    const STRING_TYPE = 'str';
    const INT_TYPE = 'integer';
    const BOOLEAN_TYPE = 'bool';
    const ANY_TYPE = 'mixed';
    /**#@-*/

    /**#@+
     * Normalized type constants
     */
    const NORMALIZED_STRING_TYPE = 'string';
    const NORMALIZED_INT_TYPE = 'int';
    const NORMALIZED_FLOAT_TYPE = 'float';
    const NORMALIZED_DOUBLE_TYPE = 'double';
    const NORMALIZED_BOOLEAN_TYPE = 'boolean';
    const NORMALIZED_ANY_TYPE = 'anyType';
    /**#@-*/

    /**#@-*/
    protected $_types = [];

    /**
     * @var NameFinder
     */
    private $nameFinder;

    /**
     * The getter function to get the new NameFinder dependency
     *
     * @return NameFinder
     *
     * @deprecated 100.1.0
     */
    private function getNameFinder()
    {
        if ($this->nameFinder === null) {
            $this->nameFinder = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Reflection\NameFinder::class);
        }
        return $this->nameFinder;
    }

    /**
     * Retrieve processed types data.
     *
     * @return array
     */
    public function getTypesData()
    {
        return $this->_types;
    }

    /**
     * Set processed types data.
     *
     * Should be used carefully since no data consistency checks are performed.
     *
     * @param array $typesData
     * @return $this
     */
    public function setTypesData($typesData)
    {
        $this->_types = $typesData;
        return $this;
    }

    /**
     * Retrieve data type details for the given type name.
     *
     * @param string $typeName
     * @return array
     * @throws \InvalidArgumentException
     */
    public function getTypeData($typeName)
    {
        if (!isset($this->_types[$typeName])) {
            throw new \InvalidArgumentException(
                sprintf('The "%s" data type isn\'t declared. Verify the type and try again.', $typeName)
            );
        }
        return $this->_types[$typeName];
    }

    /**
     * Add or update type data in config.
     *
     * @param string $typeName
     * @param array $data
     * @return void
     */
    public function setTypeData($typeName, $data)
    {
        if (!isset($this->_types[$typeName])) {
            $this->_types[$typeName] = $data;
        } else {
            $this->_types[$typeName] = array_merge_recursive($this->_types[$typeName], $data);
        }
    }

    /**
     * Process type name. In case parameter type is a complex type (class) - process its properties.
     *
     * @param string $type
     * @return string Complex type name
     * @throws \LogicException
     */
    public function register($type)
    {
        $typeName = $this->normalizeType($type);
        if (null === $typeName) {
            return null;
        }
        if (!$this->isTypeSimple($typeName) && !$this->isTypeAny($typeName)) {
            $typeSimple = $this->getArrayItemType($type);
            if (!(class_exists($typeSimple) || interface_exists($typeSimple))) {
                throw new \LogicException(
                    sprintf(
                        'The "%s" class doesn\'t exist and the namespace must be specified. Verify and try again.',
                        $type
                    )
                );
            }
            $complexTypeName = $this->translateTypeName($type);
            if (!isset($this->_types[$complexTypeName])) {
                $this->_processComplexType($type);
            }
            $typeName = $complexTypeName;
        }

        return $typeName;
    }

    /**
     * Retrieve complex type information from class public properties.
     *
     * @param string $class
     * @return array
     * @throws \InvalidArgumentException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _processComplexType($class)
    {
        $typeName = $this->translateTypeName($class);
        $this->_types[$typeName] = [];
        if ($this->isArrayType($class)) {
            $this->register($this->getArrayItemType($class));
        } else {
            if (!(class_exists($class) || interface_exists($class))) {
                throw new \InvalidArgumentException(
                    sprintf('The "%s" class couldn\'t load as a parameter type.', $class)
                );
            }
            $reflection = new ClassReflection($class);
            $docBlock = $reflection->getDocBlock();
            $this->_types[$typeName]['documentation'] = $docBlock ? $this->getDescription($docBlock) : '';
            /** @var MethodReflection $methodReflection */
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $methodReflection) {
                if ($methodReflection->class === \Magento\Framework\Model\AbstractModel::class) {
                    continue;
                }
                $this->_processMethod($methodReflection, $typeName);
            }
        }

        return $this->_types[$typeName];
    }

    /**
     * Collect metadata for virtual field corresponding to current method if it is a getter (used in WSDL generation).
     *
     * @param MethodReflection $methodReflection
     * @param string $typeName
     * @return void
     */
    protected function _processMethod(MethodReflection $methodReflection, $typeName)
    {
        $isGetter = (strpos($methodReflection->getName(), 'get') === 0)
            || (strpos($methodReflection->getName(), 'is') === 0)
            || (strpos($methodReflection->getName(), 'has') === 0);
        /** Field will not be added to WSDL if getter has params */
        if ($isGetter && !$methodReflection->getNumberOfRequiredParameters()) {
            $returnMetadata = $this->getGetterReturnType($methodReflection);
            $fieldName = $this->getNameFinder()->getFieldNameFromGetterName($methodReflection->getName());
            if ($returnMetadata['description']) {
                $description = $returnMetadata['description'];
            } else {
                $description = $this->getNameFinder()->getFieldDescriptionFromGetterDescription(
                    $methodReflection->getDocBlock()->getShortDescription()
                );
            }
            $this->_types[$typeName]['parameters'][$fieldName] = [
                'type' => $this->register($returnMetadata['type']),
                'required' => $returnMetadata['isRequired'],
                'documentation' => $description,
            ];
        }
    }

    /**
     * Get short and long description from docblock and concatenate.
     *
     * @param DocBlockReflection $doc
     * @return string
     */
    public function getDescription(DocBlockReflection $doc)
    {
        $shortDescription = $doc->getShortDescription();
        $longDescription = $doc->getLongDescription();

        $description = rtrim($shortDescription);
        $longDescription = str_replace(["\n", "\r"], '', $longDescription);
        if (!empty($longDescription) && !empty($description)) {
            $description .= " ";
        }
        $description .= ltrim($longDescription);

        return $description;
    }

    /**
     * Convert Data Object getter name into field name.
     *
     * @param string $getterName
     * @return string
     *
     * @deprecated 100.1.0
     */
    public function dataObjectGetterNameToFieldName($getterName)
    {
        return $this->getNameFinder()->getFieldNameFromGetterName($getterName);
    }

    /**
     * Convert Data Object getter short description into field description.
     *
     * @param string $shortDescription
     * @return string
     *
     * @deprecated 100.1.0
     */
    protected function dataObjectGetterDescriptionToFieldDescription($shortDescription)
    {
        return $this->getNameFinder()->getFieldDescriptionFromGetterDescription($shortDescription);
    }

    /**
     * Identify getter return type by its reflection.
     *
     * @param MethodReflection $methodReflection
     * @return array <pre>array(
     *     'type' => <string>$type,
     *     'isRequired' => $isRequired,
     *     'description' => $description
     *     'parameterCount' => $numberOfRequiredParameters
     * )</pre>
     * @throws \InvalidArgumentException
     */
    public function getGetterReturnType($methodReflection)
    {
        $returnAnnotation = $this->getMethodReturnAnnotation($methodReflection);
        $types = $returnAnnotation->getTypes();
        $returnType = current($types);
        $nullable = in_array('null', $types);

        return [
            'type' => $returnType,
            'isRequired' => !$nullable,
            'description' => $returnAnnotation->getDescription(),
            'parameterCount' => $methodReflection->getNumberOfRequiredParameters()
        ];
    }

    /**
     * Get possible method exceptions
     *
     * @param MethodReflection $methodReflection
     * @return array
     */
    public function getExceptions($methodReflection)
    {
        $exceptions = [];
        $methodDocBlock = $methodReflection->getDocBlock();
        if ($methodDocBlock->hasTag('throws')) {
            $throwsTypes = $methodDocBlock->getTags('throws');
            if (is_array($throwsTypes)) {
                /** @var $throwsType \Zend\Code\Reflection\DocBlock\Tag\ThrowsTag */
                foreach ($throwsTypes as $throwsType) {
                    $exceptions = array_merge($exceptions, $throwsType->getTypes());
                }
            }
        }

        return $exceptions;
    }

    /**
     * Normalize short type names to full type names.
     *
     * @param string $type
     * @return string
     */
    public function normalizeType($type)
    {
        if ($type == 'null') {
            return null;
        }
        $normalizationMap = [
            self::STRING_TYPE => self::NORMALIZED_STRING_TYPE,
            self::INT_TYPE => self::NORMALIZED_INT_TYPE,
            self::BOOLEAN_TYPE => self::NORMALIZED_BOOLEAN_TYPE,
            self::ANY_TYPE => self::NORMALIZED_ANY_TYPE,
        ];

        return is_string($type) && isset($normalizationMap[$type]) ? $normalizationMap[$type] : $type;
    }

    /**
     * Check if given type is a simple type.
     *
     * @param string $type
     * @return bool
     */
    public function isTypeSimple($type)
    {
        return in_array(
            $this->getNormalizedType($type),
            [
                self::NORMALIZED_STRING_TYPE,
                self::NORMALIZED_INT_TYPE,
                self::NORMALIZED_FLOAT_TYPE,
                self::NORMALIZED_DOUBLE_TYPE,
                self::NORMALIZED_BOOLEAN_TYPE,
            ]
        );
    }

    /**
     * Check if given type is any type.
     *
     * @param string $type
     * @return bool
     */
    public function isTypeAny($type)
    {
        return ($this->getNormalizedType($type) == self::NORMALIZED_ANY_TYPE);
    }

    /**
     * Check if given type is an array of type items.
     * Example:
     * <pre>
     *  ComplexType[] -> array of ComplexType items
     *  string[] -> array of strings
     * </pre>
     *
     * @param string $type
     * @return bool
     */
    public function isArrayType($type)
    {
        return (bool)preg_match('/(\[\]$|^ArrayOf)/', $type);
    }

    /**
     * Check if given type is valid to use as an argument type declaration
     *
     * @see http://php.net/manual/en/functions.arguments.php#functions.arguments.type-declaration
     * @param string $type
     * @return bool
     */
    public function isValidTypeDeclaration($type)
    {
        return !($this->isTypeSimple($type) || $this->isTypeAny($type) || $this->isArrayType($type));
    }

    /**
     * Get item type of the array.
     *
     * @param string $arrayType
     * @return string
     */
    public function getArrayItemType($arrayType)
    {
        return $this->normalizeType(str_replace('[]', '', $arrayType));
    }

    /**
     * Translate complex type class name into type name.
     *
     * Example:
     * <pre>
     *  \Magento\Customer\Api\Data\CustomerInterface => CustomerV1DataCustomer
     * </pre>
     *
     * @param string $class
     * @return string
     * @throws \InvalidArgumentException
     */
    public function translateTypeName($class)
    {
        if (preg_match('/\\\\?(.*)\\\\(.*)\\\\(Service|Api)\\\\\2?(.*)/', $class, $matches)) {
            $moduleNamespace = $matches[1] == 'Magento' ? '' : $matches[1];
            $moduleName = $matches[2];
            $typeNameParts = explode('\\', $matches[4]);

            return ucfirst($moduleNamespace . $moduleName . implode('', $typeNameParts));
        }
        throw new \InvalidArgumentException(
            sprintf('The "%s" parameter type is invalid. Verify the parameter and try again.', $class)
        );
    }

    /**
     * Translate array complex type name.
     *
     * Example:
     * <pre>
     *  ComplexTypeName[] => ArrayOfComplexTypeName
     *  string[] => ArrayOfString
     * </pre>
     *
     * @param string $type
     * @return string
     */
    public function translateArrayTypeName($type)
    {
        return 'ArrayOf' . ucfirst($this->getArrayItemType($type));
    }

    /**
     * Convert the value to the requested simple or any type
     *
     * @param int|string|float|int[]|string[]|float[] $value
     * @param string $type Convert given value to the this simple type
     * @return int|string|float|int[]|string[]|float[] Return the value which is converted to type
     * @throws SerializationException
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function processSimpleAndAnyType($value, $type)
    {
        $isArrayType = $this->isArrayType($type);
        if ($isArrayType && is_array($value)) {
            $arrayItemType = $this->getArrayItemType($type);
            foreach (array_keys($value) as $key) {
                if ($value !== null && !settype($value[$key], $arrayItemType)) {
                    throw new SerializationException(
                        new Phrase(
                            'The "%value" value\'s type is invalid. The "%type" type was expected. '
                            . 'Verify and try again.',
                            ['value' => $value, 'type' => $type]
                        )
                    );
                }
            }
        } elseif ($isArrayType && $value === null) {
            return null;
        } elseif (!$isArrayType && !is_array($value)) {
            if ($value !== null && !$this->isTypeAny($type) && !$this->setType($value, $type)) {
                throw new SerializationException(
                    new Phrase(
                        'The "%value" value\'s type is invalid. The "%type" type was expected. Verify and try again.',
                        ['value' => (string)$value, 'type' => $type]
                    )
                );
            }
        } elseif (!$this->isTypeAny($type)) {
            throw new SerializationException(
                new Phrase(
                    'The "%value" value\'s type is invalid. The "%type" type was expected. Verify and try again.',
                    ['value' => gettype($value), 'type' => $type]
                )
            );
        }
        return $value;
    }

    /**
     * Get the parameter type
     *
     * @param ParameterReflection $param
     * @return string
     * @throws \LogicException
     */
    public function getParamType(ParameterReflection $param)
    {
        $type = $param->detectType();
        if ($type === 'null') {
            throw new \LogicException(sprintf(
                '@param annotation is incorrect for the parameter "%s" in the method "%s:%s".'
                . ' First declared type should not be null. E.g. string|null',
                $param->getName(),
                $param->getDeclaringClass()->getName(),
                $param->getDeclaringFunction()->name
            ));
        }
        if ($type === 'array') {
            // try to determine class, if it's array of objects
            $paramDocBlock = $this->getParamDocBlockTag($param);
            $paramTypes = $paramDocBlock->getTypes();
            $paramType = array_shift($paramTypes);

            $paramType = $this->resolveFullyQualifiedClassName($param->getDeclaringClass(), $paramType);

            return strpos($paramType, '[]') !== false ? $paramType : "{$paramType}[]";
        }

        return $this->resolveFullyQualifiedClassName($param->getDeclaringClass(), $type);
    }

    /**
     * Get alias mapping for source class
     *
     * @param ClassReflection $sourceClass
     * @return array
     */
    public function getAliasMapping(ClassReflection $sourceClass): array
    {
        $sourceFileName = $sourceClass->getDeclaringFile();
        $aliases = [];
        foreach ($sourceFileName->getUses() as $use) {
            if ($use['as'] !== null) {
                $aliases[$use['as']] = $use['use'];
            } else {
                $pos = strrpos($use['use'], '\\');

                $aliasName = substr($use['use'], $pos + 1);
                $aliases[$aliasName] = $use['use'];
            }
        }

        return $aliases;
    }

    /**
     * Return true if the passed type is a simple type
     *
     * Eg.:
     * Return true with; array, string, ...
     * Return false with: SomeClassName
     *
     * @param string $typeName
     * @return bool
     */
    public function isSimpleType(string $typeName): bool
    {
        return strtolower($typeName) === $typeName;
    }

    /**
     * Get basic type for a class name
     *
     * Eg.:
     * SomeClassName[] => SomeClassName
     *
     * @param string $className
     * @return string
     */
    public function getBasicClassName(string $className): string
    {
        $pos = strpos($className, '[');
        return ($pos === false) ? $className : substr($className, 0, $pos);
    }

    /**
     * Return true if it is a FQ class name
     *
     * Eg.:
     * SomeClassName => false
     * \My\NameSpace\SomeClassName => true
     *
     * @param string $className
     * @return bool
     */
    public function isFullyQualifiedClassName(string $className): bool
    {
        return strpos($className, '\\') === 0;
    }

    /**
     * Get aliased class name
     *
     * @param string $className
     * @param string $namespace
     * @param array $aliases
     * @return string
     */
    private function getAliasedClassName(string $className, string $namespace, array $aliases): string
    {
        $pos = strpos($className, '\\');
        if ($pos === false) {
            $namespacePrefix = $className;
            $partialClassName = '';
        } else {
            $namespacePrefix = substr($className, 0, $pos);
            $partialClassName = substr($className, $pos);
        }

        if (isset($aliases[$namespacePrefix])) {
            return $aliases[$namespacePrefix] . $partialClassName;
        }

        return $namespace . '\\' . $className;
    }

    /**
     * Resolve fully qualified type name in the class alias context
     *
     * @param ClassReflection $sourceClass
     * @param string $typeName
     * @return string
     */
    public function resolveFullyQualifiedClassName(ClassReflection $sourceClass, string $typeName): string
    {
        $typeName = trim($typeName);

        // Simple way to understand it is a basic type or a class name
        if ($this->isSimpleType($typeName)) {
            return $typeName;
        }

        $basicTypeName = $this->getBasicClassName($typeName);

        // Already a FQN class name
        if ($this->isFullyQualifiedClassName($basicTypeName)) {
            return '\\' . substr($typeName, 1);
        }

        $isArray = $this->isArrayType($typeName);
        $aliases = $this->getAliasMapping($sourceClass);

        $namespace = $sourceClass->getNamespaceName();
        $fqClassName = '\\' . $this->getAliasedClassName($basicTypeName, $namespace, $aliases);

        if (interface_exists($fqClassName) || class_exists($fqClassName)) {
            return $fqClassName . ($isArray ? '[]' : '');
        }

        return $typeName;
    }

    /**
     * Gets method parameter description.
     *
     * @param ParameterReflection $param
     * @return string|null
     */
    public function getParamDescription(ParameterReflection $param)
    {
        $paramDocBlock = $this->getParamDocBlockTag($param);
        return $paramDocBlock->getDescription();
    }

    /**
     * Find the getter method name for a property from the given class
     *
     * @param ClassReflection $class
     * @param string $camelCaseProperty
     * @return string processed method name
     * @throws \Exception If $camelCaseProperty has no corresponding getter method
     *
     * @deprecated 100.1.0
     */
    public function findGetterMethodName(ClassReflection $class, $camelCaseProperty)
    {
        return $this->getNameFinder()->getGetterMethodName($class, $camelCaseProperty);
    }

    /**
     * Set value to a particular type
     *
     * @param mixed $value
     * @param string $type
     * @return true on successful type cast
     */
    protected function setType(&$value, $type)
    {
        // settype doesn't work for boolean string values.
        // ex: custom_attributes passed from SOAP client can have boolean values as string
        $booleanTypes = ['bool', 'boolean'];
        if (in_array($type, $booleanTypes)) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            return true;
        }
        $numType = ['int', 'float'];
        if (in_array($type, $numType) && !is_numeric($value)) {
            return false;
        }
        return settype($value, $type);
    }

    /**
     * Find the setter method name for a property from the given class
     *
     * @param ClassReflection $class
     * @param string $camelCaseProperty
     * @return string processed method name
     * @throws \Exception If $camelCaseProperty has no corresponding setter method
     *
     * @deprecated 100.1.0
     */
    public function findSetterMethodName(ClassReflection $class, $camelCaseProperty)
    {
        return $this->getNameFinder()->getSetterMethodName($class, $camelCaseProperty);
    }

    /**
     * Find the accessor method name for a property from the given class
     *
     * @param ClassReflection $class
     * @param string $camelCaseProperty
     * @param string $accessorName
     * @param bool $boolAccessorName
     * @return string processed method name
     * @throws \Exception If $camelCaseProperty has no corresponding setter method
     *
     * @deprecated 100.1.0
     */
    protected function findAccessorMethodName(
        ClassReflection $class,
        $camelCaseProperty,
        $accessorName,
        $boolAccessorName
    ) {
        return $this->getNameFinder()
            ->findAccessorMethodName($class, $camelCaseProperty, $accessorName, $boolAccessorName);
    }

    /**
     * Checks if method is defined
     *
     * Case sensitivity of the method is taken into account.
     *
     * @param ClassReflection $class
     * @param string $methodName
     * @return bool
     *
     * @deprecated 100.1.0
     */
    protected function classHasMethod(ClassReflection $class, $methodName)
    {
        return $this->getNameFinder()->hasMethod($class, $methodName);
    }

    /**
     * Process call info data from interface.
     *
     * @param array $interface
     * @param string $serviceName API service name
     * @param string $methodName
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function processInterfaceCallInfo($interface, $serviceName, $methodName)
    {
        foreach ($interface as $direction => $interfaceData) {
            $direction = ($direction == 'in') ? 'requiredInput' : 'returned';
            if ($direction == 'returned' && !isset($interfaceData['parameters'])) {
                /** No return value means that service method is asynchronous */
                return $this;
            }
            foreach ($interfaceData['parameters'] as $parameterData) {
                if (!$this->isTypeSimple($parameterData['type']) && !$this->isTypeAny($parameterData['type'])) {
                    $operation = $this->getOperationName($serviceName, $methodName);
                    if ($parameterData['required']) {
                        $condition = ($direction == 'requiredInput') ? 'yes' : 'always';
                    } else {
                        $condition = ($direction == 'requiredInput') ? 'no' : 'conditionally';
                    }
                    $callInfo = [];
                    $callInfo[$direction][$condition]['calls'][] = $operation;
                    $this->setTypeData($parameterData['type'], ['callInfo' => $callInfo]);
                }
            }
        }
        return $this;
    }

    /**
     * Get name of operation based on service and method names.
     *
     * @param string $serviceName API service name
     * @param string $methodName
     * @return string
     */
    public function getOperationName($serviceName, $methodName)
    {
        return $serviceName . ucfirst($methodName);
    }

    /**
     * Get normalized type
     *
     * @param string $type
     * @return string
     */
    private function getNormalizedType($type)
    {
        $type = $this->normalizeType($type);
        if ($this->isArrayType($type)) {
            $type = $this->getArrayItemType($type);
        }
        return $type;
    }

    /**
     * Parses `return` annotation from reflection method.
     *
     * @param MethodReflection $methodReflection
     * @return ReturnTag
     * @throws \InvalidArgumentException if doc block is empty or `@return` annotation doesn't exist
     */
    private function getMethodReturnAnnotation(MethodReflection $methodReflection)
    {
        $methodName = $methodReflection->getName();
        $returnAnnotations = $this->getReturnFromDocBlock($methodReflection);
        if (empty($returnAnnotations)) {
            // method can inherit doc block from implemented interface, like for interceptors
            $implemented = $methodReflection->getDeclaringClass()->getInterfaces();
            /** @var ClassReflection $parentClassReflection */
            foreach ($implemented as $parentClassReflection) {
                if ($parentClassReflection->hasMethod($methodName)) {
                    $returnAnnotations = $this->getReturnFromDocBlock(
                        $parentClassReflection->getMethod($methodName)
                    );
                    break;
                }
            }
            // throw an exception if even implemented interface doesn't have return annotations
            if (empty($returnAnnotations)) {
                throw new \InvalidArgumentException(
                    "Method's return type must be specified using @return annotation. "
                    . "See {$methodReflection->getDeclaringClass()->getName()}::{$methodName}()"
                );
            }
        }
        return $returnAnnotations;
    }

    /**
     * Parses `return` annotation from doc block.
     *
     * @param MethodReflection $methodReflection
     * @return ReturnTag
     */
    private function getReturnFromDocBlock(MethodReflection $methodReflection)
    {
        $methodDocBlock = $methodReflection->getDocBlock();
        if (!$methodDocBlock) {
            throw new \InvalidArgumentException(
                "Each method must have a doc block. "
                . "See {$methodReflection->getDeclaringClass()->getName()}::{$methodReflection->getName()}()"
            );
        }
        return current($methodDocBlock->getTags('return'));
    }

    /**
     * Gets method's param doc block.
     *
     * @param ParameterReflection $param
     * @return ParamTag
     */
    private function getParamDocBlockTag(ParameterReflection $param): ParamTag
    {
        $docBlock = $param->getDeclaringFunction()
            ->getDocBlock();
        $paramsTag = $docBlock->getTags('param');
        return $paramsTag[$param->getPosition()];
    }
}
