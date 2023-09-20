<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\ObjectManager\Test\Unit\Factory\Fixture;

/**
 * Constructor with undefined number of arguments
 */
class Polymorphous
{
    /**
     * @var array
     */
    private $args;

    public function __construct()
    {
        $this->args = func_get_args();
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getArg($key)
    {
        return $this->args[$key] ?? null;
    }
}
