<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Controller\Adminhtml\Order;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Backend\Model\Session\Quote as SessionQuote;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CreateTest extends \Magento\TestFramework\TestCase\AbstractBackendController
{
    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @inheritDoc
     *
     * @throws \Magento\Framework\Exception\AuthenticationException
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->productRepository = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
    }

    /**
     * Test LoadBlock being dispatched.
     */
    public function testLoadBlockAction()
    {
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setParam('block', ',');
        $this->getRequest()->setParam('json', 1);
        $this->dispatch('backend/sales/order_create/loadBlock');
        $this->assertStringContainsString('"message":""}', $this->getResponse()->getBody());
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testLoadBlockActionData()
    {
        $product = $this->productRepository->get('simple');
        $this->_objectManager->get(
            \Magento\Sales\Model\AdminOrder\Create::class
        )->addProducts(
            [$product->getId() => ['qty' => 1]]
        );
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setParam('block', 'data');
        $this->getRequest()->setParam('json', 1);
        $this->dispatch('backend/sales/order_create/loadBlock');
        $html = $this->getResponse()->getBody();
        $this->assertStringContainsString('<div id=\"sales_order_create_search_grid\"', $html);
        $this->assertStringContainsString('<div id=\"order-billing_method_form\"', $html);
        $this->assertStringContainsString('id=\"shipping-method-overlay\"', $html);
        $this->assertStringContainsString('id=\"coupons:code\"', $html);
    }

    /**
     * Tests that shipping method 'Table rates' shows rates according to selected website.
     *
     * @magentoAppArea adminhtml
     * @magentoDataFixture Magento/Quote/Fixtures/quote_sec_website.php
     * @magentoDataFixture Magento/OfflineShipping/_files/tablerates_second_website.php
     * @magentoDbIsolation disabled
     */
    public function testLoadBlockShippingMethod()
    {
        $store = $this->getStore('fixture_second_store');

        /** @var MutableScopeConfigInterface $mutableScopeConfig */
        $mutableScopeConfig = $this->_objectManager->get(MutableScopeConfigInterface::class);
        $mutableScopeConfig->setValue(
            'carriers/tablerate/active',
            1,
            ScopeInterface::SCOPE_STORE,
            $store->getCode()
        );
        $mutableScopeConfig->setValue(
            'carriers/tablerate/condition_name',
            'package_qty',
            ScopeInterface::SCOPE_STORE,
            $store->getCode()
        );

        $website = $this->getWebsite('test');
        $customer = $this->getCustomer('customer.web@example.com', (int)$website->getId());
        $quote = $this->getQuoteById('0000032134');
        $session = $this->_objectManager->get(SessionQuote::class);
        $session->setQuoteId($quote->getId());

        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue(
            [
                'customer_id' => $customer->getId(),
                'collect_shipping_rates' => 1,
                'store_id' => $store->getId(),
                'json' => true
            ]
        );
        $this->dispatch('backend/sales/order_create/loadBlock/block/shipping_method');
        $body = $this->getResponse()->getBody();
        $expectedTableRatePrice = '<span class=\"price\">$20.00<\/span>';

        $this->assertStringContainsString($expectedTableRatePrice, $body, '');
    }

    /**
     * Tests LoadBlock actions.
     *
     * @param string $block Block name.
     * @param string $expected Contains HTML.
     *
     * @dataProvider loadBlockActionsDataProvider
     */
    public function testLoadBlockActions($block, $expected)
    {
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setParam('block', $block);
        $this->getRequest()->setParam('json', 1);
        $this->dispatch('backend/sales/order_create/loadBlock');
        $html = $this->getResponse()->getBody();
        $this->assertStringContainsString($expected, $html);
    }

    /**
     * @return array
     */
    public function loadBlockActionsDataProvider()
    {
        return [
            'shipping_method' => ['shipping_method', 'id=\"shipping-method-overlay\"'],
            'billing_method' => ['billing_method', '<div id=\"order-billing_method_form\">'],
            'newsletter' => ['newsletter', 'name=\"newsletter:subscribe\"'],
            'search' => ['search', '<div id=\"sales_order_create_search_grid\"'],
            'search_grid' => ['search', '<div id=\"sales_order_create_search_grid\"']
        ];
    }

    /**
     * Tests action items.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testLoadBlockActionItems()
    {
        $product = $this->productRepository->get('simple');
        $this->_objectManager->get(
            \Magento\Sales\Model\AdminOrder\Create::class
        )->addProducts(
            [$product->getId() => ['qty' => 1]]
        );
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setParam('block', 'items');
        $this->getRequest()->setParam('json', 1);
        $this->dispatch('backend/sales/order_create/loadBlock');
        $html = $this->getResponse()->getBody();
        $this->assertStringContainsString('id=\"coupons:code\"', $html);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoAppArea adminhtml
     */
    public function testIndexAction()
    {
        $product = $this->productRepository->get('simple');
        /** @var $order \Magento\Sales\Model\AdminOrder\Create */
        $order = $this->_objectManager->get(\Magento\Sales\Model\AdminOrder\Create::class);
        $order->addProducts([$product->getId() => ['qty' => 1]]);
        $this->dispatch('backend/sales/order_create/index');
        $html = $this->getResponse()->getBody();

        $this->assertGreaterThanOrEqual(
            1,
            \Magento\TestFramework\Helper\Xpath::getElementsCountForXpath(
                '//div[@id="order-customer-selector"]',
                $html
            )
        );
        $this->assertGreaterThanOrEqual(
            1,
            \Magento\TestFramework\Helper\Xpath::getElementsCountForXpath(
                '//*[@data-grid-id="sales_order_create_customer_grid"]',
                $html
            )
        );
        $this->assertGreaterThanOrEqual(
            1,
            \Magento\TestFramework\Helper\Xpath::getElementsCountForXpath(
                '//div[@id="order-billing_method_form"]',
                $html
            )
        );
        $this->assertGreaterThanOrEqual(
            1,
            \Magento\TestFramework\Helper\Xpath::getElementsCountForXpath(
                '//*[@id="shipping-method-overlay"]',
                $html
            )
        );
        $this->assertGreaterThanOrEqual(
            1,
            \Magento\TestFramework\Helper\Xpath::getElementsCountForXpath(
                '//div[@id="sales_order_create_search_grid"]',
                $html
            )
        );

        $this->assertGreaterThanOrEqual(
            1,
            \Magento\TestFramework\Helper\Xpath::getElementsCountForXpath('//*[@id="coupons:code"]', $html)
        );
    }

    /**
     * Tests ACL.
     *
     * @param string $actionName
     * @param boolean $reordered
     * @param string $expectedResult
     *
     * @dataProvider getAclResourceDataProvider
     * @magentoAppIsolation enabled
     */
    public function testGetAclResource($actionName, $reordered, $expectedResult)
    {
        $this->_objectManager->get(SessionQuote::class)->setReordered($reordered);
        $orderController = $this->_objectManager->get(
            \Magento\Sales\Controller\Adminhtml\Order\Stub\OrderCreateStub::class
        );

        $this->getRequest()->setActionName($actionName);

        $method = new \ReflectionMethod(\Magento\Sales\Controller\Adminhtml\Order\Create::class, '_getAclResource');
        $method->setAccessible(true);
        $result = $method->invoke($orderController);
        $this->assertEquals($result, $expectedResult);
    }

    /**
     * @return array
     */
    public function getAclResourceDataProvider()
    {
        return [
            ['index', false, 'Magento_Sales::create'],
            ['index', true, 'Magento_Sales::reorder'],
            ['save', false, 'Magento_Sales::create'],
            ['save', true, 'Magento_Sales::reorder'],
            ['reorder', false, 'Magento_Sales::reorder'],
            ['reorder', true, 'Magento_Sales::reorder'],
            ['cancel', false, 'Magento_Sales::cancel'],
            ['cancel', true, 'Magento_Sales::reorder'],
            ['', false, 'Magento_Sales::actions'],
            ['', true, 'Magento_Sales::actions']
        ];
    }

    /**
     * @magentoDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     * @magentoAppArea adminhtml
     */
    public function testConfigureProductToAddAction()
    {
        $product = $this->productRepository->get('configurable');
        $this->getRequest()->setParam('id', $product->getEntityId())
            ->setParam('isAjax', true);

        $this->dispatch('backend/sales/order_create/configureProductToAdd');

        $body = $this->getResponse()->getBody();

        $this->assertNotEmpty($body);
        $this->assertStringContainsString('><span>Quantity</span></label>', $body);
        $this->assertStringContainsString('>Test Configurable</label>', $body);
        $this->assertStringContainsString('"code":"test_configurable","label":"Test Configurable"', $body);
        $this->assertStringContainsString(sprintf('"productId":"%s"', $product->getEntityId()), $body);
    }

    /**
     * Test not allowing to save.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function testDeniedSaveAction()
    {
        $this->_objectManager->configure(
            [\Magento\Backend\App\Action\Context::class => [
                    'arguments' => [
                        'authorization' => [
                            'instance' => \Magento\Sales\Controller\Adminhtml\Order\AuthorizationMock::class,
                        ],
                    ],
                ],
            ]
        );
        \Magento\TestFramework\Helper\Bootstrap::getInstance()
            ->loadArea('adminhtml');

        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->dispatch('backend/sales/order_create/save');
        $this->assertEquals('403', $this->getResponse()->getHttpResponseCode());
    }

    /**
     * Checks a case when shipping is the same as billing and billing address details was changed by request.
     * Both billing and shipping addresses should be updated.
     *
     * @magentoAppArea adminhtml
     * @magentoDataFixture Magento/Sales/_files/quote_with_customer.php
     */
    public function testSyncBetweenQuoteAddresses()
    {
        /** @var CustomerRepositoryInterface $customerRepository */
        $customerRepository = $this->_objectManager->get(CustomerRepositoryInterface::class);
        $customer = $customerRepository->get('customer@example.com');

        /** @var CartRepositoryInterface $quoteRepository */
        $quoteRepository = $this->_objectManager->get(CartRepositoryInterface::class);
        $quote = $quoteRepository->getActiveForCustomer($customer->getId());

        $session = $this->_objectManager->get(SessionQuote::class);
        $session->setQuoteId($quote->getId());

        $data = [
            'firstname' => 'John',
            'lastname' => 'Doe',
            'street' => ['Soborna 23'],
            'city' => 'Kyiv',
            'country_id' => 'UA',
            'region' => 'Kyivska',
            'region_id' => 1
        ];
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue(
            [
                'order' => ['billing_address' => $data],
                'reset_shipping' => 1,
                'customer_id' => $customer->getId(),
                'store_id' => 1,
                'json' => true
            ]
        );

        $this->dispatch('backend/sales/order_create/loadBlock/block/shipping_address');
        self::assertEquals(200, $this->getResponse()->getHttpResponseCode());

        $updatedQuote = $quoteRepository->get($quote->getId());

        $billingAddress = $updatedQuote->getBillingAddress();
        self::assertEquals($data['region_id'], $billingAddress->getRegionId());
        self::assertEquals($data['country_id'], $billingAddress->getCountryId());

        $shippingAddress = $updatedQuote->getShippingAddress();
        self::assertEquals($data['city'], $shippingAddress->getCity());
        self::assertEquals($data['street'], $shippingAddress->getStreet());
    }

    /**
     * Gets quote entity by reserved order id.
     *
     * @param string $reservedOrderId
     * @return Quote
     */
    private function getQuoteById(string $reservedOrderId): Quote
    {
        /** @var SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = $this->_objectManager->get(SearchCriteriaBuilder::class);
        $searchCriteria = $searchCriteriaBuilder->addFilter('reserved_order_id', $reservedOrderId)
            ->create();

        /** @var CartRepositoryInterface $repository */
        $repository = $this->_objectManager->get(CartRepositoryInterface::class);
        $items = $repository->getList($searchCriteria)
            ->getItems();

        return array_pop($items);
    }

    /**
     * Gets website entity.
     *
     * @param string $code
     * @return WebsiteInterface
     * @throws NoSuchEntityException
     */
    private function getWebsite(string $code): WebsiteInterface
    {
        /** @var WebsiteRepositoryInterface $repository */
        $repository = $this->_objectManager->get(WebsiteRepositoryInterface::class);
        return $repository->get($code);
    }

    /**
     * Gets customer entity.
     *
     * @param string $email
     * @param int $websiteId
     * @return CustomerInterface
     * @throws NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getCustomer(string $email, int $websiteId): CustomerInterface
    {
        /** @var CustomerRepositoryInterface $repository */
        $repository = $this->_objectManager->get(CustomerRepositoryInterface::class);
        return $repository->get($email, $websiteId);
    }

    /**
     * Gets store by code.
     *
     * @param string $code
     * @return StoreInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getStore(string $code): StoreInterface
    {
        /** @var StoreRepositoryInterface $repository */
        $repository = $this->_objectManager->get(StoreRepositoryInterface::class);
        return $repository->get($code);
    }
}
