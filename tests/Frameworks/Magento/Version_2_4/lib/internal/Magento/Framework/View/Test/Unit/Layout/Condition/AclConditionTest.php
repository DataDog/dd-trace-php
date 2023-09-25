<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\View\Test\Unit\Layout\Condition;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Layout\AclCondition;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AclConditionTest extends TestCase
{
    /**
     * @var AclCondition
     */
    protected $model;

    /**
     * @var AuthorizationInterface|MockObject
     */
    private $authorizationMock;

    protected function setUp(): void
    {
        $this->authorizationMock = $this->getMockBuilder(AuthorizationInterface::class)
            ->getMock();
        $this->model = new AclCondition($this->authorizationMock);
    }

    public function testFilterAclElements()
    {
        $this->authorizationMock->expects($this->any())
            ->method('isAllowed')
            ->willReturnMap(
                [
                    ['acl_authorised', null, true],
                    ['acl_non_authorised', null, false],
                ]
            );
        $this->assertTrue($this->model->isVisible(['acl' => 'acl_authorised']));
        $this->assertFalse($this->model->isVisible(['acl' => 'acl_non_authorised']));
    }
}
