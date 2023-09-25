<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Rss\Block;

/**
 * Class Feeds
 * @api
 * @package Magento\Rss\Block
 * @since 100.0.2
 */
class Feeds extends \Magento\Framework\View\Element\Template
{
    /**
     * @var string
     */
    protected $_template = 'Magento_Rss::feeds.phtml';

    /**
     * @var \Magento\Framework\App\Rss\RssManagerInterface
     */
    protected $rssManager;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Framework\App\Rss\RssManagerInterface $rssManager
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\App\Rss\RssManagerInterface $rssManager,
        array $data = []
    ) {
        $this->rssManager = $rssManager;
        parent::__construct($context, $data);
    }

    /**
     * Add Link elements to head
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        $head = $this->getLayout()->getBlock('head');
        $feeds = $this->getFeeds();
        if ($head && !empty($feeds)) {
            foreach ($feeds as $feed) {
                if (!isset($feed['group'])) {
                    $head->addRss($feed['label'], $feed['link']);
                } else {
                    foreach ($feed['feeds'] as $item) {
                        $head->addRss($item['label'], $item['link']);
                    }
                }
            }
        }
        return parent::_prepareLayout();
    }

    /**
     * @return array
     */
    public function getFeeds()
    {
        $providers = $this->rssManager->getProviders();
        $feeds = [];
        $groups = [];
        foreach ($providers as $provider) {
            $item = $provider->getFeeds();
            if (empty($item)) {
                continue;
            }

            if (isset($item['group'])) {
                $groups[] = $item;
            } else {
                $feeds[] = $item;
            }
        }
        $feeds = array_merge($feeds, $groups);

        return $feeds;
    }
}
