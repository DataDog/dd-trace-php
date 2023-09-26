<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\PageCache\Block\System\Config\Form\Field;

/**
 * Class Export
 *
 * @api
 * @since 100.0.2
 */
class Export extends \Magento\Config\Block\System\Config\Form\Field
{
    /**
     * Retrieve element HTML markup
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        /** @var \Magento\Backend\Block\Widget\Button $buttonBlock  */
        $buttonBlock = $this->getForm()->getLayout()->createBlock(\Magento\Backend\Block\Widget\Button::class);

        $params = [
            'website' => $buttonBlock->getRequest()->getParam('website'),
            'varnish' => $this->getVarnishVersion()
        ];

        $data = [
            'id' => 'system_full_page_cache_varnish_export_button_version' . $this->getVarnishVersion(),
            'label' => $this->getLabel(),
            'onclick' => "setLocation('" . $this->getVarnishUrl($params) . "')",
        ];

        $html = $buttonBlock->setData($data)->toHtml();
        return $html;
    }

    /**
     * Return Varnish version to this class
     *
     * @return int
     */
    public function getVarnishVersion()
    {
        return 0;
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    private function getLabel()
    {
        return  __('Export VCL for Varnish %1', $this->getVarnishVersion());
    }

    /**
     * @param array $params
     *
     * @return string
     */
    private function getVarnishUrl($params = [])
    {
        return $this->getUrl('*/PageCache/exportVarnishConfig', $params);
    }

    /**
     * Return PageCache TTL value from config
     * to avoid saving empty field
     *
     * @return string
     * @deprecated 100.1.0
     */
    public function getTtlValue()
    {
        return $this->_scopeConfig->getValue(\Magento\PageCache\Model\Config::XML_PAGECACHE_TTL);
    }
}
