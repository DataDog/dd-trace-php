<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\NewRelicReporting\Model\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\NewRelicReporting\Model\Config;

/**
 * Class ReportProductDeleted
 */
class ReportProductDeleted implements ObserverInterface
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var \Magento\NewRelicReporting\Model\SystemFactory
     */
    protected $systemFactory;

    /**
     * @var \Magento\Framework\Json\EncoderInterface
     */
    protected $jsonEncoder;

    /**
     * @param Config $config
     * @param \Magento\NewRelicReporting\Model\SystemFactory $systemFactory
     * @param \Magento\Framework\Json\EncoderInterface $jsonEncoder
     */
    public function __construct(
        Config $config,
        \Magento\NewRelicReporting\Model\SystemFactory $systemFactory,
        \Magento\Framework\Json\EncoderInterface $jsonEncoder
    ) {
        $this->config = $config;
        $this->systemFactory = $systemFactory;
        $this->jsonEncoder = $jsonEncoder;
    }

    /**
     * Reports any products deleted to the database reporting_system_updates table
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if ($this->config->isNewRelicEnabled()) {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $observer->getEvent()->getProduct();

            $jsonData = [
                'id' => $product->getId(),
                'status' => 'deleted'
            ];

            $modelData = [
                'type' => Config::PRODUCT_CHANGE,
                'action' => $this->jsonEncoder->encode($jsonData)
            ];

            /** @var \Magento\NewRelicReporting\Model\System $systemModel */
            $systemModel = $this->systemFactory->create();
            $systemModel->setData($modelData);
            $systemModel->save();
        }
    }
}
