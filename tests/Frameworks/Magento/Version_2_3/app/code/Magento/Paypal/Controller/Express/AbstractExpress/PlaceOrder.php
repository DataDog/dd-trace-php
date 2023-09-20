<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Paypal\Controller\Express\AbstractExpress;

use Magento\Framework\Exception\LocalizedException;
use Magento\Paypal\Model\Api\ProcessableException as ApiProcessableException;

/**
 * Finalizes the PayPal order and executes payment
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PlaceOrder extends \Magento\Paypal\Controller\Express\AbstractExpress
{
    /**
     * @var \Magento\Checkout\Api\AgreementsValidatorInterface
     */
    protected $agreementsValidator;

    /**
     * @var \Magento\Sales\Api\PaymentFailuresInterface
     */
    private $paymentFailures;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Magento\Paypal\Model\Express\Checkout\Factory $checkoutFactory
     * @param \Magento\Framework\Session\Generic $paypalSession
     * @param \Magento\Framework\Url\Helper\Data $urlHelper
     * @param \Magento\Customer\Model\Url $customerUrl
     * @param \Magento\Checkout\Api\AgreementsValidatorInterface $agreementValidator
     * @param \Magento\Sales\Api\PaymentFailuresInterface|null $paymentFailures
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Paypal\Model\Express\Checkout\Factory $checkoutFactory,
        \Magento\Framework\Session\Generic $paypalSession,
        \Magento\Framework\Url\Helper\Data $urlHelper,
        \Magento\Customer\Model\Url $customerUrl,
        \Magento\Checkout\Api\AgreementsValidatorInterface $agreementValidator,
        \Magento\Sales\Api\PaymentFailuresInterface $paymentFailures = null
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $orderFactory,
            $checkoutFactory,
            $paypalSession,
            $urlHelper,
            $customerUrl
        );

        $this->agreementsValidator = $agreementValidator;
        $this->paymentFailures = $paymentFailures ? : $this->_objectManager->get(
            \Magento\Sales\Api\PaymentFailuresInterface::class
        );
    }

    /**
     * Submit the order
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute()
    {
        if ($this->isValidationRequired() &&
            !$this->agreementsValidator->isValid(array_keys($this->getRequest()->getPost('agreement', [])))
        ) {
            $e = new \Magento\Framework\Exception\LocalizedException(
                __(
                    "The order wasn't placed. "
                    . "First, agree to the terms and conditions, then try placing your order again."
                )
            );
            $this->messageManager->addExceptionMessage(
                $e,
                $e->getMessage()
            );
            $this->_redirect('*/*/review');
            return;
        }

        try {
            $this->_initCheckout();
            $this->_checkout->place($this->_initToken());

            // prepare session to success or cancellation page
            $this->_getCheckoutSession()->clearHelperData();
            $this->_getSession()->unsQuoteId();

            // "last successful quote"
            $quoteId = $this->_getQuote()->getId();
            $this->_getCheckoutSession()->setLastQuoteId($quoteId)->setLastSuccessQuoteId($quoteId);

            // an order may be created
            $order = $this->_checkout->getOrder();
            if ($order) {
                $this->_getCheckoutSession()->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus());
            }

            $this->_eventManager->dispatch(
                'paypal_express_place_order_success',
                [
                    'order' => $order,
                    'quote' => $this->_getQuote()
                ]
            );

            // redirect if PayPal specified some URL (for example, to Giropay bank)
            $url = $this->_checkout->getRedirectUrl();
            if ($url) {
                $this->getResponse()->setRedirect($url);
                return;
            }
            $this->_initToken(false); // no need in token anymore
            $this->_redirect('checkout/onepage/success');
            return;
        } catch (ApiProcessableException $e) {
            $this->_processPaypalApiError($e);
        } catch (LocalizedException $e) {
            $this->processException($e, $e->getRawMessage());
        } catch (\Exception $e) {
            $this->processException($e, 'We can\'t place the order.');
        }
    }

    /**
     * Process exception.
     *
     * @param \Exception $exception
     * @param string $message
     *
     * @return void
     */
    private function processException(\Exception $exception, string $message): void
    {
        $this->messageManager->addExceptionMessage($exception, __($message));
        $this->_redirect('*/*/review');
    }

    /**
     * Process PayPal API's processable errors
     *
     * @param \Magento\Paypal\Model\Api\ProcessableException $exception
     * @return void
     */
    protected function _processPaypalApiError($exception)
    {
        $this->paymentFailures->handle((int)$this->_getCheckoutSession()->getQuoteId(), $exception->getMessage());

        switch ($exception->getCode()) {
            case ApiProcessableException::API_MAX_PAYMENT_ATTEMPTS_EXCEEDED:
            case ApiProcessableException::API_TRANSACTION_EXPIRED:
                $this->getResponse()->setRedirect(
                    $this->_getQuote()->getPayment()->getCheckoutRedirectUrl()
                );
                break;
            case ApiProcessableException::API_DO_EXPRESS_CHECKOUT_FAIL:
                $this->_redirectSameToken();
                break;
            case ApiProcessableException::API_ADDRESS_MATCH_FAIL:
                $this->redirectToOrderReviewPageAndShowError($exception->getUserMessage());
                break;
            case ApiProcessableException::API_UNABLE_TRANSACTION_COMPLETE:
                if ($this->_config->getPaymentAction() == \Magento\Payment\Model\Method\AbstractMethod::ACTION_ORDER) {
                    $paypalTransactionData = $this->_getCheckoutSession()->getPaypalTransactionData();
                    $this->getResponse()->setRedirect(
                        $this->_config->getExpressCheckoutOrderUrl($paypalTransactionData['transaction_id'])
                    );
                } else {
                    $this->_redirectSameToken();
                }
                break;
            default:
                $this->_redirectToCartAndShowError($exception->getUserMessage());
                break;
        }
    }

    /**
     * Redirect customer back to PayPal with the same token
     *
     * @return void
     */
    protected function _redirectSameToken()
    {
        $token = $this->_initToken();
        $this->getResponse()->setRedirect(
            $this->_config->getExpressCheckoutStartUrl($token)
        );
    }

    /**
     * Redirect customer to shopping cart and show error message
     *
     * @param string $errorMessage
     * @return void
     */
    protected function _redirectToCartAndShowError($errorMessage)
    {
        $this->messageManager->addErrorMessage($errorMessage);
        $this->_redirect('checkout/cart');
    }

    /**
     * Redirect customer to the paypal order review page and show error message
     *
     * @param string $errorMessage
     * @return void
     */
    private function redirectToOrderReviewPageAndShowError($errorMessage)
    {
        $this->messageManager->addErrorMessage($errorMessage);
        $this->_redirect('*/*/review');
    }

    /**
     * Return true if agreements validation required
     *
     * @return bool
     */
    protected function isValidationRequired()
    {
        return is_array($this->getRequest()->getBeforeForwardInfo())
            && empty($this->getRequest()->getBeforeForwardInfo());
    }
}
