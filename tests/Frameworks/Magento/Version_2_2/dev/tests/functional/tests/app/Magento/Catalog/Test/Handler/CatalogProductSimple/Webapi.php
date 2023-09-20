<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Test\Handler\CatalogProductSimple;

use Magento\Catalog\Test\Fixture\CatalogProductSimple;
use Magento\Mtf\Config\DataInterface;
use Magento\Mtf\Fixture\FixtureInterface;
use Magento\Mtf\Fixture\InjectableFixture;
use Magento\Mtf\System\Event\EventManagerInterface;
use Magento\Mtf\Util\Protocol\CurlInterface;
use Magento\Mtf\Util\Protocol\CurlTransport\WebapiDecorator;
use Magento\Mtf\Handler\Webapi as AbstractWebapi;

/**
 * Create new simple product via webapi.
 */
class Webapi extends AbstractWebApi implements CatalogProductSimpleInterface
{
    /**
     * Fixture instance.
     *
     * @var InjectableFixture
     */
    protected $fixture;

    /**
     * Prepared fields.
     *
     * @var array
     */
    protected $fields;

    /**
     * Product Curl handler instance.
     *
     * @var Curl
     */
    protected $handlerCurl;

    /**
     * List basic fields of product.
     *
     * @var array
     */
    protected $basicFields = [
        'sku',
        'name',
        'store_id',
        'attribute_set_id',
        'price',
        'status',
        'visibility',
        'type_id',
        'weight',
        'product_links',
        'options',
        'media_gallery_entries',
        'tier_prices',
        'extension_attributes',
        'custom_attributes'
    ];

    /**
     * Temporary media path.
     *
     * @var string
     */
    private $mediaPathTmp = '/pub/media/tmp/catalog/product/';

    /**
     * @constructor
     * @param DataInterface $configuration
     * @param EventManagerInterface $eventManager
     * @param WebapiDecorator $webapiTransport
     * @param Curl $handlerCurl
     */
    public function __construct(
        DataInterface $configuration,
        EventManagerInterface $eventManager,
        WebapiDecorator $webapiTransport,
        Curl $handlerCurl
    ) {
        parent::__construct($configuration, $eventManager, $webapiTransport);
        $this->handlerCurl = $handlerCurl;
    }

    /**
     * Webapi request for creating simple product.
     *
     * @param FixtureInterface|null $fixture [optional]
     * @return array
     * @throws \Exception
     */
    public function persist(FixtureInterface $fixture = null)
    {
        $this->fixture = $fixture;
        $this->fields = $this->handlerCurl->prepareData($this->fixture);
        $this->prepareData();
        $this->convertData();

        /** @var CatalogProductSimple $fixture */
        $url = $_ENV['app_frontend_url'] . 'rest/all/V1/products';
        $this->webapiTransport->write($url, $this->fields, CurlInterface::POST);
        $encodedResponse = $this->webapiTransport->read();
        $response = json_decode($encodedResponse, true);
        $this->webapiTransport->close();

        if (!isset($response['id'])) {
            $this->eventManager->dispatchEvent(['webapi_failed'], [$response]);
            throw new \Exception("Product creation by webapi handler was not successful! Response: {$encodedResponse}");
        }

        $this->updateProduct($fixture);
        return $this->parseResponse($response);
    }

    /**
     * Update product info per website.
     *
     * @param FixtureInterface $fixture
     * @return void
     * @throws \Exception
     */
    private function updateProduct(FixtureInterface $fixture)
    {
        if (isset($fixture->getData()['website_data'])) {
            $websiteData = $fixture->getData()['website_data'];
            foreach ($fixture->getDataFieldConfig('website_ids')['source']->getStores() as $key => $store) {
                $url = $_ENV['app_frontend_url'] . 'rest/' . $store->getCode() . '/V1/products/' . $fixture->getSku();
                $this->webapiTransport->write($url, ['product' => $websiteData[$key]], CurlInterface::PUT);
                $encodedResponse = $this->webapiTransport->read();
                $response = json_decode($encodedResponse, true);
                $this->webapiTransport->close();

                if (!isset($response['id'])) {
                    $this->eventManager->dispatchEvent(['webapi_failed'], [$response]);
                    throw new \Exception(
                        "Product update by webapi handler was not successful! Response: {$encodedResponse}"
                    );
                }
            }
        }
    }

    /**
     * Prepare data for creating product request.
     *
     * @return void
     */
    protected function prepareData()
    {
        $config = $this->fixture->getDataConfig();

        $this->fields['product']['type_id'] = $config['type_id'];
        $this->prepareFpt();
        $this->prepareAdvancedInventory();
        $this->prepareTierPrice();
        $this->prepareCustomOptions();
        $this->prepareMediaGallery();
        $this->prepareSpecialPrice();
    }

    /**
     * Convert prepared data to webapi structure request.
     *
     * @return void
     */
    protected function convertData()
    {
        $fields = [];

        unset($this->fields['product']['website_ids']);
        unset($this->fields['product']['checkout_data']);

        foreach ($this->basicFields as $fieldName) {
            if (isset($this->fields['product'][$fieldName])) {
                $fields['product'][$fieldName] = $this->fields['product'][$fieldName];
            }
        }
        $this->fields['product'] = array_diff_key($this->fields['product'], array_flip($this->basicFields));

        foreach ($this->fields['product'] as $attribute => $value) {
            $fields['product']['custom_attributes'][] = [
                'attribute_code' => $attribute,
                'value' => $value
            ];
        }

        $this->fields = $fields;
    }

