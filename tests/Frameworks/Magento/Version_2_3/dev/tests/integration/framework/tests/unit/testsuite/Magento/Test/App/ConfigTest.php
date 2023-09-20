<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\TestFramework\App\Config.
 */
namespace Magento\Test\App;

use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\TestFramework\App\Config;

class ConfigTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Config
     */
    private $model;

    protected function setUp(): void
    {
        $scopeCodeResolver = $this->getMockBuilder(ScopeCodeResolver::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->model = new Config($scopeCodeResolver);
    }

    public function testGet()
    {
        $configType = "system";
        $path = "stores/one";
        $value = 1;
        $this->model->setValue($path, $value, 'default', 'one');

        $this->assertEquals($value, $this->model->get($configType, 'default/stores/one'));
    }

    public function testClean()
    {
        $configType = "system";
        $path = "stores/one";
        $value = 1;
        $this->model->setValue($path, $value, 'default', 'one');
        $this->assertEquals($value, $this->model->get($configType, 'default/stores/one'));
        $this->model->clean();
        $this->assertNull($this->model->get($configType, 'default/stores/one'));
    }
}
