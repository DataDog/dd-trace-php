<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\ViewModel\Product;

use Magento\Catalog\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\Escaper;

/**
 * Product breadcrumbs view model.
 */
class Breadcrumbs extends DataObject implements ArgumentInterface
{
    /**
     * Catalog data.
     *
     * @var Data
     */
    private $catalogData;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @param Data $catalogData
     * @param ScopeConfigInterface $scopeConfig
     * @param Json|null $json
     * @param Escaper|null $escaper
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        Data $catalogData,
        ScopeConfigInterface $scopeConfig,
        Json $json = null,
        Escaper $escaper = null
    ) {
        parent::__construct();

        $this->catalogData = $catalogData;
        $this->scopeConfig = $scopeConfig;
        $this->escaper = $escaper ?: ObjectManager::getInstance()->get(Escaper::class);
    }

    /**
     * Returns category URL suffix.
     *
     * @return mixed
     */
    public function getCategoryUrlSuffix()
    {
        return $this->scopeConfig->getValue(
            'catalog/seo/category_url_suffix',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Checks if categories path is used for product URLs.
     *
     * @return bool
     */
    public function isCategoryUsedInProductUrl(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'catalog/seo/product_use_categories',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Returns product name.
     *
     * @return string
     */
    public function getProductName(): string
    {
        return $this->catalogData->getProduct() !== null
            ? $this->catalogData->getProduct()->getName()
            : '';
    }

    /**
     * Returns breadcrumb json with html escaped names
     *
     * @return string
     */
    public function getJsonConfigurationHtmlEscaped() : string
    {
        return json_encode(
            [
                'breadcrumbs' => [
                    'categoryUrlSuffix' => $this->escaper->escapeHtml($this->getCategoryUrlSuffix()),
                    'useCategoryPathInUrl' => (int)$this->isCategoryUsedInProductUrl(),
                    'product' => $this->escaper->escapeHtml($this->getProductName())
                ]
            ],
            JSON_HEX_TAG
        );
    }

    /**
     * Returns breadcrumb json.
     *
     * @return string
     * @deprecated 102.0.11 in favor of new method with name {suffix}Html{postfix}()
     */
    public function getJsonConfiguration()
    {
        return $this->getJsonConfigurationHtmlEscaped();
    }
}
