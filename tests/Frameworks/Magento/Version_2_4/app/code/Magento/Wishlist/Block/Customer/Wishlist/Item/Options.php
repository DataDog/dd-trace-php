<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Wishlist\Block\Customer\Wishlist\Item;

/**
 * Wishlist block customer items
 *
 * @api
 * @method \Magento\Wishlist\Model\Item getItem()
 * @since 100.0.2
 */
class Options extends \Magento\Wishlist\Block\AbstractBlock
{
    /**
     * @var \Magento\Catalog\Helper\Product\ConfigurationPool
     */
    protected $_helperPool;

    /**
     * List of product options rendering configurations by product type
     *
     * @var array
     */
    protected $_optionsCfg = [
        'default' => [
            'helper' => \Magento\Catalog\Helper\Product\Configuration::class,
            'template' => 'Magento_Wishlist::options_list.phtml',
        ],
    ];

    /**
     * @param \Magento\Catalog\Block\Product\Context $context
     * @param \Magento\Framework\App\Http\Context $httpContext
     * @param \Magento\Catalog\Helper\Product\ConfigurationPool $helperPool
     * @param array $data
     */
    public function __construct(
        \Magento\Catalog\Block\Product\Context $context,
        \Magento\Framework\App\Http\Context $httpContext,
        \Magento\Catalog\Helper\Product\ConfigurationPool $helperPool,
        array $data = []
    ) {
        $this->_helperPool = $helperPool;
        parent::__construct(
            $context,
            $httpContext,
            $data
        );
    }

    /**
     * Initialize block
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_eventManager->dispatch('product_option_renderer_init', ['block' => $this]);
    }

    /**
     * Adds config for rendering product type options
     *
     * @param string $productType
     * @param string $helperName
     * @param null|string $template
     * @return $this
     */
    public function addOptionsRenderCfg($productType, $helperName, $template = null)
    {
        $this->_optionsCfg[$productType] = ['helper' => $helperName, 'template' => $template];
        return $this;
    }

    /**
     * Get item options renderer config
     *
     * @param string $productType
     * @return array|null
     */
    public function getOptionsRenderCfg($productType)
    {
        if (isset($this->_optionsCfg[$productType])) {
            return $this->_optionsCfg[$productType];
        } elseif (isset($this->_optionsCfg['default'])) {
            return $this->_optionsCfg['default'];
        } else {
            return null;
        }
    }

    /**
     * Retrieve product configured options
     *
     * @return array
     */
    public function getConfiguredOptions()
    {
        $item = $this->getItem();
        $data = $this->getOptionsRenderCfg($item->getProduct()->getTypeId());
        $helper = $this->_helperPool->get($data['helper']);

        $options = $helper->getOptions($item);
        foreach ($options as $index => $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                if (!(array_key_exists('has_html', $option) && $option['has_html'] === true)) {
                    if (is_array($option['value'])) {
                        foreach ($option['value'] as $key => $value) {
                            $option['value'][$key] = $this->escapeHtml($value);
                        }
                    } else {
                        $option['value'] = $this->escapeHtml($option['value'], ["a"]);
                    }
                }
                $options[$index]['value'] = $option['value'];
            }
        }

        return $options;
    }

    /**
     * Retrieve block template
     *
     * @return string
     */
    public function getTemplate()
    {
        $template = parent::getTemplate();
        if ($template) {
            return $template;
        }

        $item = $this->getItem();
        if (!$item) {
            return '';
        }
        $data = $this->getOptionsRenderCfg($item->getProduct()->getTypeId());
        if (empty($data['template'])) {
            $data = $this->getOptionsRenderCfg('default');
        }

        return empty($data['template']) ? '' : $data['template'];
    }

    /**
     * Render block html
     *
     * @return string
     */
    protected function _toHtml()
    {
        $this->setOptionList($this->getConfiguredOptions());

        return parent::_toHtml();
    }
}
