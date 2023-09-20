<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Event\Test\Unit\Config;

class SchemaLocatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\App\ResourceConnection\Config\SchemaLocator
     */
    protected $model;

    /** @var \Magento\Framework\Config\Dom\UrnResolver */
    protected $urnResolver;

    /** @var \Magento\Framework\Config\Dom\UrnResolver */
    protected $urnResolverMock;

    protected function setUp(): void
    {
        $this->urnResolver = new \Magento\Framework\Config\Dom\UrnResolver();
        $this->urnResolverMock = $this->createMock(\Magento\Framework\Config\Dom\UrnResolver::class);
        $this->model = new \Magento\Framework\Event\Config\SchemaLocator($this->urnResolverMock);
    }

    public function testGetSchema()
    {
        $this->urnResolverMock->expects($this->once())
            ->method('getRealPath')
            ->with('urn:magento:framework:Event/etc/events.xsd')
            ->willReturn(
                $this->urnResolver->getRealPath('urn:magento:framework:Event/etc/events.xsd')
            );
        $this->assertEquals(
            $this->urnResolver->getRealPath('urn:magento:framework:Event/etc/events.xsd'),
            $this->model->getSchema()
        );
    }

    public function testGetPerFileSchema()
    {
        $this->urnResolverMock->expects($this->once())
            ->method('getRealPath')
            ->with('urn:magento:framework:Event/etc/events.xsd')
            ->willReturn(
                $this->urnResolver->getRealPath('urn:magento:framework:Event/etc/events.xsd')
            );
        $this->assertEquals(
            $this->urnResolver->getRealPath('urn:magento:framework:Event/etc/events.xsd'),
            $this->model->getPerFileSchema()
        );
    }
}
