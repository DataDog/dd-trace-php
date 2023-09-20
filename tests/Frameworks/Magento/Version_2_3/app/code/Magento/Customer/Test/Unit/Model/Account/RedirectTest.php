<?php
/**
 * Unit test for Magento\Customer\Test\Unit\Model\Account\Redirect
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Customer\Test\Unit\Model\Account;

use Magento\Customer\Model\Account\Redirect;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Url\HostChecker;
use Magento\Store\Model\ScopeInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RedirectTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Redirect
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Store\Model\Store
     */
    protected $store;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\UrlInterface
     */
    protected $url;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Url\DecoderInterface
     */
    protected $urlDecoder;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Customer\Model\Url
     */
    protected $customerUrl;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Controller\Result\Redirect
     */
    protected $resultRedirect;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Controller\Result\Forward
     */
    protected $resultForward;

    /**
     * @var ResultFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultFactory;

    /**
     * @var HostChecker | \PHPUnit\Framework\MockObject\MockObject
     */
    private $hostChecker;

    protected function setUp(): void
    {
        $this->request = $this->getMockForAbstractClass(\Magento\Framework\App\RequestInterface::class);

        $this->customerSession = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getLastCustomerId',
                'isLoggedIn',
                'getId',
                'setLastCustomerId',
                'unsBeforeAuthUrl',
                'getBeforeAuthUrl',
                'setBeforeAuthUrl',
                'getAfterAuthUrl',
                'setAfterAuthUrl',
                'getBeforeRequestParams',
                'getBeforeModuleName',
                'getBeforeControllerName',
                'getBeforeAction',
            ])
            ->getMock();

        $this->scopeConfig = $this->getMockForAbstractClass(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        $this->store = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->storeManager = $this->getMockForAbstractClass(\Magento\Store\Model\StoreManagerInterface::class);
        $this->storeManager->expects($this->once())
            ->method('getStore')
            ->willReturn($this->store);

        $this->url = $this->getMockForAbstractClass(\Magento\Framework\UrlInterface::class);
        $this->urlDecoder = $this->getMockForAbstractClass(\Magento\Framework\Url\DecoderInterface::class);

        $this->customerUrl = $this->getMockBuilder(\Magento\Customer\Model\Url::class)
            ->setMethods(['DashboardUrl', 'getAccountUrl', 'getLoginUrl', 'getLogoutUrl', 'getDashboardUrl'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->resultRedirect = $this->getMockBuilder(\Magento\Framework\Controller\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resultForward = $this->getMockBuilder(\Magento\Framework\Controller\Result\Forward::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resultFactory = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->hostChecker = $this->getMockBuilder(HostChecker::class)
            ->disableOriginalConstructor()
            ->getMock();

        $objectManager = new ObjectManager($this);
        $this->model = $objectManager->getObject(
            \Magento\Customer\Model\Account\Redirect::class,
            [
                'request' => $this->request,
                'customerSession' => $this->customerSession,
                'scopeConfig' => $this->scopeConfig,
                'storeManager' => $this->storeManager,
                'url' => $this->url,
                'urlDecoder' => $this->urlDecoder,
                'customerUrl' => $this->customerUrl,
                'resultFactory' => $this->resultFactory,
                'hostChecker' => $this->hostChecker,
            ]
        );
    }

    /**
     * @param int $customerId
     * @param int $lastCustomerId
     * @param string $referer
     * @param string $baseUrl
     * @param string $beforeAuthUrl
     * @param string $afterAuthUrl
     * @param string $accountUrl
     * @param string $loginUrl
     * @param string $logoutUrl
     * @param string $dashboardUrl
     * @param bool $customerLoggedIn
     * @param bool $redirectToDashboard
     * @dataProvider getRedirectDataProvider
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function testGetRedirect(
        $customerId,
        $lastCustomerId,
        $referer,
        $baseUrl,
        $beforeAuthUrl,
        $afterAuthUrl,
        $accountUrl,
        $loginUrl,
        $logoutUrl,
        $dashboardUrl,
        $customerLoggedIn,
        $redirectToDashboard
    ) {
        // Preparations for method updateLastCustomerId()
        $this->customerSession->expects($this->once())->method('getLastCustomerId')->willReturn($customerId);
        $this->customerSession->expects($this->any())->method('isLoggedIn')->willReturn($customerLoggedIn);
        $this->customerSession->expects($this->any())->method('getId')->willReturn($lastCustomerId);
        $this->customerSession->expects($this->any())->method('unsBeforeAuthUrl')->willReturnSelf();
        $this->customerSession->expects($this->any())
            ->method('setLastCustomerId')
            ->with($lastCustomerId)
            ->willReturnSelf();

        // Preparations for method prepareRedirectUrl()
        $this->store->expects($this->once())->method('getBaseUrl')->willReturn($baseUrl);

        $this->customerSession->expects($this->any())->method('getBeforeAuthUrl')->willReturn($beforeAuthUrl);
        $this->customerSession->expects($this->any())->method('setBeforeAuthUrl')->willReturnSelf();
        $this->customerSession->expects($this->any())->method('getAfterAuthUrl')->willReturn($afterAuthUrl);
        $this->customerSession->expects($this->any())
            ->method('setAfterAuthUrl')
            ->with($beforeAuthUrl)
            ->willReturnSelf();
        $this->customerSession->expects($this->any())->method('getBeforeRequestParams')->willReturn(false);

        $this->customerUrl->expects($this->any())->method('getAccountUrl')->willReturn($accountUrl);
        $this->customerUrl->expects($this->any())->method('getLoginUrl')->willReturn($loginUrl);
        $this->customerUrl->expects($this->any())->method('getLogoutUrl')->willReturn($logoutUrl);
        $this->customerUrl->expects($this->any())->method('getDashboardUrl')->willReturn($dashboardUrl);

        $this->scopeConfig->expects($this->any())
            ->method('isSetFlag')
            ->with(CustomerUrl::XML_PATH_CUSTOMER_STARTUP_REDIRECT_TO_DASHBOARD, ScopeInterface::SCOPE_STORE)
            ->willReturn($redirectToDashboard);

        $this->request->expects($this->any())
            ->method('getParam')
            ->with(CustomerUrl::REFERER_QUERY_PARAM_NAME)
            ->willReturn($referer);

        $this->urlDecoder->expects($this->any())->method('decode')->with($referer)->willReturn($referer);

        $this->url->expects($this->any())->method('isOwnOriginUrl')->willReturn(true);

        $this->resultRedirect->expects($this->once())->method('setUrl')->with($beforeAuthUrl)->willReturnSelf();

        $this->resultFactory->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_REDIRECT)
            ->willReturn($this->resultRedirect);

        $this->hostChecker->expects($this->any())->method('isOwnOrigin')->willReturn(true);

        $this->model->getRedirect();
    }

    /**
     * @return array
     */
    public function getRedirectDataProvider()
    {
        /**
         * Customer ID
         * Last customer ID
         * Referer
         * Base URL
         * BeforeAuth URL
         * AfterAuth URL
         * Account URL
         * Login URL
         * Logout URL
         * Dashboard URL
         * Is customer logged in flag
         * Redirect to Dashboard flag
         */
        return [
            // Logged In, Redirect by Referer
            [1, 2, 'referer', 'base', '', '', 'account', '', '', '', true, false],
            [1, 2, 'http://referer.com/', 'http://base.com/', '', '', 'account', '', '', 'dashboard', true, false],
            // Logged In, Redirect by AfterAuthUrl
            [1, 2, 'referer', 'base', '', 'defined', 'account', '', '', '', true, true],
            // Not logged In, Redirect by LoginUrl
            [1, 2, 'referer', 'base', '', '', 'account', 'login', '', '', false, true],
            // Logout, Redirect to Dashboard
            [1, 2, 'referer', 'base', 'logout', '', 'account', 'login', 'logout', 'dashboard', false, true],
            // Default redirect
            [1, 2, 'referer', 'base', 'defined', '', 'account', 'login', 'logout', 'dashboard', true, true],
            // Logout, Without Redirect to Dashboard
            [
                'customer_id' => 1,
                'last_customer_id' => 2,
                'referer' => 'http://base.com/customer/account/logoutSuccess/',
                'base_url' => 'http://base.com/',
                'before_auth_url' => 'http://base.com/',
                'after_auth_url' => 'http://base.com/customer/account/',
                'account_url' => 'account',
                'login_url' => 'login',
                'logout_url' => 'logout',
                'dashboard_url' => 'dashboard',
                'is_customer_logged_id_flag' => true,
                'redirect_to_dashboard_flag' => false,
            ],
        ];
    }

    public function testBeforeRequestParams()
    {
        $requestParams = [
            'param1' => 'value1',
        ];

        $module = 'module';
        $controller = 'controller';
        $action = 'action';

        $this->customerSession->expects($this->exactly(2))
            ->method('getBeforeRequestParams')
            ->willReturn($requestParams);
        $this->customerSession->expects($this->once())
            ->method('getBeforeModuleName')
            ->willReturn($module);
        $this->customerSession->expects($this->once())
            ->method('getBeforeControllerName')
            ->willReturn($controller);
        $this->customerSession->expects($this->once())
            ->method('getBeforeAction')
            ->willReturn($action);

        $this->resultForward->expects($this->once())
            ->method('setParams')
            ->with($requestParams)
            ->willReturnSelf();
        $this->resultForward->expects($this->once())
            ->method('setModule')
            ->with($module)
            ->willReturnSelf();
        $this->resultForward->expects($this->once())
            ->method('setController')
            ->with($controller)
            ->willReturnSelf();
        $this->resultForward->expects($this->once())
            ->method('forward')
            ->with($action)
            ->willReturnSelf();

        $this->resultFactory->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_FORWARD)
            ->willReturn($this->resultForward);

        $result = $this->model->getRedirect();
        $this->assertSame($this->resultForward, $result);
    }
}
