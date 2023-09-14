<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\TestModuleOverrideConfig\MagentoDataFixture;

use Magento\TestModuleOverrideConfig\AbstractOverridesTest;
use Magento\TestModuleOverrideConfig\Model\FixtureCallStorage;

/**
 * Class checks that magentoConfigFixtures can be placed into certain place using override config
 *
 * @magentoAppIsolation enabled
 */
class SortFixturesTest extends AbstractOverridesTest
{
    /** @var FixtureCallStorage */
    private $fixtureCallStorage;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpstan:ignore "Class Magento\TestModuleOverrideConfig\Model\FixtureCallStorage not found."
        $this->fixtureCallStorage = $this->objectManager->get(FixtureCallStorage::class);
    }

    /**
     * Checks that fixtures can be placed to specific place according to config
     *
     * @dataProvider sortFixturesProvider
     *
     * @magentoDataFixture Magento/TestModuleOverrideConfig/_files/fixture1_first_module.php
     * @magentoDataFixture Magento/TestModuleOverrideConfig/_files/fixture2_first_module.php
     * @magentoDataFixture Magento/TestModuleOverrideConfig/_files/fixture3_first_module.php
     *
     * @param array $sortedFixtures
     * @return void
     */
    public function testSortFixtures(array $sortedFixtures): void
    {
        $this->assertEquals($sortedFixtures, $this->fixtureCallStorage->getStorage());
    }

    /**
     * @return array
     */
    public function sortFixturesProvider(): array
    {
        return [
            'first_data_set' => [
                'sorted_fixtures' => [
                    'fixture3_second_module.php',
                    'fixture1_first_module.php',
                    'fixture1_second_module.php',
                    'fixture2_first_module.php',
                    'fixture1_third_module.php',
                    'fixture3_first_module.php',
                    'global_fixture_first_module.php',// globally added fixture
                    'fixture2_second_module.php',
                ],
            ],
            'second_data_set' => [
                'sorted_fixtures' => [
                    'fixture1_first_module.php',
                    'fixture1_second_module.php',
                    'fixture2_first_module.php',
                    'fixture3_first_module.php',
                    'global_fixture_first_module.php',// globally added fixture
                    'fixture2_second_module.php',
                ],
            ],
        ];
    }
}
