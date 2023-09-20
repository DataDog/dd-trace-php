<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Block\Adminhtml\Edit\Tab\Newsletter\Grid\Renderer;

/**
 * Adminhtml newsletter queue grid block action item renderer
 */
class Action extends \Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer
{
    /**
     * @var \Magento\Framework\Escaper
     */
    private $escaper;

    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry = null;

    /**
     * @param \Magento\Backend\Block\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param array $data
     * @param \Magento\Framework\Escaper|null $escaper
     */
    public function __construct(
        \Magento\Backend\Block\Context $context,
        \Magento\Framework\Registry $registry,
        array $data = [],
        \Magento\Framework\Escaper $escaper = null
    ) {
        $this->_coreRegistry = $registry;
        $this->escaper = $escaper ?? \Magento\Framework\App\ObjectManager::getInstance()->get(
            \Magento\Framework\Escaper::class
        );
        parent::__construct($context, $data);
    }

    /**
     * Render actions
     *
     * @param \Magento\Framework\DataObject $row
     * @return string
     */
    public function render(\Magento\Framework\DataObject $row)
    {
        $actions = [];

        $actions[] = [
            '@' => [
                'href' => $this->getUrl(
                    'newsletter/template/preview',
                    [
                        'id' => $row->getTemplateId(),
                        'subscriber' => $this->_coreRegistry->registry('subscriber')->getId()
                    ]
                ),
                'target' => '_blank',
            ],
            '#' => __('View'),
        ];

        return $this->_actionsToHtml($actions);
    }

    /**
     * Retrieve escaped value
     *
     * @param string $value
     * @return string
     */
    protected function _getEscapedValue($value)
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return addcslashes($this->escaper->escapeHtml($value), '\\\'');
    }

    /**
     * Actions to html
     *
     * @param array $actions
     * @return string
     */
    protected function _actionsToHtml(array $actions)
    {
        $html = [];
        $attributesObject = new \Magento\Framework\DataObject();
        foreach ($actions as $action) {
            $attributesObject->setData($action['@']);
            $html[] = '<a ' . $attributesObject->serialize() . '>' . $action['#'] . '</a>';
        }
        return implode('<span class="separator">&nbsp;|&nbsp;</span>', $html);
    }
}
