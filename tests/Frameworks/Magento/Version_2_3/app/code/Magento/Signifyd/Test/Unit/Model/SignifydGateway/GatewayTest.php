<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Test\Unit\Model\SignifydGateway;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Signifyd\Api\CaseRepositoryInterface;
use Magento\Signifyd\Api\Data\CaseInterface;
use PHPUnit\Framework\MockObject\MockObject as MockObject;
use Magento\Signifyd\Model\SignifydGateway\Gateway;
use Magento\Signifyd\Model\SignifydGateway\GatewayException;
use Magento\Signifyd\Model\SignifydGateway\Request\CreateCaseBuilderInterface;
use Magento\Signifyd\Model\SignifydGateway\ApiClient;
use Magento\Signifyd\Model\SignifydGateway\ApiCallException;

class GatewayTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CreateCaseBuilderInterface|MockObject
     */
    private $createCaseBuilder;

    /**
     * @var ApiClient|MockObject
     */
    private $apiClient;

    /**
     * @var Gateway
     */
    private $gateway;

    /**
     * @var OrderRepositoryInterface|MockObject
     */
    private $orderRepository;

    /**
     * @var CaseRepositoryInterface|MockObject
     */
    private $caseRepository;

    protected function setUp(): void
    {
        $this->createCaseBuilder = $this->getMockBuilder(CreateCaseBuilderInterface::class)
            ->getMockForAbstractClass();

        $this->apiClient = $this->getMockBuilder(ApiClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->orderRepository = $this->getMockBuilder(OrderRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->caseRepository= $this->getMockBuilder(CaseRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->gateway = new Gateway(
            $this->createCaseBuilder,
            $this->apiClient,
            $this->orderRepository,
            $this->caseRepository
        );
    }

    public function testCreateCaseForSpecifiedOrder()
    {
        $dummyOrderId = 1;
        $dummyStoreId = 2;
        $dummySignifydInvestigationId = 42;

        $this->withOrderEntity($dummyOrderId, $dummyStoreId);
        $this->apiClient
            ->method('makeApiCall')
            ->willReturn([
                'investigationId' => $dummySignifydInvestigationId
            ]);

        $this->createCaseBuilder
            ->expects($this->atLeastOnce())
            ->method('build')
            ->with($this->equalTo($dummyOrderId))
            ->willReturn([]);

        $result = $this->gateway->createCase($dummyOrderId);
        $this->assertEquals(42, $result);
    }

    public function testCreateCaseCallsValidApiMethod()
    {
        $dummyOrderId = 1;
        $dummyStoreId = 2;
        $dummySignifydInvestigationId = 42;

        $this->withOrderEntity($dummyOrderId, $dummyStoreId);
        $this->createCaseBuilder
            ->method('build')
            ->willReturn([]);

        $this->apiClient
            ->expects($this->atLeastOnce())
            ->method('makeApiCall')
            ->with(
                $this->equalTo('/cases'),
                $this->equalTo('POST'),
                $this->isType('array'),
                $this->equalTo($dummyStoreId)
            )
            ->willReturn([
                'investigationId' => $dummySignifydInvestigationId
            ]);

        $result = $this->gateway->createCase($dummyOrderId);
        $this->assertEquals(42, $result);
    }

    public function testCreateCaseNormalFlow()
    {
        $dummyOrderId = 1;
        $dummyStoreId = 2;
        $dummySignifydInvestigationId = 42;

        $this->withOrderEntity($dummyOrderId, $dummyStoreId);
        $this->createCaseBuilder
            ->method('build')
            ->willReturn([]);
        $this->apiClient
            ->method('makeApiCall')
            ->willReturn([
                'investigationId' => $dummySignifydInvestigationId
            ]);

        $returnedInvestigationId = $this->gateway->createCase($dummyOrderId);
        $this->assertEquals(
            $dummySignifydInvestigationId,
            $returnedInvestigationId,
            'Method must return value specified in "investigationId" response parameter'
        );
    }

    public function testCreateCaseWithFailedApiCall()
    {
        $dummyOrderId = 1;
        $dummyStoreId = 2;
        $apiCallFailureMessage = 'Api call failed';

        $this->withOrderEntity($dummyOrderId, $dummyStoreId);
        $this->createCaseBuilder
            ->method('build')
            ->willReturn([]);
        $this->apiClient
            ->method('makeApiCall')
            ->willThrowException(new ApiCallException($apiCallFailureMessage));

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage($apiCallFailureMessage);
        $this->gateway->createCase($dummyOrderId);
    }

    public function testCreateCaseWithMissedResponseRequiredData()
    {
        $dummyOrderId = 1;
        $dummyStoreId = 2;

        $this->withOrderEntity($dummyOrderId, $dummyStoreId);
        $this->createCaseBuilder
            ->method('build')
            ->willReturn([]);
        $this->apiClient
            ->method('makeApiCall')
            ->willReturn([
                'someOtherParameter' => 'foo',
            ]);

        $this->expectException(GatewayException::class);
        $this->gateway->createCase($dummyOrderId);
    }

    public function testCreateCaseWithAdditionalResponseData()
    {
        $dummyOrderId = 1;
        $dummyStoreId = 2;
        $dummySignifydInvestigationId = 42;

        $this->withOrderEntity($dummyOrderId, $dummyStoreId);
        $this->createCaseBuilder
            ->method('build')
            ->willReturn([]);
        $this->apiClient
            ->method('makeApiCall')
            ->willReturn([
                'investigationId' => $dummySignifydInvestigationId,
                'someOtherParameter' => 'foo',
            ]);

        $returnedInvestigationId = $this->gateway->createCase($dummyOrderId);
        $this->assertEquals(
            $dummySignifydInvestigationId,
            $returnedInvestigationId,
            'Method must return value specified in "investigationId" response parameter and ignore any other parameters'
        );
    }

    public function testSubmitCaseForGuaranteeCallsValidApiMethod()
    {
        $dummySygnifydCaseId = 42;
        $dummyStoreId = 1;
        $dummyDisposition = 'APPROVED';

        $this->withCaseEntity($dummySygnifydCaseId, $dummyStoreId);
        $this->apiClient
            ->expects($this->atLeastOnce())
            ->method('makeApiCall')
            ->with(
                $this->equalTo('/guarantees'),
                $this->equalTo('POST'),
                $this->equalTo([
                    'caseId' => $dummySygnifydCaseId
                ]),
                $this->equalTo($dummyStoreId)
            )->willReturn([
                'disposition' => $dummyDisposition
            ]);

        $result = $this->gateway->submitCaseForGuarantee($dummySygnifydCaseId);
        $this->assertEquals('APPROVED', $result);
    }

    public function testSubmitCaseForGuaranteeWithFailedApiCall()
    {
        $dummySygnifydCaseId = 42;
        $dummyStoreId = 1;
        $apiCallFailureMessage = 'Api call failed';

        $this->withCaseEntity($dummySygnifydCaseId, $dummyStoreId);
        $this->apiClient
            ->method('makeApiCall')
            ->willThrowException(new ApiCallException($apiCallFailureMessage));

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage($apiCallFailureMessage);
        $result = $this->gateway->submitCaseForGuarantee($dummySygnifydCaseId);
        $this->assertEquals('Api call failed', $result);
    }

    public function testSubmitCaseForGuaranteeReturnsDisposition()
    {
        $dummySygnifydCaseId = 42;
        $dummyStoreId = 1;
        $dummyDisposition = 'APPROVED';
        $dummyGuaranteeId = 123;
        $dummyRereviewCount = 0;

        $this->withCaseEntity($dummySygnifydCaseId, $dummyStoreId);
        $this->apiClient
            ->method('makeApiCall')
            ->willReturn([
                'guaranteeId' => $dummyGuaranteeId,
                'disposition' => $dummyDisposition,
                'rereviewCount' => $dummyRereviewCount,
            ]);

        $actualDisposition = $this->gateway->submitCaseForGuarantee($dummySygnifydCaseId);
        $this->assertEquals(
            $dummyDisposition,
            $actualDisposition,
            'Method must return guarantee disposition retrieved in Signifyd API response as a result'
        );
    }

    public function testSubmitCaseForGuaranteeWithMissedDisposition()
    {
        $dummySygnifydCaseId = 42;
        $dummyStoreId = 1;
        $dummyGuaranteeId = 123;
        $dummyRereviewCount = 0;

        $this->withCaseEntity($dummySygnifydCaseId, $dummyStoreId);
        $this->apiClient
            ->method('makeApiCall')
            ->willReturn([
                'guaranteeId' => $dummyGuaranteeId,
                'rereviewCount' => $dummyRereviewCount,
            ]);

        $this->expectException(GatewayException::class);
        $this->gateway->submitCaseForGuarantee($dummySygnifydCaseId);
    }

    public function testSubmitCaseForGuaranteeWithUnexpectedDisposition()
    {
        $dummySygnifydCaseId = 42;
        $dummyStoreId = 1;
        $dummyUnexpectedDisposition = 'UNEXPECTED';

        $this->withCaseEntity($dummySygnifydCaseId, $dummyStoreId);
        $this->apiClient
            ->method('makeApiCall')
            ->willReturn([
                'disposition' => $dummyUnexpectedDisposition,
            ]);

        $this->expectException(GatewayException::class);
        $result = $this->gateway->submitCaseForGuarantee($dummySygnifydCaseId);
        $this->assertEquals('UNEXPECTED', $result);
    }

    /**
     * @dataProvider supportedGuaranteeDispositionsProvider
     */
    public function testSubmitCaseForGuaranteeWithExpectedDisposition($dummyExpectedDisposition)
    {
        $dummySygnifydCaseId = 42;
        $dummyStoreId = 1;

        $this->withCaseEntity($dummySygnifydCaseId, $dummyStoreId);
        $this->apiClient
            ->method('makeApiCall')
            ->willReturn([
                'disposition' => $dummyExpectedDisposition,
            ]);

        try {
            $result = $this->gateway->submitCaseForGuarantee($dummySygnifydCaseId);
            $this->assertEquals($dummyExpectedDisposition, $result);
        } catch (GatewayException $e) {
            $this->fail(sprintf(
                'Expected disposition "%s" was not accepted with message "%s"',
                $dummyExpectedDisposition,
                $e->getMessage()
            ));
        }
    }

    /**
     * Checks a test case when guarantee for a case is successfully canceled
     *
     * @covers \Magento\Signifyd\Model\SignifydGateway\Gateway::cancelGuarantee
     */
    public function testCancelGuarantee()
    {
        $caseId = 123;
        $dummyStoreId = 1;

        $this->withCaseEntity($caseId, $dummyStoreId);
        $this->apiClient->expects(self::once())
            ->method('makeApiCall')
            ->with(
                '/cases/' . $caseId . '/guarantee',
                'PUT',
                ['guaranteeDisposition' => Gateway::GUARANTEE_CANCELED],
                $dummyStoreId
            )
            ->willReturn(
                ['disposition' => Gateway::GUARANTEE_CANCELED]
            );

        $result = $this->gateway->cancelGuarantee($caseId);
        self::assertEquals(Gateway::GUARANTEE_CANCELED, $result);
    }

    /**
     * Checks a case when API request returns unexpected guarantee disposition.
     *
     * @covers \Magento\Signifyd\Model\SignifydGateway\Gateway::cancelGuarantee
     */
    public function testCancelGuaranteeWithUnexpectedDisposition()
    {
        $this->expectException(\Magento\Signifyd\Model\SignifydGateway\GatewayException::class);
        $this->expectExceptionMessage('API returned unexpected disposition: DECLINED.');

        $caseId = 123;
        $dummyStoreId = 1;

        $this->withCaseEntity($caseId, $dummyStoreId);
        $this->apiClient->expects(self::once())
            ->method('makeApiCall')
            ->with(
                '/cases/' . $caseId . '/guarantee',
                'PUT',
                ['guaranteeDisposition' => Gateway::GUARANTEE_CANCELED],
                $dummyStoreId
            )
            ->willReturn(['disposition' => Gateway::GUARANTEE_DECLINED]);

        $result = $this->gateway->cancelGuarantee($caseId);
        $this->assertEquals(Gateway::GUARANTEE_CANCELED, $result);
    }

    /**
     * @return array
     */
    public function supportedGuaranteeDispositionsProvider()
    {
        return [
            'APPROVED' => ['APPROVED'],
            'DECLINED' => ['DECLINED'],
            'PENDING' => ['PENDING'],
            'CANCELED' => ['CANCELED'],
            'IN_REVIEW' => ['IN_REVIEW'],
            'UNREQUESTED' => ['UNREQUESTED'],
        ];
    }

    /**
     * Specifies order entity mock execution.
     *
     * @param int $orderId
     * @param int $storeId
     * @return void
     */
    private function withOrderEntity(int $orderId, int $storeId): void
    {
        $orderEntity = $this->getMockBuilder(OrderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $orderEntity->method('getStoreId')
            ->willReturn($storeId);
        $this->orderRepository->method('get')
            ->with($orderId)
            ->willReturn($orderEntity);
    }

    /**
     * Specifies case entity mock execution.
     *
     * @param int $caseId
     * @param int $storeId
     * @return void
     */
    private function withCaseEntity(int $caseId, int $storeId): void
    {
        $orderId = 1;

        $caseEntity = $this->getMockBuilder(CaseInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $caseEntity->method('getOrderId')
            ->willReturn($orderId);
        $this->caseRepository->method('getByCaseId')
            ->with($caseId)
            ->willReturn($caseEntity);

        $this->withOrderEntity($orderId, $storeId);
    }
}
