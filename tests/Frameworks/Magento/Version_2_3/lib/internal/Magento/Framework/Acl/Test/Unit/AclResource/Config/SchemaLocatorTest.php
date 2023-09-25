<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Acl\Test\Unit\AclResource\Config;

class SchemaLocatorTest extends \PHPUnit\Framework\TestCase
{
    public function testGetSchema()
    {
        $urnResolver = new \Magento\Framework\Config\Dom\UrnResolver();
        /** @var \Magento\Framework\Config\Dom\UrnResolver $urnResolverMock */
        $urnResolverMock = $this->createMock(\Magento\Framework\Config\Dom\UrnResolver::class);
        $urnResolverMock->expects($this->once())
            ->method('getRealPath')
            ->with('urn:magento:framework:Acl/etc/acl_merged.xsd')
            ->willReturn($urnResolver->getRealPath('urn:magento:framework:Acl/etc/acl_merged.xsd'));
        $schemaLocator = new \Magento\Framework\Acl\AclResource\Config\SchemaLocator($urnResolverMock);
        $this->assertEquals(
            $urnResolver->getRealPath('urn:magento:framework:Acl/etc/acl_merged.xsd'),
            $schemaLocator->getSchema()
        );
    }
}
