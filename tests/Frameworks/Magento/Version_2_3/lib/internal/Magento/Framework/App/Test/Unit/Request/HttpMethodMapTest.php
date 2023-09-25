<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Framework\App\Test\Unit\Request;

use Magento\Framework\App\Request\HttpMethodMap;
use PHPUnit\Framework\TestCase;

class HttpMethodMapTest extends TestCase
{
    /**
     * Test filtering of interface names.
     */
    public function testFilter()
    {
        $map = new HttpMethodMap(
            ['method1' => '\\Throwable', 'method2' => 'DateTime']
        );
        $this->assertEquals(
            ['method1' => \Throwable::class, 'method2' => \DateTime::class],
            $map->getMap()
        );
    }

    /**
     * Test validation of interface names.
     *
     */
    public function testExisting()
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpMethodMap(['method1' => 'NonExistingClass']);
    }

    /**
     * Test validation of method names.
     *
     */
    public function testMethod()
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpMethodMap([\Throwable::class]);
    }
}
