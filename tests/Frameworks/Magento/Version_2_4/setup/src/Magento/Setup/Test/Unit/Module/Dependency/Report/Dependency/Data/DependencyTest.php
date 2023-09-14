<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Setup\Test\Unit\Module\Dependency\Report\Dependency\Data;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Setup\Module\Dependency\Report\Dependency\Data\Dependency;

use PHPUnit\Framework\TestCase;

class DependencyTest extends TestCase
{
    /**
     * @param string $module
     * @param string|null $type One of \Magento\Setup\Module\Dependency\Dependency::TYPE_ const
     * @return Dependency
     */
    protected function createDependency($module, $type = null)
    {
        $objectManagerHelper = new ObjectManager($this);
        return $objectManagerHelper->getObject(
            Dependency::class,
            ['module' => $module, 'type' => $type]
        );
    }

    public function testGetModule()
    {
        $module = 'module';

        $dependency = $this->createDependency($module);

        $this->assertEquals($module, $dependency->getModule());
    }

    public function testGetType()
    {
        $type = Dependency::TYPE_SOFT;

        $dependency = $this->createDependency('module', $type);

        $this->assertEquals($type, $dependency->getType());
    }

    public function testThatHardTypeIsDefault()
    {
        $dependency = $this->createDependency('module');

        $this->assertEquals(Dependency::TYPE_HARD, $dependency->getType());
    }

    public function testThatHardTypeIsDefaultIfPassedWrongType()
    {
        $dependency = $this->createDependency('module', 'wrong_type');

        $this->assertEquals(Dependency::TYPE_HARD, $dependency->getType());
    }
}
