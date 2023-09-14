<?php
/***
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Version\Controller\Index;

class IndexTest extends \Magento\TestFramework\TestCase\AbstractController
{
    public function testIndexAction()
    {
        // Execute controller to get version response
        $this->dispatch('magento_version/index/index');
        $body = $this->getResponse()->getBody();

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Framework\App\ProductMetadataInterface $productMetadata */
        $productMetadata = $objectManager->get(\Magento\Framework\App\ProductMetadataInterface::class);
        $name = $productMetadata->getName();
        $edition = $productMetadata->getEdition();

        $fullVersion = $productMetadata->getVersion();
        if ($this->isComposerBasedInstallation($fullVersion)) {
            $versionParts = explode('.', $fullVersion);
            $majorMinor = $versionParts[0] . '.' . $versionParts[1];

            // Response must contain Major.Minor version, product name, and edition
            $this->assertStringContainsString($majorMinor, $body);
            $this->assertStringContainsString($name, $body);
            $this->assertStringContainsString($edition, $body);

            // Response must not contain full version including patch version
            $this->assertStringNotContainsString($fullVersion, $body);
        } else {
            // Response is supposed to be empty when the project is installed from git
            $this->assertEmpty($body);
        }
    }

    private function isComposerBasedInstallation($fullVersion)
    {
        $versionParts = explode('-', $fullVersion);
        return !(isset($versionParts[0]) && $versionParts[0] == 'dev');
    }
}
