<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Csp\Model\Collector;

use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Test collecting csp_whitelist configurations.
 */
class CspWhitelistXmlCollectorTest extends TestCase
{
    /**
     * @var CspWhitelistXmlCollector
     */
    private $collector;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->collector = Bootstrap::getObjectManager()->get(CspWhitelistXmlCollector::class);
    }

    /**
     * Test collecting configurations from multiple XML files.
     *
     * @return void
     */
    public function testCollecting(): void
    {
        $policies = $this->collector->collect([]);

        $mediaSrcChecked = false;
        $objectSrcChecked = false;
        $this->assertNotEmpty($policies);
        /** @var FetchPolicy $policy */
        foreach ($policies as $policy) {
            $this->assertFalse($policy->isNoneAllowed());
            $this->assertFalse($policy->isSelfAllowed());
            $this->assertFalse($policy->isInlineAllowed());
            $this->assertFalse($policy->isEvalAllowed());
            $this->assertFalse($policy->isDynamicAllowed());
            $this->assertEmpty($policy->getSchemeSources());
            $this->assertEmpty($policy->getNonceValues());
            if ($policy->getId() === 'object-src') {
                $this->assertInstanceOf(FetchPolicy::class, $policy);
                $this->assertEquals(['http://magento.com', 'https://devdocs.magento.com'], $policy->getHostSources());
                $this->assertEquals(
                    [
                        'B2yPHKaXnvFWtRChIbabYmUBFZdVfKKXHbWtWidDVF8=' => 'sha256',
                        'B2yPHKaXnvFWtRChIbabYmUBFZdVfKKXHbWtWidDVF9=' => 'sha256'
                    ],
                    $policy->getHashes()
                );
                $objectSrcChecked = true;
            } elseif ($policy->getId() === 'media-src') {
                $this->assertInstanceOf(FetchPolicy::class, $policy);
                $this->assertEquals(
                    ['*.adobe.com', 'https://magento.com', 'https://devdocs.magento.com'],
                    $policy->getHostSources()
                );
                $this->assertEmpty($policy->getHashes());
                $mediaSrcChecked = true;
            }
        }
        $this->assertTrue($objectSrcChecked);
        $this->assertTrue($mediaSrcChecked);
    }

    /**
     * Test collecting configurations from multiple XML files for adminhtml area.
     *
     * @magentoAppArea adminhtml
     * @return void
     */
    public function testCollectingForAdminhtmlArea(): void
    {
        $policies = $this->collector->collect([]);

        $mediaSrcChecked = false;
        $objectSrcChecked = false;
        $this->assertNotEmpty($policies);
        /** @var FetchPolicy $policy */
        foreach ($policies as $policy) {
            $this->assertFalse($policy->isNoneAllowed());
            $this->assertFalse($policy->isSelfAllowed());
            $this->assertFalse($policy->isInlineAllowed());
            $this->assertFalse($policy->isEvalAllowed());
            $this->assertFalse($policy->isDynamicAllowed());
            $this->assertEmpty($policy->getSchemeSources());
            $this->assertEmpty($policy->getNonceValues());
            if ($policy->getId() === 'object-src') {
                $this->assertInstanceOf(FetchPolicy::class, $policy);
                $this->assertEquals(
                    [
                        'https://admin.magento.com',
                        'https://devdocs.magento.com',
                        'example.magento.com'
                    ],
                    $policy->getHostSources()
                );
                $this->assertEquals(
                    [
                        'B2yPHKaXnvFWtRChIbabYmUBFZdVfKKXHbWtWidDVF8=' => 'sha256',
                        'B2yPHKaXnvFWtRChIbabYmUBFZdVfKKXHbWtWidDVF9=' => 'sha256'
                    ],
                    $policy->getHashes()
                );
                $objectSrcChecked = true;
            } elseif ($policy->getId() === 'media-src') {
                $this->assertInstanceOf(FetchPolicy::class, $policy);
                $this->assertEquals(
                    [
                        '*.adobe.com',
                        'https://admin.magento.com',
                        'https://devdocs.magento.com',
                        'example.magento.com'
                    ],
                    $policy->getHostSources()
                );
                $this->assertEmpty($policy->getHashes());
                $mediaSrcChecked = true;
            }
        }
        $this->assertTrue($objectSrcChecked);
        $this->assertTrue($mediaSrcChecked);
    }
}