    /**
     * Parse data in response.
     *
     * @param array $response
     * @return array
     */
    protected function parseResponse(array $response)
    {
        return ['id' => $response['id']];
    }

    /**
     * Preparation of fpt attribute data.
     *
     * @return void
     */
    protected function prepareFpt()
    {
        if ($this->fixture->hasData('fpt')) {
            $fptLabel = $this->fixture->getDataFieldConfig('attribute_set_id')['source']
                ->getAttributeSet()->getDataFieldConfig('assigned_attributes')['source']
                ->getAttributes()[0]->getFrontendLabel();
            $fptValues = $this->fields['product'][$fptLabel];

            foreach ($fptValues as $key => $item) {
                $item['value'] = $item['price'];
                unset($item['price']);

                $fptValues[$key] = $item;
            }

            $this->fields['product']['custom_attributes'][] = [
                'attribute_code' => $fptLabel,
                'value' => $fptValues
            ];
            unset($this->fields['product'][$fptLabel]);
        }
    }

    /**
     * Preparation of "Advanced Inventory" tab data.
     *
     * @return void
     */
    protected function prepareAdvancedInventory()
    {
        $stockData = $this->fields['product']['stock_data'];

        if (!isset($stockData['is_in_stock'])) {
            $stockData['is_in_stock'] = isset($this->fields['product']['quantity_and_stock_status']['is_in_stock'])
                ? $this->fields['product']['quantity_and_stock_status']['is_in_stock']
                : false;
        }
        if (!isset($stockData['qty']) && isset($this->fields['product']['quantity_and_stock_status']['qty'])) {
            $stockData['qty'] = $this->fields['product']['quantity_and_stock_status']['qty'];
        }

        if (isset($stockData['use_config_enable_qty_increments'])) {
            $stockData['use_config_enable_qty_inc'] = $stockData['use_config_enable_qty_increments'];
            unset($stockData['use_config_enable_qty_increments']);
        }

        $this->fields['product']['extension_attributes']['stock_item'] = $stockData;
        unset($this->fields['product']['stock_data']);
    }

    /**
     * Preparation of tier price data.
     *
     * @return void
     */
    protected function prepareTierPrice()
    {
        if (isset($this->fields['product']['tier_price'])) {
            $this->fields['product']['tier_prices'] = $this->fields['product']['tier_price'];
            unset($this->fields['product']['tier_price']);

            foreach ($this->fields['product']['tier_prices'] as $key => $priceInfo) {
                $priceInfo['customer_group_id'] = $priceInfo['cust_group'];
                unset($priceInfo['cust_group']);

                if (isset($priceInfo['price'])) {
                    $priceInfo['value'] = $priceInfo['price'];
                    unset($priceInfo['price']);
                }
                unset($priceInfo['value_type']);

                if (isset($priceInfo['percentage_value'])) {
                    $priceInfo['extension_attributes']['percentage_value'] = $priceInfo['percentage_value'];
                    unset($priceInfo['percentage_value']);
                }

                $priceInfo['qty'] = $priceInfo['price_qty'];
                unset($priceInfo['price_qty']);

                if (isset($priceInfo['website_id'])) {
                    $priceInfo['extension_attributes']['website_id'] = $priceInfo['website_id'];
                    unset($priceInfo['website_id']);
                }

                unset($priceInfo['delete']);

                $this->fields['product']['tier_prices'][$key] = $priceInfo;
            }
        }
    }

    /**
     * Preparation of product special price data.
     *
     * @return void
     */
    protected function prepareSpecialPrice()
    {
        if (isset($this->fields['product']['special_from_date'])) {
            $this->fields['product']['special_from_date'] = date(
                'n/j/Y',
                strtotime($this->fields['product']['special_from_date'])
            );
        }
        if (isset($this->fields['product']['special_to_date'])) {
            $this->fields['product']['special_to_date'] = date(
                'n/j/Y',
                strtotime($this->fields['product']['special_to_date'])
            );
        }
    }

    /**
     * Preparation of "Custom Options" tab data.
     *
     * @return void
     */
    protected function prepareCustomOptions()
    {
        if (isset($this->fields['product']['options'])) {
            foreach ($this->fields['product']['options'] as $ko => $option) {
                $option['product_sku'] = $this->fields['product']['sku'];

                if (isset($option['values'])) {
                    foreach ($option['values'] as $kv => $value) {
                        unset($value['is_delete']);
                        $option['values'][$kv] = $value;
                    }
                }

                unset($option['option_id']);
                unset($option['is_delete']);

                $this->fields['product']['options'][$ko] = $option;
            }
        }
    }

    /**
     * Create test image file.
     *
     * @return void
     */
    protected function prepareMediaGallery()
    {
        if (isset($this->fields['product']['media_gallery'])) {
            foreach ($this->fields['product']['media_gallery']['images'] as $galleryItem) {
                $filename = $galleryItem['file'];
                $this->fields['product']['media_gallery_entries'][] = $this->getImageData($filename);
            }
            unset($this->fields['product']['media_gallery']);
        }
    }

    /**
     * Get media gallery data.
     *
     * @param $filename
     * @return array
     */
    private function getImageData($filename)
    {
        return
            [
                'position' => 1,
                'media_type' => 'image',
                'disabled' => false,
                'label' => $filename,
                'types' => [],
                'content' => [
                    'type' => 'image/jpeg',
                    'name' => $filename,
                    'base64_encoded_data' => base64_encode(file_get_contents(BP . $this->mediaPathTmp . $filename)),
                ]
            ];
    }
}
