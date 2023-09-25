<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Code\Test\Unit\Generator\TestAsset;

use Laminas\Code\Generator\ClassGenerator;

/**
 * phpcs:ignoreFile
 */
class SourceClass extends ParentClass
{
    /**
     * Public child constructor
     *
     * @param ClassGenerator $classGenerator
     * @param string $param1
     * @param string $param2
     * @param string $param3
     * @param null $param4
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        ClassGenerator $classGenerator,
        $param1 = '',
        $param2 = '\\',
        $param3 = '\'',
        $param4 = null
    ) {
    }

    /**
     * Public child method
     *
     * @param ClassGenerator $classGenerator
     * @param string $param1
     * @param string $param2
     * @param string $param3
     * @param array $array
     * @param null $param5
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function publicChildMethod(
        ClassGenerator $classGenerator,
        $param1 = '',
        $param2 = '\\',
        $param3 = '\'',
        array $array = [],
        $param5 = null
    ) {
    }

    /**
     * Public child method with reference
     *
     * @param ClassGenerator $classGenerator
     * @param array $array
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function publicMethodWithReference(ClassGenerator &$classGenerator, array &$array)
    {
    }

    /**
     * Protected child method
     *
     * @param ClassGenerator $classGenerator
     * @param string $param1
     * @param string $param2
     * @param string $param3
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _protectedChildMethod(
        ClassGenerator $classGenerator,
        $param1 = '',
        $param2 = '\\',
        $param3 = '\''
    ) {
    }

    /**
     * Private child method
     *
     * @param ClassGenerator $classGenerator
     * @param string $param1
     * @param string $param2
     * @param string $param3
     * @param array $array
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function _privateChildMethod(
        ClassGenerator $classGenerator,
        $param1 = '',
        $param2 = '\\',
        $param3 = '\'',
        array $array = []
    ) {
    }

    public function publicChildWithoutParameters()
    {
    }

    public static function publicChildStatic()
    {
    }
    
    final public function publicChildFinal()
    {
    }
}
