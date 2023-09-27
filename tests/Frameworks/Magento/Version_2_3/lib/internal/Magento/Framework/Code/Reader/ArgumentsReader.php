<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Code\Reader;

use Laminas\Code\Reflection\MethodReflection;
use Laminas\Code\Reflection\ParameterReflection;

/**
 * Reader for a class arguments
 */
class ArgumentsReader
{
    const NO_DEFAULT_VALUE = 'NO-DEFAULT';

    /**
     * @var NamespaceResolver
     */
    private $namespaceResolver;

    /**
     * @var ScalarTypesProvider
     */
    private $scalarTypesProvider;

    /**
     * @param NamespaceResolver|null $namespaceResolver
     * @param ScalarTypesProvider|null $scalarTypesProvider
     */
    public function __construct(
        NamespaceResolver $namespaceResolver = null,
        ScalarTypesProvider $scalarTypesProvider = null
    ) {
        $this->namespaceResolver = $namespaceResolver ?: new NamespaceResolver();
        $this->scalarTypesProvider = $scalarTypesProvider ?: new ScalarTypesProvider();
    }

    /**
     * Get class constructor
     *
     * @param \ReflectionClass $class
     * @param bool $groupByPosition
     * @param bool $inherited
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @throws \ReflectionException
     */
    public function getConstructorArguments(\ReflectionClass $class, $groupByPosition = false, $inherited = false)
    {
        $output = [];
        /**
         * Skip native PHP types, classes without constructor
         */
        if ($class->isInterface() || !$class->getFileName() || false == $class->hasMethod(
            '__construct'
        ) || !$inherited && $class->getConstructor()->class != $class->getName()
        ) {
            return $output;
        }

        $constructor = new MethodReflection($class->getName(), '__construct');
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $position = $parameter->getPosition();
            $index = $groupByPosition ? $position : $name;
            $default = null;
            if ($parameter->isOptional()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $value = $parameter->getDefaultValue();
                    if (true == is_array($value)) {
                        $default = $this->_varExportMin($value);
                    } elseif (true == is_int($value)) {
                        $default = $value;
                    } else {
                        $default = $parameter->getDefaultValue();
                    }
                } elseif ($parameter->allowsNull()) {
                    $default = null;
                }
            }

            $output[$index] = [
                'name' => $name,
                'position' => $position,
                'type' => $this->processType($class, $parameter),
                'isOptional' => $parameter->isOptional(),
                'default' => $default,
            ];
        }
        return $output;
    }

    /**
     * Process argument type.
     *
     * @param \ReflectionClass $class
     * @param ParameterReflection $parameter
     * @return string
     */
    private function processType(\ReflectionClass $class, ParameterReflection $parameter)
    {
        if ($parameter->getClass()) {
            return NamespaceResolver::NS_SEPARATOR . $parameter->getClass()->getName();
        }

        $type =  $parameter->detectType();

        if ($type === 'null') {
            return null;
        }

        if (strpos($type, '[]') !== false) {
            return 'array';
        }

        if (!in_array($type, $this->scalarTypesProvider->getTypes())) {
            $availableNamespaces = $this->namespaceResolver->getImportedNamespaces(file($class->getFileName()));
            $availableNamespaces[0] = $class->getNamespaceName();
            return $this->namespaceResolver->resolveNamespace($type, $availableNamespaces);
        }

        return $type;
    }

    /**
     * Get arguments of parent __construct call
     *
     * @param \ReflectionClass $class
     * @param array $classArguments
     * @return array|null
     */
    public function getParentCall(\ReflectionClass $class, array $classArguments)
    {
        /** Skip native PHP types */
        if (!$class->getFileName()) {
            return null;
        }

        $trimFunction = function (&$value) {
            $value = trim($value, PHP_EOL . ' $');
        };

        $method = $class->getMethod('__construct');
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        $length = $end - $start;

        $source = file($class->getFileName());
        $content = implode('', array_slice($source, $start, $length));
        $pattern = '/parent::__construct\(([ ' .
            PHP_EOL .
            ']*[$]{1}[a-zA-Z0-9_]*,)*[ ' .
            PHP_EOL .
            ']*' .
            '([$]{1}[a-zA-Z0-9_]*){1}[' .
            PHP_EOL .
            ' ]*\);/';

        if (!preg_match($pattern, $content, $matches)) {
            return null;
        }

        $arguments = $matches[0];
        if (!trim($arguments)) {
            return null;
        }

        $arguments = substr(trim($arguments), 20, -2);
        $arguments = explode(',', $arguments);
        array_walk($arguments, $trimFunction);

        $output = [];
        foreach ($arguments as $argumentPosition => $argumentName) {
            $type = isset($classArguments[$argumentName]) ? $classArguments[$argumentName]['type'] : null;
            $output[$argumentPosition] = [
                'name' => $argumentName,
                'position' => $argumentPosition,
                'type' => $type,
            ];
        }
        return $output;
    }

    /**
     * Check argument type compatibility
     *
     * @param string $requiredType
     * @param string $actualType
     * @return bool
     */
    public function isCompatibleType($requiredType, $actualType)
    {
        /** Types are compatible if type names are equal */
        if ($requiredType === $actualType) {
            return true;
        }

        /** Types are 'semi-compatible' if one of them are undefined */
        if ($requiredType === null || $actualType === null) {
            return true;
        }

        /**
         * Special case for scalar arguments
         * Array type is compatible with array or null type. Both of these types are checked above
         */
        if ($requiredType === 'array' || $actualType === 'array') {
            return false;
        }

        if ($requiredType === 'mixed' || $actualType === 'mixed') {
            return true;
        }

        return is_subclass_of($actualType, $requiredType);
    }

    /**
     * Export variable value
     *
     * @param mixed $var
     * @return mixed|string
     */
    protected function _varExportMin($var)
    {
        if (is_array($var)) {
            $toImplode = [];
            foreach ($var as $key => $value) {
                $toImplode[] = var_export($key, true) . ' => ' . $this->_varExportMin($value);
            }
            $code = 'array(' . implode(', ', $toImplode) . ')';
            return $code;
        } else {
            return var_export($var, true);
        }
    }

    /**
     * Get constructor annotations
     *
     * @param \ReflectionClass $class
     * @return array
     */
    public function getAnnotations(\ReflectionClass $class)
    {
        $regexp = '(@([a-z_][a-z0-9_]+)\(([^\)]+)\))i';
        $docBlock = $class->getConstructor()->getDocComment();
        $annotations = [];
        preg_match_all($regexp, $docBlock, $matches);
        foreach (array_keys($matches[0]) as $index) {
            $name = $matches[1][$index];
            $value = trim($matches[2][$index], '" ');
            $annotations[$name] = $value;
        }

        return $annotations;
    }
}
