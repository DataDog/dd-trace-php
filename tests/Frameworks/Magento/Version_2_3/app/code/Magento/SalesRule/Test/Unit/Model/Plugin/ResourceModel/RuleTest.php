<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\SalesRule\Test\Unit\Model\Plugin\ResourceModel;

class RuleTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\SalesRule\Model\Plugin\ResourceModel\Rule
     */
    protected $plugin;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $ruleResource;

    /**
     * @var \Closure
     */
    protected $genericClosure;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $abstractModel;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->ruleResource = $this->getMockBuilder(\Magento\SalesRule\Model\ResourceModel\Rule::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->genericClosure = function () {
            return;
        };
        $this->abstractModel = $this->getMockBuilder(\Magento\Framework\Model\AbstractModel::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->plugin = $objectManager->getObject(\Magento\SalesRule\Model\Plugin\ResourceModel\Rule::class);
    }

    public function testAroundLoadCustomerGroupIds()
    {
        $this->assertEquals(
            $this->ruleResource,
            $this->plugin->aroundLoadCustomerGroupIds($this->ruleResource, $this->genericClosure, $this->abstractModel)
        );
    }

    public function testAroundLoadWebsiteIds()
    {
        $this->assertEquals(
            $this->ruleResource,
            $this->plugin->aroundLoadWebsiteIds($this->ruleResource, $this->genericClosure, $this->abstractModel)
        );
    }
}
