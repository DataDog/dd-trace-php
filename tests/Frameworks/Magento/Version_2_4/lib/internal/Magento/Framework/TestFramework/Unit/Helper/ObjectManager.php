<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\TestFramework\Unit\Helper;

use Magento\Framework\GetParameterClassTrait;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Helper class for basic object retrieving, such as blocks, models etc...
 *
 * @deprecated Class under test should be instantiated with `new` keyword with explicit dependencies declaration
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ObjectManager
{
    use GetParameterClassTrait;

    /**
     * Special cases configuration
     *
     * @var array
     */
    protected $_specialCases = [
        \Magento\Framework\Model\ResourceModel\AbstractResource::class => '_getResourceModelMock',
        \Magento\Framework\TranslateInterface::class => '_getTranslatorMock',
    ];

    /**
     * Test object
     *
     * @var \PHPUnit\Framework\TestCase
     */
    protected $_testObject;

    /**
     * Constructor
     *
     * @param \PHPUnit\Framework\TestCase $testObject
     */
    public function __construct(\PHPUnit\Framework\TestCase $testObject)
    {
        $this->_testObject = $testObject;
    }

    /**
     * Get mock for argument
     *
     * @param string $argClassName
     * @param array $originalArguments
     * @return null|object|MockObject
     */
    protected function _createArgumentMock($argClassName, array $originalArguments)
    {
        $object = null;
        if ($argClassName) {
            $object = $this->_processSpecialCases($argClassName, $originalArguments);
            if (null === $object) {
                $object = $this->_getMockWithoutConstructorCall($argClassName);
            }
        }
        return $object;
    }

    /**
     * Process special cases
     *
     * @param string $className
     * @param array $arguments
     * @return null|object
     */
    protected function _processSpecialCases($className, $arguments)
    {
        $object = null;
        $interfaces = class_implements($className);
        if (in_array(\Magento\Framework\ObjectManager\ContextInterface::class, $interfaces)) {
            $object = $this->getObject($className, $arguments);
        } elseif (isset($this->_specialCases[$className])) {
            $method = $this->_specialCases[$className];
            $object = $this->{$method}($className);
        }

        return $object;
    }

    /**
     * Retrieve specific mock of core resource model
     *
     * @return \Magento\Framework\Module\ResourceInterface|MockObject
     */
    protected function _getResourceModelMock()
    {
        $resourceMock = $this->_testObject->getMockBuilder(\Magento\Framework\Module\ModuleResource::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->setMethods(['getIdFieldName', '__sleep', '__wakeup'])
            ->getMock();
        $resourceMock->expects(
            $this->_testObject->any()
        )->method(
            'getIdFieldName'
        )->will(
            $this->_testObject->returnValue('id')
        );

        return $resourceMock;
    }

    /**
     * Retrieve mock of core translator model
     *
     * @param string $className
     * @return \Magento\Framework\TranslateInterface|MockObject
     */
    protected function _getTranslatorMock($className)
    {
        $translator = $this->_testObject->getMockBuilder($className)->disableOriginalConstructor()->getMock();
        $translateCallback = function ($arguments) {
            return is_array($arguments) ? vsprintf(array_shift($arguments), $arguments) : '';
        };
        $translator->expects(
            $this->_testObject->any()
        )->method(
            'translate'
        )->will(
            $this->_testObject->returnCallback($translateCallback)
        );
        return $translator;
    }

    /**
     * Get mock without call of original constructor
     *
     * @param string $className
     * @return MockObject
     */
    protected function _getMockWithoutConstructorCall($className)
    {
        $mock = $this->_testObject->getMockBuilder($className)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->getMock();
        return $mock;
    }

    /**
     * Get class instance
     *
     * @param string $className
     * @param array $arguments
     * @return object
     */
    public function getObject($className, array $arguments = [])
    {
        // phpstan:ignore
        if (is_subclass_of($className, \Magento\Framework\Api\AbstractSimpleObjectBuilder::class)
            || is_subclass_of($className, \Magento\Framework\Api\Builder::class)
        ) {
            return $this->getBuilder($className, $arguments);
        }
        $constructArguments = $this->getConstructArguments($className, $arguments);
        $reflectionClass = new \ReflectionClass($className);
        $newObject = $reflectionClass->newInstanceArgs($constructArguments);

        foreach (array_diff_key($arguments, $constructArguments) as $key => $value) {
            $propertyReflectionClass = $reflectionClass;
            while ($propertyReflectionClass) {
                if ($propertyReflectionClass->hasProperty($key)) {
                    $reflectionProperty = $propertyReflectionClass->getProperty($key);
                    $reflectionProperty->setAccessible(true);
                    $reflectionProperty->setValue($newObject, $value);
                    break;
                }
                $propertyReflectionClass = $propertyReflectionClass->getParentClass();
            }
        }
        return $newObject;
    }

    /**
     * Get data object builder
     *
     * @param string $className
     * @param array $arguments
     * @return object
     */
    protected function getBuilder($className, array $arguments)
    {
        if (!isset($arguments['objectFactory'])) {
            $objectFactory = $this->_testObject->getMockBuilder(\Magento\Framework\Api\ObjectFactory::class)
                ->disableOriginalConstructor()
                ->disableOriginalClone()
                ->disableArgumentCloning()
                ->disallowMockingUnknownTypes()
                ->setMethods(['populateWithArray', 'populate', 'create'])
                ->getMock();

            $objectFactory->expects($this->_testObject->any())
                ->method('populateWithArray')
                ->will($this->_testObject->returnSelf());
            $objectFactory->expects($this->_testObject->any())
                ->method('populate')
                ->will($this->_testObject->returnSelf());
            $objectFactory->expects($this->_testObject->any())
                ->method('create')
                ->will($this->_testObject->returnCallback(
                    function ($className, $arguments) {
                        $reflectionClass = new \ReflectionClass($className);
                        $constructorMethod = $reflectionClass->getConstructor();
                        $parameters = $constructorMethod->getParameters();
                        $args = [];
                        foreach ($parameters as $parameter) {
                            $parameterName = $parameter->getName();
                            if (isset($arguments[$parameterName])) {
                                $args[] = $arguments[$parameterName];
                            } else {
                                if ($parameter->getType() && $parameter->getType()->getName() === 'array') {
                                    $args[] = [];
                                } elseif ($parameter->allowsNull()) {
                                    $args[] = null;
                                } else {
                                    $parameterClass = $this->getParameterClass($parameter);
                                    $mock = $this->_getMockWithoutConstructorCall($parameterClass->getName());
                                    $args[] = $mock;
                                }
                            }
                        }
                        return new $className(...array_values($args));
                    }
                ));

            $arguments['objectFactory'] = $objectFactory;
        }

        return new $className(...array_values($this->getConstructArguments($className, $arguments)));
    }

    /**
     * Retrieve associative array of arguments that used for new object instance creation
     *
     * @param string $className
     * @param array $arguments
     * @return array
     */
    public function getConstructArguments($className, array $arguments = [])
    {
        $constructArguments = [];
        if (!method_exists($className, '__construct')) {
            return $constructArguments;
        }
        $method = new \ReflectionMethod($className, '__construct');

        foreach ($method->getParameters() as $parameter) {
            $parameterName = $parameter->getName();
            $argClassName = null;
            $defaultValue = null;

            if (array_key_exists($parameterName, $arguments)) {
                $constructArguments[$parameterName] = $arguments[$parameterName];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $defaultValue = $parameter->getDefaultValue();
            }

            $object = null;
            try {
                if ($parameterClass = $this->getParameterClass($parameter)) {
                    $argClassName = $parameterClass->getName();
                }
                $object = $this->_getMockObject($argClassName, $arguments);
            } catch (\ReflectionException $e) {
                $parameterString = $parameter->__toString();
                $firstPosition = strpos($parameterString, '<required>');
                if ($firstPosition !== false) {
                    $parameterString = substr($parameterString, $firstPosition + 11);
                    $parameterString = substr($parameterString, 0, strpos($parameterString, ' '));
                    $object = $this->_testObject->getMockBuilder($parameterString)
                        ->disableOriginalConstructor()
                        ->disableOriginalClone()
                        ->disableArgumentCloning()
                        ->disallowMockingUnknownTypes()
                        ->getMock();
                }
            }

            $constructArguments[$parameterName] = null === $object ? $defaultValue : $object;
        }
        return $constructArguments;
    }

    /**
     * Get collection mock
     *
     * @param string $className
     * @param array $data
     * @return MockObject
     * @throws \InvalidArgumentException
     */
    public function getCollectionMock($className, array $data)
    {
        if (!is_subclass_of($className, \Magento\Framework\Data\Collection::class)) {
            throw new \InvalidArgumentException(
                $className . ' does not instance of \Magento\Framework\Data\Collection'
            );
        }
        $mock = $this->_testObject->getMockBuilder($className)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->getMock();
        $iterator = new \ArrayIterator($data);
        $mock->expects(
            $this->_testObject->any()
        )->method(
            'getIterator'
        )->will(
            $this->_testObject->returnValue($iterator)
        );
        return $mock;
    }

    /**
     * Helper function that creates a mock object for a given class name.
     *
     * Will return a real object in some cases to assist in testing.
     *
     * @param string $argClassName
     * @param array $arguments
     * @return null|object|MockObject
     */
    private function _getMockObject($argClassName, array $arguments)
    {
        // phpstan:ignore
        if (is_subclass_of($argClassName, \Magento\Framework\Api\ExtensibleObjectBuilder::class)) {
            return $this->getBuilder($argClassName, $arguments);
        }

        return $this->_createArgumentMock($argClassName, $arguments);
    }

    /**
     * Set mocked property
     *
     * @param object $object
     * @param string $propertyName
     * @param object $propertyValue
     * @param string $className The namespace of parent class for injection private property into this class
     * @return void
     */
    public function setBackwardCompatibleProperty($object, $propertyName, $propertyValue, $className = '')
    {
        $reflection = new \ReflectionClass($className ? $className : get_class($object));
        $reflectionProperty = $reflection->getProperty($propertyName);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $propertyValue);
    }
}
