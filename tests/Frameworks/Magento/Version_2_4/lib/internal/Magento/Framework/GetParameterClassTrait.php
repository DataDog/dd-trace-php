<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework;

use ReflectionClass;
use ReflectionParameter;

/**
 * Returns a reflection parameter's class if possible.
 */
trait GetParameterClassTrait
{
    /**
     * Get class by reflection parameter
     *
     * @param ReflectionParameter $reflectionParameter
     *
     * @return ReflectionClass|null
     * @throws ReflectionException
     */
    private function getParameterClass(ReflectionParameter $reflectionParameter): ?ReflectionClass
    {
        $parameterType = $reflectionParameter->getType();
        // In PHP8, $parameterType could be an instance of ReflectionUnionType, which doesn't have isBuiltin method.
        if ($parameterType !== null && method_exists($parameterType, 'isBuiltin') === false) {
            return null;
        }

        return $parameterType && !$parameterType->isBuiltin()
            ? new ReflectionClass($parameterType->getName())
            : null;
    }
}
