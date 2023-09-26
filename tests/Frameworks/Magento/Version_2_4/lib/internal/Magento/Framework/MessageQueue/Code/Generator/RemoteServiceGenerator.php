<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\MessageQueue\Code\Generator;

use Laminas\Code\Reflection\MethodReflection;
use Magento\Framework\Code\Generator\DefinedClasses;
use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Communication\Config\ReflectionGenerator;
use Magento\Framework\Communication\ConfigInterface as CommunicationConfig;
use Magento\Framework\MessageQueue\Code\Generator\Config\RemoteServiceReader\Communication as RemoteServiceReader;
use Magento\Framework\Reflection\MethodsMap as ServiceMethodsMap;

/**
 * Code generator for remote services.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RemoteServiceGenerator extends \Magento\Framework\Code\Generator\EntityAbstract
{
    public const ENTITY_TYPE = 'remote';
    public const REMOTE_SERVICE_SUFFIX = 'Remote';

    /**
     * @var CommunicationConfig
     */
    protected $communicationConfig;

    /**
     * @var ServiceMethodsMap
     */
    private $serviceMethodsMap;

    /**
     * @var ReflectionGenerator
     */
    private $reflectionGenerator;

    /**
     * Initialize dependencies.
     *
     * @param CommunicationConfig $communicationConfig
     * @param ServiceMethodsMap $serviceMethodsMap
     * @param RemoteServiceReader $communicationRemoteServiceReader
     * @param string|null $sourceClassName
     * @param string|null $resultClassName
     * @param Io $ioObject
     * @param \Magento\Framework\Code\Generator\CodeGeneratorInterface $classGenerator
     * @param DefinedClasses $definedClasses
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        CommunicationConfig $communicationConfig,
        ServiceMethodsMap $serviceMethodsMap,
        RemoteServiceReader $communicationRemoteServiceReader,
        $sourceClassName = null,
        $resultClassName = null,
        Io $ioObject = null,
        \Magento\Framework\Code\Generator\CodeGeneratorInterface $classGenerator = null,
        DefinedClasses $definedClasses = null
    ) {
        $this->communicationConfig = $communicationConfig;
        $this->serviceMethodsMap = $serviceMethodsMap;
        parent::__construct(
            $sourceClassName,
            $resultClassName,
            $ioObject,
            $classGenerator,
            $definedClasses
        );
    }

    /**
     * @inheritDoc
     */
    protected function _getDefaultConstructorDefinition()
    {
        return [
            'name' => '__construct',
            'parameters' => [
                ['name' => 'publisher', 'type' => '\\' . \Magento\Framework\MessageQueue\PublisherInterface::class],
            ],
            'body' => "\$this->publisher = \$publisher;",
            'docblock' => [
                'shortDescription' => 'Initialize dependencies.',
                'tags' => [
                    [
                        'name' => 'param',
                        'description' => '\Magento\Framework\MessageQueue\PublisherInterface $publisher',
                    ],
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function _getClassProperties()
    {
        return [
            [
                'name' => 'publisher',
                'visibility' => 'protected',
                'docblock' => [
                    'shortDescription' => 'Publisher',
                    'tags' => [
                        [
                            'name' => 'var',
                            'description' => '\\' . \Magento\Framework\MessageQueue\PublisherInterface::class,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function _getClassMethods()
    {
        $methods = [$this->_getDefaultConstructorDefinition()];
        $interfaceMethodsMap = $this->serviceMethodsMap->getMethodsMap($this->getSourceClassName());
        foreach (array_keys($interfaceMethodsMap) as $methodName) {
            // Uses Laminas Reflection instead MethodsMap service, because second does not support features of PHP 7.x
            $methodReflection = new MethodReflection($this->getSourceClassName(), $methodName);
            $sourceMethodParameters = $methodReflection->getParameters();
            $methodParameters = [];
            $topicParameters = [];
            /** @var \Laminas\Code\Reflection\ParameterReflection $methodParameter */
            foreach ($sourceMethodParameters as $methodParameter) {
                $parameterName = $methodParameter->getName();
                $parameter = [
                    'name' => $parameterName,
                    'type' => $this->convertMethodType($methodParameter->getType()),
                ];
                if ($methodParameter->isDefaultValueAvailable()) {
                    $parameter['defaultValue'] = $methodParameter->getDefaultValue() !== null
                        ? $methodParameter->getDefaultValue() : $this->_getNullDefaultValue();
                }
                $methodParameters[] = $parameter;
                $topicParameters[] = "'{$parameterName}' => \${$parameterName}";
            }
            $topicName = $this->getReflectionGenerator()->generateTopicName($this->getSourceClassName(), $methodName);
            $topicConfig = $this->communicationConfig->getTopic($topicName);
            $methodBody = $topicConfig[CommunicationConfig::TOPIC_IS_SYNCHRONOUS] ? 'return ' : '';
            $methodBody .= "\$this->publisher->publish(\n"
                . "    '{$topicName}',\n"
                . "    [" . implode(', ', $topicParameters) . "]\n"
                . ");";
            $annotations = [['name' => 'inheritdoc']];
            $method = [
                'name' => $methodName,
                'returnType' => $this->convertMethodType($methodReflection->getReturnType()),
                'parameters' => $methodParameters,
                'body' => $methodBody,
                'docblock' => ['tags' => $annotations],
            ];
            $methods[] = $method;
        }
        return $methods;
    }

    /**
     * Converts method type if needed.
     *
     * @param mixed $type
     * @return string|null
     */
    private function convertMethodType($type)
    {
        $returnTypeValue = $type instanceof \ReflectionNamedType ? $type->getName() : $type;

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $returnTypeValue = [];
            foreach ($type->getTypes() as $type) {
                $returnTypeValue[] =  $type->getName();
            }

            $returnTypeValue = implode(
                $type instanceof \ReflectionUnionType ? '|' : '&',
                $returnTypeValue
            );
        }

        return $returnTypeValue;
    }

    /**
     * @inheritDoc
     */
    protected function _validateData()
    {
        $classNameValidationResults = $this->validateResultClassName();
        return parent::_validateData() && $classNameValidationResults;
    }

    /**
     * @inheritDoc
     */
    protected function _generateCode()
    {
        $this->_classGenerator->setImplementedInterfaces([$this->getSourceClassName()]);
        return parent::_generateCode();
    }

    /**
     * Ensure that result class name corresponds to the source class name.
     *
     * @return bool
     */
    protected function validateResultClassName()
    {
        $result = true;
        $sourceClassName = $this->getSourceClassName();
        $resultClassName = $this->_getResultClassName();
        $interfaceSuffix = 'Interface';
        if (substr($sourceClassName, -strlen($interfaceSuffix)) !== $interfaceSuffix) {
            $this->_addError(
                sprintf(
                    'Remote service class "%s" should be set as preference for an interface, "%s" given',
                    $resultClassName,
                    $sourceClassName
                )
            );
        }
        $expectedResultClassName = $sourceClassName . self::REMOTE_SERVICE_SUFFIX;
        if ($resultClassName !== $expectedResultClassName) {
            $this->_addError(
                'Invalid remote service class name [' . $resultClassName . ']. Use ' . $expectedResultClassName
            );
            $result = false;
        }
        return $result;
    }

    /**
     * Get reflection generator.
     *
     * @return ReflectionGenerator
     *
     * @deprecated 103.0.0
     * @see https://jira.corp.adobe.com/browse/MAGETWO-56305
     */
    private function getReflectionGenerator()
    {
        if ($this->reflectionGenerator === null) {
            $this->reflectionGenerator = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(ReflectionGenerator::class);
        }
        return $this->reflectionGenerator;
    }
}
