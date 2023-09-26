<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Paypal\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\State\InvalidTransitionException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Helper\Formatter;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\ConfigInterface;
use Magento\Payment\Model\Method\ConfigInterfaceFactory;
use Magento\Payment\Model\Method\Online\GatewayInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Paypal\Model\Payflow\Service\Gateway;
use Magento\Paypal\Model\Payflow\Service\Response\Handler\HandlerInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\ScopeInterface;

/**
 * Payflow Pro payment gateway model
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Payflowpro extends \Magento\Payment\Model\Method\Cc implements GatewayInterface
{
    use Formatter;

    /**
     * Transaction action codes
     */
    const TRXTYPE_AUTH_ONLY = 'A';

    const TRXTYPE_SALE = 'S';

    const TRXTYPE_CREDIT = 'C';

    const TRXTYPE_DELAYED_CAPTURE = 'D';

    const TRXTYPE_DELAYED_VOID = 'V';

    const TRXTYPE_DELAYED_VOICE = 'F';

    const TRXTYPE_DELAYED_INQUIRY = 'I';

    const TRXTYPE_ACCEPT_DENY       = 'U';

    const UPDATEACTION_APPROVED = 'APPROVE';

    const UPDATEACTION_DECLINED_BY_MERCHANT = 'FPS_MERCHANT_DECLINE';

    /**
     * Tender type codes
     */
    const TENDER_CC = 'C';

    /**
     * Gateway request URLs
     */
    const TRANSACTION_URL = 'https://payflowpro.paypal.com/transaction';

    const TRANSACTION_URL_TEST_MODE = 'https://pilot-payflowpro.paypal.com/transaction';

    /**#@+
     * Response code
     */
    const RESPONSE_CODE_APPROVED = 0;

    const RESPONSE_CODE_INVALID_AMOUNT = 4;

    const RESPONSE_CODE_FRAUDSERVICE_FILTER = 126;

    const RESPONSE_CODE_DECLINED = 12;

    const RESPONSE_CODE_DECLINED_BY_FILTER = 125;

    const RESPONSE_CODE_DECLINED_BY_MERCHANT = 128;

    const RESPONSE_CODE_CAPTURE_ERROR = 111;

    const RESPONSE_CODE_VOID_ERROR = 108;

    private const RESPONSE_CODE_AUTHORIZATION_EXPIRED = 10601;

    const PNREF = 'pnref';

    /**#@-*/
    protected $_responseParamsMappings = [
        'firstname' => 'billtofirstname',
        'lastname' => 'billtolastname',
        'address' => 'billtostreet',
        'city' => 'billtocity',
        'state' => 'billtostate',
        'zip' => 'billtozip',
        'country' => 'billtocountry',
        'phone' => 'billtophone',
        'email' => 'billtoemail',
        'nametoship' => 'shiptofirstname',
        'addresstoship' => 'shiptostreet',
        'citytoship' => 'shiptocity',
        'statetoship' => 'shiptostate',
        'ziptoship' => 'shiptozip',
        'countrytoship' => 'shiptocountry',
        'phonetoship' => 'shiptophone',
        'emailtoship' => 'shiptoemail',
        'faxtoship' => 'shiptofax',
        'method' => 'tender',
        'cscmatch' => 'cvv2match',
        'type' => 'trxtype',
        'cclast4' => 'acct',
        'ccavsstatus' => 'avsdata',
        'amt' => 'amt',
        'transtime' => 'transtime',
        'expdate' => 'expdate',
        'securetoken' => 'securetoken',
        'securetokenid' => 'securetokenid',
        'authcode' => 'authcode',
        'hostcode' => 'hostcode',
        'pnref' => 'pnref',
        'cc_type' => 'cardtype'
    ];

    /**
     * PayPal credit card type map.
     * @see https://developer.paypal.com/docs/classic/payflow/integration-guide/#credit-card-transaction-responses
     *
     * @var array
     */
    private $ccTypeMap = [
        '0' => 'VI',
        '1' => 'MC',
        '2' => 'DI',
        '3' => 'AE',
        '4' => 'DN',
        '5' => 'JCB'
    ];

    /**
     * Payment method code
     *
     * @var string
     */
    protected $_code = \Magento\Paypal\Model\Config::METHOD_PAYFLOWPRO;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isGateway = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canCapturePartial = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canVoid = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseInternal = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canSaveCc = false;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isProxy = false;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canFetchTransactionInfo = true;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canReviewPayment = true;

    /**
     * Gateway request timeout
     *
     * @var int
     */
    protected $_clientTimeout = 45;

    /**
     * Fields that should be replaced in debug with '***'
     *
     * @var string[]
     */
    protected $_debugReplacePrivateDataKeys = ['user', 'pwd', 'acct', 'expdate', 'cvv2'];

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ConfigInterfaceFactory
     */
    protected $configFactory;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var Gateway
     */
    private $gateway;

    /**
     * @var HandlerInterface
     */
    private $errorHandler;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param ConfigInterfaceFactory $configFactory
     * @param Gateway $gateway
     * @param HandlerInterface $errorHandler
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        ConfigInterfaceFactory $configFactory,
        Gateway $gateway,
        HandlerInterface $errorHandler,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        $this->configFactory = $configFactory;
        $this->gateway = $gateway;
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $moduleList,
            $localeDate,
            $resource,
            $resourceCollection,
            $data
        );
        $this->errorHandler = $errorHandler;
    }

    /**
     * Check whether payment method can be used
     *
     * @param \Magento\Quote\Api\Data\CartInterface|Quote|null $quote
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->getConfig()->isMethodAvailable($this->getCode());
    }

    /**
     * Is active
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive($storeId = null)
    {
        $pathPayflowPro = 'payment/' . Config::METHOD_PAYFLOWPRO . '/active';
        $pathPaymentPro = 'payment/' . Config::METHOD_PAYMENT_PRO . '/active';

        return (bool)(int) $this->_scopeConfig->getValue($pathPayflowPro, ScopeInterface::SCOPE_STORE, $storeId)
            || (bool)(int) $this->_scopeConfig->getValue($pathPaymentPro, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Payment action getter compatible with payment model
     *
     * @return string
     */
    public function getConfigPaymentAction()
    {
        return $this->getConfig()->getPaymentAction();
    }

    /**
     * Authorize payment
     *
     * @param InfoInterface|Payment|Object $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws InvalidTransitionException
     */
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $request = $this->_buildPlaceRequest($payment, $amount);
        $this->addRequestOrderInfo($request, $payment->getOrder());
        $request->setTrxtype(self::TRXTYPE_AUTH_ONLY);
        $response = $this->postRequest($request, $this->getConfig());
        $this->processErrors($response);
        $this->setTransStatus($payment, $response);
        return $this;
    }

    /**
     * Get capture amount
     *
     * @param float $amount
     * @return float|int
     */
    protected function _getCaptureAmount($amount)
    {
        $infoInstance = $this->getInfoInstance();
        $amountToPay = $amount;
        $authorizedAmount = $infoInstance->getAmountAuthorized();
        return abs($amountToPay - $authorizedAmount) < 0.00001 ? 0 : $amountToPay;
    }

    /**
     * Capture payment
     *
     * @param InfoInterface|Payment|Object $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws InvalidTransitionException
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if ($payment->getAdditionalInformation(self::PNREF)) {
            $request = $this->buildBasicRequest();
            $request->setAmt($this->formatPrice($amount));
            $request->setTrxtype(self::TRXTYPE_SALE);
            $request->setOrigid($payment->getAdditionalInformation(self::PNREF));
            $payment->unsAdditionalInformation(self::PNREF);
            $request->setData('currency', $payment->getOrder()->getBaseCurrencyCode());
        } elseif ($payment->getParentTransactionId()) {
            $request = $this->buildBasicRequest();
            $request->setOrigid($payment->getParentTransactionId());
            $captureAmount = $this->_getCaptureAmount($amount);
            if ($captureAmount) {
                $request->setAmt($this->formatPrice($captureAmount));
            }
            $trxType = $this->getInfoInstance()->hasAmountPaid() ? self::TRXTYPE_SALE : self::TRXTYPE_DELAYED_CAPTURE;
            $request->setTrxtype($trxType);
        } else {
            $request = $this->_buildPlaceRequest($payment, $amount);
            $request->setTrxtype(self::TRXTYPE_SALE);
        }
        $this->addRequestOrderInfo($request, $payment->getOrder());

        $response = $this->postRequest($request, $this->getConfig());
        $this->processErrors($response);
        $this->setTransStatus($payment, $response);
        return $this;
    }

    /**
     * Void payment
     *
     * @param InfoInterface|Payment|Object $payment
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws InvalidTransitionException
     */
    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        $request = $this->buildBasicRequest();
        $request->setTrxtype(self::TRXTYPE_DELAYED_VOID);
        $request->setOrigid($payment->getParentTransactionId());
        $response = $this->postRequest($request, $this->getConfig());
        $this->processErrors($response);

        if ($response->getResultCode() == self::RESPONSE_CODE_APPROVED) {
            $payment->setTransactionId(
                $response->getPnref()
            )->setIsTransactionClosed(
                1
            )->setShouldCloseParentTransaction(
                1
            );
        }

        return $this;
    }

    /**
     * Check void availability
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function canVoid()
    {
        if ($this->getInfoInstance()->getAmountPaid()) {
            $this->_canVoid = false;
        }

        return $this->_canVoid;
    }

    /**
     * Attempt to void the authorization on cancelling
     *
     * @param InfoInterface|Object $payment
     * @return $this
     * @throws CommandException
     */
    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        if (!$payment->getOrder()->getInvoiceCollection()->count()) {
            try {
                $this->void($payment);
            } catch (CommandException $e) {
                // Ignore error about expiration of authorization transaction.
                if (strpos($e->getMessage(), (string)self::RESPONSE_CODE_AUTHORIZATION_EXPIRED) === false) {
                    throw $e;
                }
            }

        }

        return $this;
    }

    /**
     * Refund capture
     *
     * @param InfoInterface|Payment|Object $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws InvalidTransitionException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $request = $this->buildBasicRequest();
        $request->setTrxtype(self::TRXTYPE_CREDIT);
        $request->setOrigid($payment->getParentTransactionId());
        $request->setAmt($this->formatPrice($amount));
        $response = $this->postRequest($request, $this->getConfig());
        $this->processErrors($response);

        if ($response->getResultCode() == self::RESPONSE_CODE_APPROVED) {
            $payment->setTransactionId($response->getPnref())->setIsTransactionClosed(true);
        }
        return $this;
    }

    /**
     * Fetch transaction details info
     *
     * @param InfoInterface $payment
     * @param string $transactionId
     * @return array
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        $response = $this->transactionInquiryRequest($payment, $transactionId);

        $this->processErrors($response);

        if (!$this->_isTransactionUnderReview($response->getOrigresult())) {
            $payment->setTransactionId($response->getOrigpnref())->setIsTransactionClosed(0);
            if ($response->getOrigresult() == self::RESPONSE_CODE_APPROVED) {
                $payment->setIsTransactionApproved(true);
            } elseif ($response->getOrigresult() == self::RESPONSE_CODE_DECLINED_BY_MERCHANT) {
                $payment->setIsTransactionDenied(true);
            }
        }

        $rawData = $response->getData();
        return $rawData ? $rawData : [];
    }

    /**
     * Check whether the transaction is in payment review status
     *
     * @param string $status
     * @return bool
     */
    protected function _isTransactionUnderReview($status)
    {
        if (in_array($status, [self::RESPONSE_CODE_APPROVED, self::RESPONSE_CODE_DECLINED_BY_MERCHANT])) {
            return false;
        }
        return true;
    }

    /**
     * Get Config instance
     *
     * @return PayflowConfig
     */
    public function getConfig()
    {
        if (!$this->config) {
            $storeId = $this->storeManager->getStore($this->getStore())->getId();

            $this->config = $this->configFactory->create();

            $this->config->setStoreId($storeId);
            $this->config->setMethodInstance($this);
            $this->config->setMethod($this);
        }

        return $this->config;
    }

    /**
     * @inheritdoc
     */
    public function postRequest(DataObject $request, ConfigInterface $config)
    {
        try {
            return $this->gateway->postRequest($request, $config);
        } catch (\Zend_Http_Client_Exception $e) {
            throw new LocalizedException(
                __('Payment Gateway is unreachable at the moment. Please use another payment option.'),
                $e
            );
        }
    }

    /**
     * Return request object with information for 'authorization' or 'sale' action
     *
     * @param Object|Payment $payment
     * @param float $amount
     * @return DataObject
     */
    protected function _buildPlaceRequest(DataObject $payment, $amount)
    {
        $request = $this->buildBasicRequest();
        $request->setAmt($this->formatPrice($amount));
        $request->setAcct($payment->getCcNumber());
        $request->setExpdate(sprintf('%02d', $payment->getCcExpMonth()) . substr($payment->getCcExpYear(), -2, 2));
        $request->setCvv2($payment->getCcCid());

        $order = $payment->getOrder();
        $request->setCurrency($order->getBaseCurrencyCode());
        $request = $this->fillCustomerContacts($order, $request);
        return $request;
    }

    /**
     * Return request object with basic information for gateway request
     *
     * @return DataObject
     */
    public function buildBasicRequest()
    {
        $request = new DataObject();

        /** @var \Magento\Paypal\Model\PayflowConfig $config */
        $config = $this->getConfig();

        $request->setUser($this->getConfigData('user'));
        $request->setVendor($this->getConfigData('vendor'));
        $request->setPartner($this->getConfigData('partner'));
        $request->setPwd($this->getConfigData('pwd'));
        $request->setVerbosity($this->getConfigData('verbosity'));
        $request->setData('BUTTONSOURCE', $config->getBuildNotationCode());
        $request->setTender(self::TENDER_CC);

        return $request;
    }

    /**
     * If response is failed throw exception
     *
     * @param DataObject $response
     * @return void
     * @throws CommandException
     * @throws InvalidTransitionException
     */
    public function processErrors(DataObject $response)
    {
        $resultCode = (int)$response->getResultCode();
        if ($resultCode === self::RESPONSE_CODE_VOID_ERROR) {
            throw new InvalidTransitionException(
                __("The verification transaction can't be voided. ")
            );
        }
        if (!in_array($resultCode, [self::RESPONSE_CODE_APPROVED, self::RESPONSE_CODE_FRAUDSERVICE_FILTER])) {
            throw new CommandException(__($response->getRespmsg()));
        }
        if ((int)$response->getOrigresult() === self::RESPONSE_CODE_DECLINED_BY_FILTER) {
            throw new CommandException(__($response->getRespmsg()));
        }
    }

    /**
     * Attempt to accept a pending payment
     *
     * @param InfoInterface $payment
     * @return bool
     */
    public function acceptPayment(InfoInterface $payment)
    {
        return $this->reviewPayment($payment, self::UPDATEACTION_APPROVED);
    }

    /**
     * Attempt to deny a pending payment
     *
     * @param InfoInterface $payment
     * @return bool
     */
    public function denyPayment(InfoInterface $payment)
    {
        return $this->reviewPayment($payment, self::UPDATEACTION_DECLINED_BY_MERCHANT);
    }

    /**
     * Perform the payment review
     *
     * @param InfoInterface $payment
     * @param string $action
     * @return bool
     */
    public function reviewPayment(InfoInterface $payment, $action)
    {
        $request = $this->buildBasicRequest();
        $transactionId = ($payment->getCcTransId()) ? $payment->getCcTransId() : $payment->getLastTransId();
        $request->setTrxtype(self::TRXTYPE_ACCEPT_DENY);
        $request->setOrigid($transactionId);
        $request->setUpdateaction($action);

        $response = $this->postRequest($request, $this->getConfig());
        $payment->setAdditionalInformation((array)$response->getData());
        $this->processErrors($response);

        if (!$this->_isTransactionUnderReview($response->getOrigresult())) {
            $payment->setTransactionId($response->getOrigpnref())->setIsTransactionClosed(0);
            if ($response->getOrigresult() == self::RESPONSE_CODE_APPROVED) {
                $payment->setIsTransactionApproved(true);
            } elseif ($response->getOrigresult() == self::RESPONSE_CODE_DECLINED_BY_MERCHANT) {
                $payment->setIsTransactionDenied(true);
            }
        }
        $rawData = $response->getData();
        return ($rawData) ? $rawData : [];
    }

    /**
     * Set billing address
     *
     * @param DataObject $request
     * @param DataObject $billing
     *
     * @return Object
     */
    public function setBilling(DataObject $request, $billing)
    {
        $request->setFirstname(
            $billing->getFirstname()
        )->setLastname(
            $billing->getLastname()
        )->setStreet(
            implode(' ', $billing->getStreet())
        )->setCity(
            $billing->getCity()
        )->setState(
            $billing->getRegionCode()
        )->setZip(
            $billing->getPostcode()
        )->setCountry(
            $billing->getCountryId()
        );
        return $request;
    }

    /**
     * Set shipping address
     *
     * @param DataObject $request
     * @param DataObject $shipping
     *
     * @return Object
     */
    public function setShipping($request, $shipping)
    {
        $request->setShiptofirstname(
            $shipping->getFirstname()
        )->setShiptolastname(
            $shipping->getLastname()
        )->setShiptostreet(
            implode(' ', $shipping->getStreet())
        )->setShiptocity(
            $shipping->getCity()
        )->setShiptostate(
            $shipping->getRegionCode()
        )->setShiptozip(
            $shipping->getPostcode()
        )->setShiptocountry(
            $shipping->getCountryId()
        );
        return $request;
    }

    /**
     * Fill response with data.
     *
     * @param array $postData
     * @param DataObject $response
     *
     * @return DataObject
     */
    public function mapGatewayResponse(array $postData, DataObject $response)
    {
        $response->setData(array_change_key_case($postData));

        foreach ($this->_responseParamsMappings as $originKey => $key) {
            if ($response->getData($key) !== null) {
                $response->setData($originKey, $response->getData($key));
            }
        }

        $response->setData(
            'avsdata',
            $this->mapResponseAvsData(
                $response->getData('avsaddr'),
                $response->getData('avszip')
            )
        );

        $response->setData(
            'name',
            $this->mapResponseBillToName(
                $response->getData('billtofirstname'),
                $response->getData('billtolastname')
            )
        );

        $response->setData(
            OrderPaymentInterface::CC_TYPE,
            $this->mapResponseCreditCardType(
                $response->getData(OrderPaymentInterface::CC_TYPE)
            )
        );

        return $response;
    }

    /**
     * Set transaction status
     *
     * @param DataObject $payment
     * @param DataObject $response
     *
     * @return Object
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function setTransStatus($payment, $response)
    {
        if ($payment instanceof InfoInterface) {
            $this->errorHandler->handle($payment, $response);
        }

        switch ($response->getResultCode()) {
            case self::RESPONSE_CODE_APPROVED:
                $payment->setTransactionId($response->getPnref())->setIsTransactionClosed(0);
                break;
            case self::RESPONSE_CODE_DECLINED_BY_FILTER:
            case self::RESPONSE_CODE_FRAUDSERVICE_FILTER:
                $payment->setTransactionId($response->getPnref())->setIsTransactionClosed(0);
                $payment->setIsTransactionPending(true);
                $payment->setIsFraudDetected(true);
                break;
            case self::RESPONSE_CODE_DECLINED:
                throw new \Magento\Framework\Exception\LocalizedException(
                    __($response->getRespmsg())
                );
            default:
                break;
        }
        return $payment;
    }

    /**
     * Fill customer contacts
     *
     * @param DataObject $order
     * @param DataObject $request
     * @return DataObject
     */
    public function fillCustomerContacts(DataObject $order, DataObject $request)
    {
        $billing = $order->getBillingAddress();
        if (!empty($billing)) {
            $request = $this->setBilling($request, $billing);
            $request->setEmail($order->getCustomerEmail());
        }
        $shipping = $order->getShippingAddress();
        if (!empty($shipping)) {
            $request = $this->setShipping($request, $shipping);
            return $request;
        }
        return $request;
    }

    /**
     * Add order details to payment request
     *
     * @param DataObject $request
     * @param Order $order
     * @return void
     */
    public function addRequestOrderInfo(DataObject $request, Order $order)
    {
        $id = $order->getId();
        // for auth request order id is not exists yet
        if (!empty($id)) {
            $request->setPonum($id);
        }
        $orderIncrementId = $order->getIncrementId();
        $request->setCustref($orderIncrementId)
            ->setInvnum($orderIncrementId)
            ->setData('comment1', $orderIncrementId);
    }

    /**
     * Assign data to info model instance
     *
     * @param array|DataObject $data
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        $this->_eventManager->dispatch(
            'payment_method_assign_data_' . $this->getCode(),
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE => $this->getInfoInstance(),
                AbstractDataAssignObserver::DATA_CODE => $data
            ]
        );

        $this->_eventManager->dispatch(
            'payment_method_assign_data',
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE => $this->getInfoInstance(),
                AbstractDataAssignObserver::DATA_CODE => $data
            ]
        );

        return $this;
    }

    /**
     * Make a transaction Inquiry Request
     *
     * @param InfoInterface $payment
     * @param string $transactionId
     * @return DataObject
     * @throws LocalizedException
     */
    protected function transactionInquiryRequest(InfoInterface $payment, $transactionId)
    {
        $request = $this->buildBasicRequest();
        $request->setTrxtype(self::TRXTYPE_DELAYED_INQUIRY);
        $transactionId = $payment->getCcTransId() ? $payment->getCcTransId() : $transactionId;
        $request->setOrigid($transactionId);
        $response = $this->postRequest($request, $this->getConfig());

        return $response;
    }

    /**
     * Maps PayPal `avsdata` field.
     *
     * @param string|null $avsAddr
     * @param string|null $avsZip
     * @return string|null
     */
    private function mapResponseAvsData($avsAddr, $avsZip)
    {
        return isset($avsAddr, $avsZip) ? $avsAddr . $avsZip : null;
    }

    /**
     * Maps PayPal `name` field.
     *
     * @param string|null $billToFirstName
     * @param string|null $billToLastName
     * @return string|null
     */
    private function mapResponseBillToName($billToFirstName, $billToLastName)
    {
        return isset($billToFirstName, $billToLastName)
            ? implode(' ', [$billToFirstName, $billToLastName])
            : null;
    }

    /**
     * Map PayPal transaction response credit card type to Magento values if possible.
     *
     * @param string|null $ccType
     * @return string|null
     */
    private function mapResponseCreditCardType($ccType)
    {
        return isset($this->ccTypeMap[$ccType]) ? $this->ccTypeMap[$ccType] : $ccType;
    }
}
