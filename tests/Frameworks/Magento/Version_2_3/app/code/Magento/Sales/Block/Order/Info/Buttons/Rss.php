<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Block\Order\Info\Buttons;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Rss\Signature;

/**
 * Block of links in Order view page
 *
 * @api
 * @since 100.0.2
 */
class Rss extends \Magento\Framework\View\Element\Template
{
    /**
     * @var string
     */
    protected $_template = 'Magento_Sales::order/info/buttons/rss.phtml';

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * @var \Magento\Framework\App\Rss\UrlBuilderInterface
     */
    protected $rssUrlBuilder;

    /**
     * @var Signature
     */
    private $signature;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Magento\Framework\App\Rss\UrlBuilderInterface $rssUrlBuilder
     * @param array $data
     * @param Signature|null $signature
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Framework\App\Rss\UrlBuilderInterface $rssUrlBuilder,
        array $data = [],
        Signature $signature = null
    ) {
        $this->orderFactory = $orderFactory;
        $this->rssUrlBuilder = $rssUrlBuilder;
        $this->signature = $signature ?: ObjectManager::getInstance()->get(Signature::class);

        parent::__construct($context, $data);
    }

    /**
     * Get link url.
     *
     * @return string
     */
    public function getLink()
    {
        return $this->rssUrlBuilder->getUrl($this->getLinkParams());
    }

    /**
     * Get translatable label for url.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getLabel()
    {
        return __('Subscribe to Order Status');
    }

    /**
     * Check whether status notification is allowed
     *
     * @return bool
     */
    public function isRssAllowed()
    {
        return $this->_scopeConfig->isSetFlag(
            'rss/order/status',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Retrieve order status url key
     *
     * @param \Magento\Sales\Model\Order $order
     * @return string
     */
    protected function getUrlKey($order)
    {
        $data = [
            'order_id' => $order->getId(),
            'increment_id' => $order->getIncrementId(),
            'customer_id' => $order->getCustomerId(),
        ];
        return base64_encode(json_encode($data));
    }

    /**
     * Get type, secure and query params for link.
     *
     * @return array
     * @SuppressWarnings(PHPMD.RequestAwareBlockMethod)
     */
    protected function getLinkParams()
    {
        $order = $this->orderFactory->create()->load($this->_request->getParam('order_id'));
        $data = $this->getUrlKey($order);

        return [
            'type' => 'order_status',
            '_secure' => true,
            '_query' => ['data' => $data, 'signature' => $this->signature->signData($data)],
        ];
    }
}
