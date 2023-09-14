<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 *
 */
namespace Magento\Integration\Block\Adminhtml\Integration\Activate\Permissions\Tab;

use Magento\Integration\Controller\Adminhtml\Integration as IntegrationController;
use Magento\Integration\Model\Integration;

/**
 * @magentoDataFixture Magento/Integration/_files/integration_all_permissions.php
 */
class WebapiTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\Registry */
    protected $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->registry = $objectManager->get(\Magento\Framework\Registry::class);
    }

    protected function tearDown(): void
    {
        $this->registry->unregister(IntegrationController::REGISTRY_KEY_CURRENT_INTEGRATION);
        parent::tearDown();
    }

    public function testGetSelectedResourcesJsonEmpty()
    {
        $expectedResult = '[]';
        $this->assertEquals($expectedResult, $this->createApiTabBlock()->getSelectedResourcesJson());
    }

    public function testGetSelectedResourcesJson()
    {
        $expectedResult = '["Magento_Backend::dashboard",';
        $this->registry->register(
            IntegrationController::REGISTRY_KEY_CURRENT_INTEGRATION,
            $this->getFixtureIntegration()->getData()
        );
        $this->assertStringContainsString($expectedResult, $this->createApiTabBlock()->getSelectedResourcesJson());
    }

    public function testGetResourcesTreeJson()
    {
        $expectedResult = '[{"id":"Magento_Backend::dashboard","li_attr":{"data-id":"Magento_Backend::dashboard"},' .
            '"text":"Dashboard","children":[],"state":';
        $this->registry->register(
            IntegrationController::REGISTRY_KEY_CURRENT_INTEGRATION,
            $this->getFixtureIntegration()->getData()
        );
        $this->assertStringContainsString($expectedResult, $this->createApiTabBlock()->getResourcesTreeJson());
    }

    public function testCanShowTabNegative()
    {
        $this->assertFalse($this->createApiTabBlock()->canShowTab());
    }

    public function testCanShowTabPositive()
    {
        $integrationData = $this->getFixtureIntegration()->getData();
        $integrationData[Integration::SETUP_TYPE] = Integration::TYPE_CONFIG;
        $this->registry->register(IntegrationController::REGISTRY_KEY_CURRENT_INTEGRATION, $integrationData);
        $this->assertTrue($this->createApiTabBlock()->canShowTab());
    }

    /**
     * @return \Magento\Integration\Block\Adminhtml\Integration\Activate\Permissions\Tab\Webapi
     */
    protected function createApiTabBlock()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        return $objectManager->create(
            \Magento\Integration\Block\Adminhtml\Integration\Activate\Permissions\Tab\Webapi::class
        );
    }

    /**
     * @return Integration
     */
    protected function getFixtureIntegration()
    {
        /** @var $integration Integration */
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $integration = $objectManager->create(\Magento\Integration\Model\Integration::class);
        return $integration->load('Fixture Integration', 'name');
    }
}
