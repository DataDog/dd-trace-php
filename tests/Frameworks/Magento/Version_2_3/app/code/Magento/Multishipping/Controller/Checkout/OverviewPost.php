<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Multishipping\Controller\Checkout;

use Magento\Checkout\Api\PaymentProcessingRateLimiterInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Multishipping\Model\Checkout\Type\Multishipping\State;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\PaymentException;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Checkout\Api\Exception\PaymentProcessingRateLimitExceededException;

/**
 * Placing orders.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OverviewPost extends \Magento\Multishipping\Controller\Checkout implements HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\Data\Form\FormKey\Validator
     * @deprecated Form key validation is handled on the framework level.
     */
    protected $formKeyValidator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Checkout\Api\AgreementsValidatorInterface
     */
    protected $agreementsValidator;

    /**
     * @var SessionManagerInterface
     */
    private $session;

    /**
     * @var PaymentProcessingRateLimiterInterface
     */
    private $paymentRateLimiter;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param CustomerRepositoryInterface $customerRepository
     * @param AccountManagementInterface $accountManagement
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Checkout\Api\AgreementsValidatorInterface $agreementValidator
     * @param SessionManagerInterface $session
     * @param PaymentProcessingRateLimiterInterface|null $paymentRateLimiter
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        CustomerRepositoryInterface $customerRepository,
        AccountManagementInterface $accountManagement,
        \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Checkout\Api\AgreementsValidatorInterface $agreementValidator,
        SessionManagerInterface $session,
        ?PaymentProcessingRateLimiterInterface $paymentRateLimiter = null
    ) {
        $this->formKeyValidator = $formKeyValidator;
        $this->logger = $logger;
        $this->agreementsValidator = $agreementValidator;
        $this->session = $session;
        $this->paymentRateLimiter = $paymentRateLimiter
            ?? ObjectManager::getInstance()->get(PaymentProcessingRateLimiterInterface::class);

        parent::__construct(
            $context,
            $customerSession,
            $customerRepository,
            $accountManagement
        );
    }

    /**
     * Overview action
     *
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute()
    {
        try {
            $this->paymentRateLimiter->limit();
            if (!$this->_validateMinimumAmount()) {
                return;
            }

            if (!$this->agreementsValidator->isValid(array_keys($this->getRequest()->getPost('agreement', [])))) {
                $this->messageManager->addError(
                    __('Please agree to all Terms and Conditions before placing the order.')
                );
                $this->_redirect('*/*/billing');
                return;
            }

            $payment = $this->getRequest()->getPost('payment');
            $paymentInstance = $this->_getCheckout()->getQuote()->getPayment();
            if (isset($payment['cc_number'])) {
                $paymentInstance->setCcNumber($payment['cc_number']);
            }
            if (isset($payment['cc_cid'])) {
                $paymentInstance->setCcCid($payment['cc_cid']);
            }
            $this->_getCheckout()->createOrders();
            $this->_getState()->setCompleteStep(State::STEP_OVERVIEW);

            if ($this->session->getAddressErrors()) {
                $this->_getState()->setActiveStep(State::STEP_RESULTS);
                $this->_redirect('*/*/results');
            } else {
                $this->_getState()->setActiveStep(State::STEP_SUCCESS);
                $this->_getCheckout()->getCheckoutSession()->clearQuote();
                $this->_getCheckout()->getCheckoutSession()->setDisplaySuccess(true);
                $this->_redirect('*/*/success');
            }
        } catch (PaymentProcessingRateLimitExceededException $ex) {
            $this->messageManager->addErrorMessage($ex->getMessage());
            $this->_redirect('*/*/overview');
        } catch (PaymentException $e) {
            $message = $e->getMessage();
            if (!empty($message)) {
                $this->messageManager->addError($message);
            }
            $this->_redirect('*/*/billing');
        } catch (\Magento\Checkout\Exception $e) {
            $this->_objectManager->get(
                \Magento\Checkout\Helper\Data::class
            )->sendPaymentFailedEmail(
                $this->_getCheckout()->getQuote(),
                $e->getMessage(),
                'multi-shipping'
            );
            $this->_getCheckout()->getCheckoutSession()->clearQuote();
            $this->messageManager->addError($e->getMessage());
            $this->_redirect('*/cart');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->_objectManager->get(
                \Magento\Checkout\Helper\Data::class
            )->sendPaymentFailedEmail(
                $this->_getCheckout()->getQuote(),
                $e->getMessage(),
                'multi-shipping'
            );
            $this->messageManager->addError($e->getMessage());
            $this->_redirect('*/*/billing');
        } catch (\Exception $e) {
            $this->logger->critical($e);
            try {
                $this->_objectManager->get(
                    \Magento\Checkout\Helper\Data::class
                )->sendPaymentFailedEmail(
                    $this->_getCheckout()->getQuote(),
                    $e->getMessage(),
                    'multi-shipping'
                );
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }
            $this->messageManager->addError(__('Order place error'));
            $this->_redirect('*/*/billing');
        }
    }
}
