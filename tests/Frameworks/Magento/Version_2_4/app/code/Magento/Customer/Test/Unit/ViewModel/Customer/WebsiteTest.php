<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Customer\Test\Unit\ViewModel\Customer;

use Magento\Customer\ViewModel\Customer\Website as CustomerWebsite;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Store\Model\System\Store as SystemStore;
use PHPUnit\Framework\TestCase;
use Magento\Store\Model\Website;
use Magento\Store\Model\Store;

/**
 * Test for customer's website view model
 */
class WebsiteTest extends TestCase
{
    /** @var ObjectManagerHelper */
    private $objectManagerHelper;

    /**
     * @var CustomerWebsite
     */
    private $customerWebsite;

    /**
     * @var SystemStore
     */
    private $systemStore;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    protected function setUp(): void
    {
        $this->systemStore = $this->createMock(SystemStore::class);
        $this->scopeConfig = $this->getMockForAbstractClass(ScopeConfigInterface::class);
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->customerWebsite = $this->objectManagerHelper->getObject(
            CustomerWebsite::class,
            [
                'systemStore' => $this->systemStore,
                'scopeConfig' => $this->scopeConfig
            ]
        );
        $websiteMock1 = $this->createPartialMock(Website::class, ['getId', 'getDefaultStore']);
        $websiteMock2 = $this->createPartialMock(Website::class, ['getId', 'getDefaultStore']);
        $storeMock1 = $this->createPartialMock(Store::class, ['getId']);
        $storeMock2 = $this->createPartialMock(Store::class, ['getId']);

        $storeMock1->method('getId')->willReturn('1');
        $websiteMock1->method('getId')->willReturn('1');
        $websiteMock1->method('getDefaultStore')->willReturn($storeMock1);

        $storeMock2->method('getId')->willReturn('2');
        $websiteMock2->method('getId')->willReturn('2');
        $websiteMock2->method('getDefaultStore')->willReturn($storeMock2);

        $this->systemStore->method('getWebsiteCollection')->willReturn([$websiteMock1, $websiteMock2]);
    }

    /**
     * Test that method return correct array of options
     *
     * @param array $options
     * @dataProvider dataProviderOptionsArray
     * @return void
     */
    public function testToOptionArray(array $options): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturn(1);

        $this->systemStore->method('getWebsiteValuesForForm')
            ->willReturn([
                [
                    'label' => 'Main Website',
                    'value' => '1',
                ],
                [
                    'label' => 'Second Website',
                    'value' => '2',
                ],
            ]);

        $this->assertEquals($options, $this->customerWebsite->toOptionArray());
    }

    /**
     * Data provider for testToOptionArray test
     *
     * @return array
     */
    public function dataProviderOptionsArray(): array
    {
        return [
            [
                'options' => [
                    [
                        'label' => 'Main Website',
                        'value' => '1',
                        'group_id' => '1',
                        'default_store_view_id' => '1',
                    ],
                    [
                        'label' => 'Second Website',
                        'value' => '2',
                        'group_id' => '1',
                        'default_store_view_id' => '2',
                    ],
                ],
            ],
        ];
    }
}
