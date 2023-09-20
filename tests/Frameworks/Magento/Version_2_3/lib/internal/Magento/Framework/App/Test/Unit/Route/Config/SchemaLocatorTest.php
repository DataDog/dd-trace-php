<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Test\Unit\Route\Config;

class SchemaLocatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\App\Route\Config\SchemaLocator
     */
    protected $config;

    /** @var \Magento\Framework\Config\Dom\UrnResolver */
    protected $urnResolver;

    /** @var \Magento\Framework\Config\Dom\UrnResolver */
    protected $urnResolverMock;

    protected function setUp(): void
    {
        $this->urnResolver = new \Magento\Framework\Config\Dom\UrnResolver();
        /** @var \Magento\Framework\Config\Dom\UrnResolver $urnResolverMock */
        $this->urnResolverMock = $this->createMock(\Magento\Framework\Config\Dom\UrnResolver::class);
        $this->config = new \Magento\Framework\App\Route\Config\SchemaLocator($this->urnResolverMock);
    }

    public function testGetSchema()
    {
        $this->urnResolverMock->expects($this->once())
            ->method('getRealPath')
            ->with('urn:magento:framework:App/etc/routes_merged.xsd')
            ->willReturn(
                $this->urnResolver->getRealPath('urn:magento:framework:App/etc/routes_merged.xsd')
            );
        $this->assertStringContainsString(
            $this->urnResolver->getRealPath('urn:magento:framework:App/etc/routes_merged.xsd'),
            $this->config->getSchema()
        );
    }

    public function testGetPerFileSchema()
    {
        $this->urnResolverMock->expects($this->once())
            ->method('getRealPath')
            ->with('urn:magento:framework:App/etc/routes.xsd')
            ->willReturn(
                $this->urnResolver->getRealPath('urn:magento:framework:App/etc/routes.xsd')
            );
        $this->assertStringContainsString(
            $this->urnResolver->getRealPath('urn:magento:framework:App/etc/routes.xsd'),
            $this->config->getPerFileSchema()
        );
    }
}
