<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Newsletter\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\EmailMessage;
use Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use PHPUnit\Framework\TestCase;

/**
 * Class checks subscription behavior.
 *
 * @see \Magento\Newsletter\Model\Subscriber
 */
class SubscriberTest extends TestCase
{
    private const CONFIRMATION_SUBSCRIBE = 'You have been successfully subscribed to our newsletter.';
    private const CONFIRMATION_UNSUBSCRIBE = 'You have been unsubscribed from the newsletter.';

    /**
     * @var SubscriberFactory
     */
    private $subscriberFactory;

    /**
     * @var TransportBuilderMock
     */
    private $transportBuilder;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var QueueFactory
     */
    private $queueFactory;

    /**
     * @var CollectionFactory
     */
    private $subscriberCollectionFactory;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->subscriberFactory = $objectManager->get(SubscriberFactory::class);
        $this->transportBuilder = $objectManager->get(TransportBuilderMock::class);
        $this->customerRepository = $objectManager->get(CustomerRepositoryInterface::class);
        $this->queueFactory = $objectManager->get(QueueFactory::class);
        $this->subscriberCollectionFactory = $objectManager->get(CollectionFactory::class);
    }

    /**
     * @magentoConfigFixture current_store newsletter/subscription/confirm 1
     *
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @return void
     */
    public function testEmailConfirmation(): void
    {
        $subscriber = $this->subscriberFactory->create();
        $subscriber->subscribe('customer_confirm@example.com');
        // confirmationCode 'ysayquyajua23iq29gxwu2eax2qb6gvy' is taken from fixture
        $this->assertStringContainsString(
            '/newsletter/subscriber/confirm/id/' . $subscriber->getSubscriberId()
            . '/code/ysayquyajua23iq29gxwu2eax2qb6gvy',
            $this->transportBuilder->getSentMessage()->getBody()->getParts()[0]->getRawContent()
        );
        $this->assertEquals(Subscriber::STATUS_NOT_ACTIVE, $subscriber->getSubscriberStatus());
    }

    /**
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @return void
     */
    public function testLoadByCustomerId(): void
    {
        $subscriber = $this->subscriberFactory->create();
        $this->assertSame($subscriber, $subscriber->loadByCustomerId(1));
        $this->assertEquals('customer@example.com', $subscriber->getSubscriberEmail());
    }

    /**
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @magentoAppArea frontend
     *
     * @return void
     */
    public function testUnsubscribeSubscribe(): void
    {
        $subscriber = $this->subscriberFactory->create();
        $this->assertSame($subscriber, $subscriber->loadByCustomerId(1));
        $this->assertEquals($subscriber, $subscriber->unsubscribe());
        $this->assertConfirmationParagraphExists(
            self::CONFIRMATION_UNSUBSCRIBE,
            $this->transportBuilder->getSentMessage()
        );

        $this->assertEquals(Subscriber::STATUS_UNSUBSCRIBED, $subscriber->getSubscriberStatus());
        // Subscribe and verify
        $this->assertEquals(Subscriber::STATUS_SUBSCRIBED, $subscriber->subscribe('customer@example.com'));
        $this->assertEquals(Subscriber::STATUS_SUBSCRIBED, $subscriber->getSubscriberStatus());

        $this->assertConfirmationParagraphExists(
            self::CONFIRMATION_SUBSCRIBE,
            $this->transportBuilder->getSentMessage()
        );
    }

    /**
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @magentoAppArea frontend
     *
     * @return void
     */
    public function testUnsubscribeSubscribeByCustomerId(): void
    {
        $subscriber = $this->subscriberFactory->create();
        // Unsubscribe and verify
        $this->assertSame($subscriber, $subscriber->unsubscribeCustomerById(1));
        $this->assertEquals(Subscriber::STATUS_UNSUBSCRIBED, $subscriber->getSubscriberStatus());
        $this->assertConfirmationParagraphExists(
            self::CONFIRMATION_UNSUBSCRIBE,
            $this->transportBuilder->getSentMessage()
        );

        // Subscribe and verify
        $this->assertSame($subscriber, $subscriber->subscribeCustomerById(1));
        $this->assertEquals(Subscriber::STATUS_SUBSCRIBED, $subscriber->getSubscriberStatus());
        $this->assertConfirmationParagraphExists(
            self::CONFIRMATION_SUBSCRIBE,
            $this->transportBuilder->getSentMessage()
        );
    }

    /**
     * Test subscribe and verify customer subscription status
     *
     * @magentoDataFixture Magento/Customer/_files/customer_sample.php
     *
     * @return void
     */
    public function testSubscribeAndVerifyCustomerSubscriptionStatus(): void
    {
        $customer = $this->customerRepository->getById(1);
        $subscriptionBefore = $customer->getExtensionAttributes()
            ->getIsSubscribed();
        $subscriber = $this->subscriberFactory->create();
        $subscriber->subscribeCustomerById($customer->getId());

        $customer = $this->customerRepository->getById(1);

        $this->assertFalse($subscriptionBefore);
        $this->assertTrue($customer->getExtensionAttributes()->getIsSubscribed());
    }

    /**
     * @magentoConfigFixture current_store newsletter/subscription/confirm 1
     *
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @return void
     */
    public function testConfirm(): void
    {
        $subscriber = $this->subscriberFactory->create();
        $customerEmail = 'customer_confirm@example.com';
        $subscriber->subscribe($customerEmail);
        $subscriber->loadByEmail($customerEmail);
        $subscriber->confirm($subscriber->getSubscriberConfirmCode());

        $this->assertConfirmationParagraphExists(
            self::CONFIRMATION_SUBSCRIBE,
            $this->transportBuilder->getSentMessage()
        );
    }

    /**
     * Unsubscribe and check queue
     *
     * @magentoDataFixture Magento/Newsletter/_files/queue.php
     *
     * @return void
     */
    public function testUnsubscribeCustomer(): void
    {
        $firstSubscriber = $this->subscriberFactory->create()
            ->load('customer@example.com', 'subscriber_email');
        $secondSubscriber = $this->subscriberFactory->create()
            ->load('customer_two@example.com', 'subscriber_email');

        $queue = $this->queueFactory->create()
            ->load('CustomerSupport', 'newsletter_sender_name');
        $queue->addSubscribersToQueue([$firstSubscriber->getId(), $secondSubscriber->getId()]);

        $secondSubscriber->unsubscribe();

        $collection = $this->subscriberCollectionFactory->create()
            ->useQueue($queue);

        $this->assertCount(1, $collection);
        $this->assertEquals(
            'customer@example.com',
            $collection->getFirstItem()->getData('subscriber_email')
        );
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer_confirmation_config_enable.php
     * @magentoDataFixture Magento/Newsletter/_files/newsletter_unconfirmed_customer.php
     *
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function testSubscribeUnconfirmedCustomerWithSubscription(): void
    {
        $customer = $this->customerRepository->get('unconfirmedcustomer@example.com');
        $subscriber = $this->subscriberFactory->create();
        $subscriber->subscribeCustomerById($customer->getId());
        $this->assertEquals(Subscriber::STATUS_SUBSCRIBED, $subscriber->getStatus());
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer_confirmation_config_enable.php
     * @magentoDataFixture Magento/Customer/_files/unconfirmed_customer.php
     *
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function testSubscribeUnconfirmedCustomerWithoutSubscription(): void
    {
        $customer = $this->customerRepository->get('unconfirmedcustomer@example.com');
        $subscriber = $this->subscriberFactory->create();
        $subscriber->subscribeCustomerById($customer->getId());
        $this->assertEquals(Subscriber::STATUS_UNCONFIRMED, $subscriber->getStatus());
    }

    /**
     * Verifies if Paragraph with specified message is in e-mail
     *
     * @param string $expectedMessage
     * @param EmailMessage $message
     */
    private function assertConfirmationParagraphExists(string $expectedMessage, EmailMessage $message): void
    {
        $messageContent = $this->getMessageRawContent($message);

        $emailDom = new \DOMDocument();
        $emailDom->loadHTML($messageContent);

        $emailXpath = new \DOMXPath($emailDom);
        $greeting = $emailXpath->query("//p[contains(text(), '$expectedMessage')]");

        $this->assertSame(1, $greeting->length, "Cannot find the confirmation paragraph in e-mail contents");
    }

    /**
     * Returns raw content of provided message
     *
     * @param EmailMessage $message
     * @return string
     */
    private function getMessageRawContent(EmailMessage $message): string
    {
        $emailParts = $message->getBody()->getParts();
        return current($emailParts)->getRawContent();
    }
}
