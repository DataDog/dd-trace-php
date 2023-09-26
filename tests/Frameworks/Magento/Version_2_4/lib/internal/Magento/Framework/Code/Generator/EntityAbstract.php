<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Code\Generator;

use Laminas\Code\Generator\ValueGenerator;
use Magento\Framework\GetParameterClassTrait;

/**
 * Abstract entity
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class EntityAbstract
{
    use GetParameterClassTrait;

    /**
     * Entity type abstract
     */
    public const ENTITY_TYPE = 'abstract';

    /**
     * @var string[]
     */
    private $_errors = [];

    /**
     * Source model class name
     *
     * @var string
     */
    private $_sourceClassName;

    /**
     * Result model class name
     *
     * @var string
     */
    private $_resultClassName;

    /**
     * @var Io
     */
    private $_ioObject;

    /**
     * Class generator object
     *
     * @var \Magento\Framework\Code\Generator\CodeGeneratorInterface
     */
    protected $_classGenerator;

    /**
     * @var DefinedClasses
     */
    private $definedClasses;

    /**
     * @param null|string $sourceClassName
     * @param null|string $resultClassName
     * @param Io $ioObject
     * @param \Magento\Framework\Code\Generator\CodeGeneratorInterface $classGenerator
     * @param DefinedClasses $definedClasses
     */
    public function __construct(
        $sourceClassName = null,
        $resultClassName = null,
        Io $ioObject = null,
        \Magento\Framework\Code\Generator\CodeGeneratorInterface $classGenerator = null,
        DefinedClasses $definedClasses = null
    ) {
        if ($ioObject) {
            $this->_ioObject = $ioObject;
        } else {
            $this->_ioObject = new Io(new \Magento\Framework\Filesystem\Driver\File());
        }
        if ($classGenerator) {
            $this->_classGenerator = $classGenerator;
        } else {
            $this->_classGenerator = new ClassGenerator();
        }
        if ($definedClasses) {
            $this->definedClasses = $definedClasses;
        } else {
            $this->definedClasses = new DefinedClasses();
        }

        $this->_sourceClassName = $this->_getFullyQualifiedClassName($sourceClassName);
        if ($resultClassName) {
            $this->_resultClassName = $this->_getFullyQualifiedClassName($resultClassName);
        } elseif ($this->_sourceClassName) {
            $this->_resultClassName = $this->_getDefaultResultClassName($this->_sourceClassName);
        }
    }

    /**
     * Generation template method
     *
     * @return bool
     */
    public function generate()
    {
        try {
            if ($this->_validateData()) {
                $sourceCode = $this->_generateCode();
                if ($sourceCode) {
                    $fileName = $this->_ioObject->generateResultFileName($this->_getResultClassName());
                    $this->_ioObject->writeResultFile($fileName, $sourceCode);
                    return $fileName;
                } else {
                    $this->_addError('Can\'t generate source code.');
                }
            }
        } catch (\Exception $e) {
            $this->_addError($e->getMessage());
        }
        return false;
    }

    /**
     * List of occurred generation errors
     *
     * @return string[]
     */
    public function getErrors()
    {
        return $this->_errors;
    }

    /**
     * Get full source class name, with namespace
     *
     * @return string
     */
    public function getSourceClassName()
    {
        return $this->_sourceClassName;
    }

    /**
     * Get source class without namespace.
     *
     * @return string
     */
    public function getSourceClassNameWithoutNamespace()
    {
        $parts = explode('\\', ltrim($this->getSourceClassName(), '\\'));
        return end($parts);
    }

    /**
     * Get fully qualified class name
     *
     * @param string $className
     * @return string
     */
    protected function _getFullyQualifiedClassName($className)
    {
        return $className ? '\\' . ltrim($className, '\\') : '';
    }

    /**
     * Get result class name
     *
     * @return string
     */
    protected function _getResultClassName()
    {
        return $this->_resultClassName;
    }

    /**
     * Get default result class name
     *
     * @param string $modelClassName
     * @return string
     */
    protected function _getDefaultResultClassName($modelClassName)
    {
        return $modelClassName . ucfirst(static::ENTITY_TYPE);
    }

    /**
     * Returns list of properties for class generator
     *
     * @return array
     */
    protected function _getClassProperties()
    {
        $objectManager = [
            'name' => '_objectManager',
            'visibility' => 'protected',
            'docblock' => [
                'shortDescription' => 'Object Manager instance',
                'tags' => [['name' => 'var', 'description' => '\\' . \Magento\Framework\ObjectManagerInterface::class]],
            ],
        ];

        return [$objectManager];
    }

    /**
     * Get default constructor definition for generated class
     *
     * @return array
     */
    abstract protected function _getDefaultConstructorDefinition();

    /**
     * Returns list of methods for class generator
     *
     * @return array
     */
    abstract protected function _getClassMethods();

    /**
     * Generate code
     *
     * @return string
     */
    protected function _generateCode()
    {
        $this->_classGenerator->setName($this->_getResultClassName())
            ->addProperties($this->_getClassProperties())
            ->addMethods($this->_getClassMethods())
            ->setClassDocBlock($this->_getClassDocBlock());

        return $this->_getGeneratedCode();
    }

    /**
     * Add error message
     *
     * @param string $message
     * @return $this
     */
    protected function _addError($message)
    {
        $this->_errors[] = $message;
        return $this;
    }

    /**
     * Validate data
     *
     * @return bool
     */
    protected function _validateData()
    {
        $sourceClassName = $this->getSourceClassName();
        $resultClassName = $this->_getResultClassName();
        $resultDir = $this->_ioObject->getResultFileDirectory($resultClassName);

        if (!$this->definedClasses->isClassLoadable($sourceClassName)) {
            $this->_addError('Source class ' . $sourceClassName . ' doesn\'t exist.');
            return false;
        } elseif (/**
         * If makeResultFileDirectory only fails because the file is already created,
         * a competing process has generated the file, no exception should be thrown.
         */
            !$this->_ioObject->makeResultFileDirectory($resultClassName)
            && !$this->_ioObject->fileExists($resultDir)
        ) {
            $this->_addError('Can\'t create directory ' . $resultDir . '.');
            return false;
        }
        return true;
    }

    /**
     * Get class DocBlock
     *
     * @return array
     */
    protected function _getClassDocBlock()
    {
        $description = ucfirst(static::ENTITY_TYPE) . ' class for @see ' . $this->getSourceClassName();
        return ['shortDescription' => $description];
    }

    /**
     * Get generated code
     *
     * @return string
     */
    protected function _getGeneratedCode()
    {
        $sourceCode = $this->_classGenerator->generate();
        return $this->_fixCodeStyle($sourceCode);
    }

    /**
     * Fix code style
     *
     * @param string $sourceCode
     * @return string
     */
    protected function _fixCodeStyle($sourceCode)
    {
        $sourceCode = str_replace(' array (', ' array(', $sourceCode);
        $sourceCode = preg_replace("/{\n{2,}/m", "{\n", $sourceCode);
        $sourceCode = preg_replace("/\n{2,}}/m", "\n}", $sourceCode);
        return $sourceCode;
    }

    /**
     * Get value generator for null default value
     *
     * @return ValueGenerator
     */
    protected function _getNullDefaultValue()
    {
        $value = new ValueGenerator(null, ValueGenerator::TYPE_NULL);

        return $value;
    }

    /**
     * Extract parameter type
     *
     * @param \ReflectionParameter $parameter
     * @return null|string
     */
    private function extractParameterType(
        \ReflectionParameter $parameter
    ): ?string {
        if (!$parameter->hasType()) {
            return null;
        }

        /** @var string|null $typeName */
        $typeName = null;
        $parameterType = $parameter->getType();

        if ($parameterType instanceof \ReflectionUnionType) {
            $parameterType = $parameterType->getTypes();
            $parameterType = implode('|', $parameterType);
        } elseif ($parameterType instanceof \ReflectionIntersectionType) {
            $parameterType = $parameterType->getTypes();
            $parameterType = implode('&', $parameterType);
        } else {
            $parameterType = $parameterType->getName();
        }

        if ($parameterType === 'array') {
            $typeName = 'array';
        } elseif ($parameterClass = $this->getParameterClass($parameter)) {
            $typeName = $this->_getFullyQualifiedClassName($parameterClass->getName());
        } elseif ($parameterType === 'callable') {
            $typeName = 'callable';
        } else {
            $typeName = $parameterType;
        }

        if ($parameter->allowsNull() && $typeName !== 'mixed') {
            $typeName = '?' . $typeName;
        }

        return $typeName;
    }

    /**
     * Extract parameter default value
     *
     * @param \ReflectionParameter $parameter
     * @return null|ValueGenerator
     * @throws \ReflectionException
     */
    private function extractParameterDefaultValue(
        \ReflectionParameter $parameter
    ): ?ValueGenerator {
        /** @var ValueGenerator|null $value */
        $value = null;
        if ($parameter->isOptional() && $parameter->isDefaultValueAvailable()) {
            $valueType = ValueGenerator::TYPE_AUTO;
            $defaultValue = $parameter->getDefaultValue();
            if ($defaultValue === null) {
                $valueType = ValueGenerator::TYPE_NULL;
            }
            $value = new ValueGenerator($defaultValue, $valueType);
        }

        return $value;
    }

    /**
     * Retrieve method parameter info
     *
     * @param \ReflectionParameter $parameter
     * @return array
     * @throws \ReflectionException
     */
    protected function _getMethodParameterInfo(\ReflectionParameter $parameter)
    {
        $parameterInfo = [
            'name' => $parameter->getName(),
            'passedByReference' => $parameter->isPassedByReference()
        ];
        if ($parameter->isVariadic()) {
            $parameterInfo['variadic'] = $parameter->isVariadic();
        }

        if ($type = $this->extractParameterType($parameter)) {
            $parameterInfo['type'] = $type;
        }
        if ($default = $this->extractParameterDefaultValue($parameter)) {
            $parameterInfo['defaultValue'] = $default;
        }

        return $parameterInfo;
    }

    /**
     * Reinit generator
     *
     * @param string $sourceClassName
     * @param string $resultClassName
     * @return void
     */
    public function init($sourceClassName, $resultClassName)
    {
        $this->_sourceClassName = $sourceClassName;
        $this->_resultClassName = $resultClassName;
    }
}
