<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\ObjectManager\Test\Unit\Factory\Fixture;

/**
 * A constructor with 2 dependencies: one injectable, another scalar
 */
class Two
{
    /**
     * @var OneScalar
     */
    private $one;

    /**
     * @var string
     */
    private $baz;

    /**
     * @param OneScalar $one
     * @param string $baz
     */
    public function __construct(OneScalar $one, $baz = 'optional')
    {
        $this->one = $one;
        $this->baz = $baz;
    }

    /**
     * @return OneScalar
     */
    public function getOne()
    {
        return $this->one;
    }

    /**
     * @return string
     */
    public function getBaz()
    {
        return $this->baz;
    }
}
