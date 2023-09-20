<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Integration\Test\Unit\Model\Plugin;

use Magento\Integration\Model\Integration;

/**
 * Unit test for \Magento\Integration\Model\Plugin\Integration
 */
class IntegrationTest extends \PHPUnit\Framework\TestCase
{
    /**
     * API setup plugin
     *
     * @var \Magento\Integration\Model\Plugin\Integration
     */
    protected $integrationPlugin;

    /**
     * @var \Magento\Integration\Api\IntegrationServiceInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $subjectMock;

    /**
     * @var  \Magento\Authorization\Model\Acl\AclRetriever|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $aclRetrieverMock;

    /**
     * @var \Magento\Integration\Api\AuthorizationServiceInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $integrationAuthServiceMock;

    /**
     * @var \Magento\Integration\Model\IntegrationConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $integrationConfigMock;

    /**
     * @var \Magento\Integration\Model\ConsolidatedConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $consolidatedConfigMock;

    protected function setUp(): void
    {
        $this->subjectMock = $this->createMock(\Magento\Integration\Model\IntegrationService::class);
        $this->integrationAuthServiceMock = $this->createPartialMock(
            \Magento\Integration\Api\AuthorizationServiceInterface::class,
            ['removePermissions', 'grantAllPermissions', 'grantPermissions']
        );
        $this->aclRetrieverMock = $this->createPartialMock(
            \Magento\Authorization\Model\Acl\AclRetriever::class,
            ['getAllowedResourcesByUser']
        );
        $this->integrationConfigMock = $this->getMockBuilder(\Magento\Integration\Model\IntegrationConfig::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();
        $this->consolidatedConfigMock = $this->getMockBuilder(\Magento\Integration\Model\ConsolidatedConfig::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->integrationPlugin = $objectManagerHelper->getObject(
            \Magento\Integration\Model\Plugin\Integration::class,
            [
                'integrationAuthorizationService' => $this->integrationAuthServiceMock,
                'aclRetriever' => $this->aclRetrieverMock,
                'integrationConfig' => $this->integrationConfigMock,
                'consolidatedConfig' => $this->consolidatedConfigMock
            ]
        );
    }

    public function testAfterDelete()
    {
        $integrationId = 1;
        $integrationsData = [
            Integration::ID => $integrationId,
            Integration::NAME => 'TestIntegration1',
            Integration::EMAIL => 'test-integration1@magento.com',
            Integration::ENDPOINT => 'http://endpoint.com',
            Integration::SETUP_TYPE => 1,
        ];

        $this->integrationAuthServiceMock->expects($this->once())
            ->method('removePermissions')
            ->with($integrationId);
        $this->integrationPlugin->afterDelete($this->subjectMock, $integrationsData);
    }

    public function testAfterCreateAllResources()
    {
        $integrationId = 1;
        $integrationModelMock = $this->getMockBuilder(\Magento\Integration\Model\Integration::class)
            ->disableOriginalConstructor()
            ->getMock();
        $integrationModelMock->expects($this->exactly(2))
            ->method('getId')
            ->willReturn($integrationId);
        $integrationModelMock->expects($this->once())
            ->method('getData')
            ->with('all_resources')
            ->willReturn(1);

        $this->integrationAuthServiceMock->expects($this->once())
            ->method('grantAllPermissions')
            ->with($integrationId);

        $this->integrationPlugin->afterCreate($this->subjectMock, $integrationModelMock);
    }

    public function testAfterCreateSomeResources()
    {
        $integrationId = 1;
        $integrationModelMock = $this->getMockBuilder(\Magento\Integration\Model\Integration::class)
            ->disableOriginalConstructor()
            ->getMock();
        $integrationModelMock->expects($this->exactly(2))
            ->method('getId')
            ->willReturn($integrationId);
        $integrationModelMock->expects($this->at(2))
            ->method('getData')
            ->with('all_resources')
            ->willReturn(null);
        $integrationModelMock->expects($this->at(3))
            ->method('getData')
            ->with('resource')
            ->willReturn(['testResource']);
        $integrationModelMock->expects($this->at(5))
            ->method('getData')
            ->with('resource')
            ->willReturn(['testResource']);

        $this->integrationAuthServiceMock->expects($this->once())
            ->method('grantPermissions')
            ->with($integrationId, ['testResource']);

        $this->integrationPlugin->afterCreate($this->subjectMock, $integrationModelMock);
    }

    public function testAfterCreateNoResource()
    {
        $integrationId = 1;
        $integrationModelMock = $this->getMockBuilder(\Magento\Integration\Model\Integration::class)
            ->disableOriginalConstructor()
            ->getMock();
        $integrationModelMock->expects($this->exactly(2))
            ->method('getId')
            ->willReturn($integrationId);
        $integrationModelMock->expects($this->at(2))
            ->method('getData')
            ->with('all_resources')
            ->willReturn(null);
        $integrationModelMock->expects($this->at(3))
            ->method('getData')
            ->with('resource')
            ->willReturn(null);

        $this->integrationAuthServiceMock->expects($this->once())
            ->method('grantPermissions')
            ->with($integrationId, []);

        $this->integrationPlugin->afterCreate($this->subjectMock, $integrationModelMock);
    }

    public function testAfterUpdateAllResources()
    {
        $integrationId = 1;
        $integrationModelMock = $this->getMockBuilder(\Magento\Integration\Model\Integration::class)
            ->disableOriginalConstructor()
            ->getMock();
        $integrationModelMock->expects($this->exactly(2))
            ->method('getId')
            ->willReturn($integrationId);
        $integrationModelMock->expects($this->once())
            ->method('getData')
            ->with('all_resources')
            ->willReturn(1);

        $this->integrationAuthServiceMock->expects($this->once())
            ->method('grantAllPermissions')
            ->with($integrationId);

        $this->integrationPlugin->afterUpdate($this->subjectMock, $integrationModelMock);
    }

    public function testAfterGet()
    {
        $integrationId = 1;
        $integrationModelMock = $this->getMockBuilder(\Magento\Integration\Model\Integration::class)
            ->disableOriginalConstructor()
            ->getMock();
        $integrationModelMock->expects($this->exactly(2))
            ->method('getId')
            ->willReturn($integrationId);
        $integrationModelMock->expects($this->once())
            ->method('setData')
            ->with('resource', ['testResource']);
        $deprecatedIntegrationsData = [
            Integration::ID => $integrationId,
            Integration::NAME => 'TestIntegration1',
            Integration::EMAIL => 'test-integration1@magento.com',
            Integration::ENDPOINT => 'http://endpoint.com',
            Integration::SETUP_TYPE => 1,
            'resource' => ['testResource']
        ];
        $consolidatedIntegrationsData = [
            Integration::ID => 2,
            Integration::NAME => 'TestIntegration2',
            Integration::EMAIL => 'test-integration2@magento.com',
            Integration::ENDPOINT => 'http://endpoint2.com',
            Integration::SETUP_TYPE => 1,
            'resource' => ['testResource']
        ];
        $this->integrationConfigMock->method('getIntegrations')->willReturn($deprecatedIntegrationsData);
        $this->consolidatedConfigMock->method('getIntegrations')->willReturn($consolidatedIntegrationsData);

        $this->aclRetrieverMock->expects($this->once())
            ->method('getAllowedResourcesByUser')
            ->with(\Magento\Authorization\Model\UserContextInterface::USER_TYPE_INTEGRATION, $integrationId)
            ->willReturn(['testResource']);

        $this->integrationPlugin->afterGet($this->subjectMock, $integrationModelMock);
    }
}
