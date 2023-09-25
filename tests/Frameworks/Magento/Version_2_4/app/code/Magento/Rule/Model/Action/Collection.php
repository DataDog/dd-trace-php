<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Rule\Model\Action;

/**
 * Collections
 *
 * @api
 * @since 100.0.2
 */
class Collection extends AbstractAction
{
    /**
     * @var \Magento\Rule\Model\ActionFactory
     */
    protected $_actionFactory;

    /**
     * @param \Magento\Framework\View\Asset\Repository $assetRepo
     * @param \Magento\Framework\View\LayoutInterface $layout
     * @param \Magento\Rule\Model\ActionFactory $actionFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\View\LayoutInterface $layout,
        \Magento\Rule\Model\ActionFactory $actionFactory,
        array $data = []
    ) {
        $this->_actionFactory = $actionFactory;
        $this->_layout = $layout;

        parent::__construct($assetRepo, $layout, $data);

        $this->setActions([]);
        $this->setType(\Magento\Rule\Model\Action\Collection::class);
    }

    /**
     * Returns array containing actions in the collection
     *
     * Output example:
     * array(
     *   {action::asArray},
     *   {action::asArray}
     * )
     *
     * @param array $arrAttributes
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function asArray(array $arrAttributes = [])
    {
        $out = parent::asArray();

        foreach ($this->getActions() as $item) {
            $out['actions'][] = $item->asArray();
        }
        return $out;
    }

    /**
     * Load array
     *
     * @param array $arr
     * @return $this
     */
    public function loadArray(array $arr)
    {
        if (!empty($arr['actions']) && is_array($arr['actions'])) {
            foreach ($arr['actions'] as $actArr) {
                if (empty($actArr['type'])) {
                    continue;
                }
                $action = $this->_actionFactory->create($actArr['type']);
                $action->loadArray($actArr);
                $this->addAction($action);
            }
        }
        return $this;
    }

    /**
     * Add actions
     *
     * @param ActionInterface $action
     * @return $this
     */
    public function addAction(ActionInterface $action)
    {
        $actions = $this->getActions();

        $action->setRule($this->getRule());

        $actions[] = $action;
        if (!$action->getId()) {
            $action->setId($this->getId() . '.' . count($actions));
        }

        $this->setActions($actions);
        return $this;
    }

    /**
     * As html
     *
     * @return string
     */
    public function asHtml()
    {
        $html = $this->getTypeElement()->toHtml() . 'Perform following actions: ';
        if ($this->getId() != '1') {
            $html .= $this->getRemoveLinkHtml();
        }
        return $html;
    }

    /**
     * Return new child element
     *
     * @return $this
     */
    public function getNewChildElement()
    {
        return $this->getForm()->addField(
            'action:' . $this->getId() . ':new_child',
            'select',
            [
                'name' => $this->elementName . '[actions][' . $this->getId() . '][new_child]',
                'values' => $this->getNewChildSelectOptions(),
                'value_name' => $this->getNewChildName()
            ]
        )->setRenderer(
            $this->_layout->getBlockSingleton(\Magento\Rule\Block\Newchild::class)
        );
    }

    /**
     * Return as html recursive
     *
     * @return string
     */
    public function asHtmlRecursive()
    {
        $html = $this->asHtml() . '<ul id="action:' . $this->getId() . ':children">';
        foreach ($this->getActions() as $cond) {
            $html .= '<li>' . $cond->asHtmlRecursive() . '</li>';
        }
        $html .= '<li>' . $this->getNewChildElement()->getHtml() . '</li></ul>';
        return $html;
    }

    /**
     * Add string
     *
     * @param string $format
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function asString($format = '')
    {
        $str = __("Perform following actions");
        return $str;
    }

    /**
     * Return string as recursive
     *
     * @param int $level
     * @return string
     */
    public function asStringRecursive($level = 0)
    {
        $str = $this->asString();
        foreach ($this->getActions() as $action) {
            $str .= "\n" . $action->asStringRecursive($level + 1);
        }
        return $str;
    }

    /**
     * Process
     *
     * @return $this
     */
    public function process()
    {
        foreach ($this->getActions() as $action) {
            $action->process();
        }
        return $this;
    }
}
