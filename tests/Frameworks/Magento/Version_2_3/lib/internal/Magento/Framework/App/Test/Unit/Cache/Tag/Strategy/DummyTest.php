<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\App\Test\Unit\Cache\Tag\Strategy;

use \Magento\Framework\App\Cache\Tag\Strategy\Dummy;

class DummyTest extends \PHPUnit\Framework\TestCase
{

    private $model;

    protected function setUp(): void
    {
        $this->model = new Dummy();
    }

    public function testGetTagsWithScalar()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provided argument is not an object');
        $this->model->getTags('scalar');
    }

    public function testGetTagsWithObject()
    {
        $emptyArray = [];

        $this->assertEquals($emptyArray, $this->model->getTags(new \stdClass));

        $identityInterface = $this->getMockForAbstractClass(\Magento\Framework\DataObject\IdentityInterface::class);
        $this->assertEquals($emptyArray, $this->model->getTags($identityInterface));
    }
}
