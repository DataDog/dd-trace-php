<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Block\Widget;

use Magento\Backend\Block\Widget\Tab\TabInterface;

/**
 * Tabs widget
 *
 * @api
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 * @since 100.0.2
 */
class Tabs extends \Magento\Backend\Block\Widget
{
    /**
     * Tabs structure
     *
     * @var array
     */
    protected $_tabs = [];

    /**
     * Active tab key
     *
     * @var string
     */
    protected $_activeTab = null;

    /**
     * Destination HTML element id
     *
     * @var string
     */
    protected $_destElementId = 'content';

    /**
     * @var string
     */
    protected $_template = 'Magento_Backend::widget/tabs.phtml';

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    protected $_authSession;

    /**
     * @var \Magento\Framework\Json\EncoderInterface
     */
    private $_jsonEncoder;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Framework\Json\EncoderInterface $jsonEncoder
     * @param \Magento\Backend\Model\Auth\Session $authSession
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Json\EncoderInterface $jsonEncoder,
        \Magento\Backend\Model\Auth\Session $authSession,
        array $data = []
    ) {
        $this->_authSession = $authSession;
        parent::__construct($context, $data);
        $this->_jsonEncoder = $jsonEncoder;
    }

    /**
     * Retrieve destination html element id
     *
     * @return string
     */
    public function getDestElementId()
    {
        return $this->_destElementId;
    }

    /**
     * Set destination element id
     *
     * @param string $elementId
     * @return $this
     */
    public function setDestElementId($elementId)
    {
        $this->_destElementId = $elementId;
        return $this;
    }

    /**
     * Add new tab after another
     *
     * @param   string $tabId new tab Id
     * @param   array|\Magento\Framework\DataObject $tab
     * @param   string $afterTabId
     * @return  void
     */
    public function addTabAfter($tabId, $tab, $afterTabId)
    {
        $this->addTab($tabId, $tab);
        $this->_tabs[$tabId]->setAfter($afterTabId);
    }

    /**
     * Add new tab
     *
     * @param   string $tabId
     * @param   array|\Magento\Framework\DataObject|string $tab
     * @return  $this
     * @throws  \Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function addTab($tabId, $tab)
    {
        if (empty($tabId)) {
            throw new \Exception(__('Please correct the tab configuration and try again. Tab Id should be not empty'));
        }

        if (is_array($tab)) {
            $this->_tabs[$tabId] = new \Magento\Framework\DataObject($tab);
        } elseif ($tab instanceof \Magento\Framework\DataObject) {
            $this->_tabs[$tabId] = $tab;
            if (!$this->_tabs[$tabId]->hasTabId()) {
                $this->_tabs[$tabId]->setTabId($tabId);
            }
        } elseif (is_string($tab)) {
            $this->_addTabByName($tab, $tabId);
            
            if (!$this->_tabs[$tabId] instanceof TabInterface) {
                unset($this->_tabs[$tabId]);
                return $this;
            }
        } else {
            throw new \Exception(__('Please correct the tab configuration and try again.'));
        }
        
        if ($this->_tabs[$tabId]->getUrl() === null) {
            $this->_tabs[$tabId]->setUrl('#');
        }

        if (!$this->_tabs[$tabId]->getTitle()) {
            $this->_tabs[$tabId]->setTitle($this->_tabs[$tabId]->getLabel());
        }

        $this->_tabs[$tabId]->setId($tabId);
        $this->_tabs[$tabId]->setTabId($tabId);
        
        if (true === $this->_tabs[$tabId]->getActive()) {
            $this->setActiveTab($tabId);
        }

        return $this;
    }

    /**
     * Add tab by tab block name
     *
     * @param string $tab
     * @param string $tabId
     * @return void
     * @throws \Exception
     */
    protected function _addTabByName($tab, $tabId)
    {
        if (strpos($tab, '\Block\\') !== false) {
            $this->_tabs[$tabId] = $this->getLayout()->createBlock($tab, $this->getNameInLayout() . '_tab_' . $tabId);
        } elseif ($this->getChildBlock($tab)) {
            $this->_tabs[$tabId] = $this->getChildBlock($tab);
        } else {
            $this->_tabs[$tabId] = null;
        }

        if ($this->_tabs[$tabId] !== null && !$this->_tabs[$tabId] instanceof TabInterface) {
            throw new \Exception(__('Please correct the tab configuration and try again.'));
        }
    }

    /**
     * Get active tab id
     *
     * @return string
     */
    public function getActiveTabId()
    {
        return $this->getTabId($this->_tabs[$this->_activeTab]);
    }

    /**
     * Set Active Tab
     *
     * Tab has to be not hidden and can show
     *
     * @param string $tabId
     * @return $this
     */
    public function setActiveTab($tabId)
    {
        if (isset(
            $this->_tabs[$tabId]
        ) && $this->canShowTab(
            $this->_tabs[$tabId]
        ) && !$this->getTabIsHidden(
            $this->_tabs[$tabId]
        )
        ) {
            $this->_activeTab = $tabId;
            if ($this->_activeTab !== null && $tabId !== $this->_activeTab) {
                foreach ($this->_tabs as $id => $tab) {
                    $tab->setActive($id === $tabId);
                }
            }
        }
        return $this;
    }

    /**
     * Set Active Tab
     *
     * @param string $tabId
     * @return $this
     */
    protected function _setActiveTab($tabId)
    {
        foreach ($this->_tabs as $id => $tab) {
            if ($this->getTabId($tab) == $tabId) {
                $this->_activeTab = $id;
                $tab->setActive(true);
                return $this;
            }
        }
        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function _beforeToHtml()
    {
        $this->_tabs = $this->reorderTabs();
        
        if ($activeTab = $this->getRequest()->getParam('active_tab')) {
            $this->setActiveTab($activeTab);
        } elseif ($activeTabId = $this->_authSession->getActiveTabId()) {
            $this->_setActiveTab($activeTabId);
        }

        if ($this->_activeTab === null && !empty($this->_tabs)) {
            /** @var TabInterface $tab */
            $this->_activeTab = (reset($this->_tabs))->getId();
        }
        
        $this->assign('tabs', $this->_tabs);
        return parent::_beforeToHtml();
    }
    
    /**
     * Reorder the tabs.
     *
     * @return array
     */
    private function reorderTabs()
    {
        $orderByIdentity = [];
        $orderByPosition = [];
        $position        = 100;
    
        /**
         * Set the initial positions for each tab.
         *
         * @var string       $key
         * @var TabInterface $tab
         */
        foreach ($this->_tabs as $key => $tab) {
            $tab->setPosition($position);
    
            $orderByIdentity[$key]      = $tab;
            $orderByPosition[$position] = $tab;
            
            $position += 100;
        }

        return $this->applyTabsCorrectOrder($orderByPosition, $orderByIdentity);
    }

    /**
     * Apply tabs order
     *
     * @param array $orderByPosition
     * @param array $orderByIdentity
     *
     * @return array
     */
    private function applyTabsCorrectOrder(array $orderByPosition, array $orderByIdentity)
    {
        $positionFactor = 1;

        /**
         * Rearrange the positions by using the after tag for each tab.
         *
         * @var int $position
         * @var TabInterface $tab
         */
        foreach ($orderByPosition as $position => $tab) {
            if (!$tab->getAfter() || !in_array($tab->getAfter(), array_keys($orderByIdentity))) {
                $positionFactor = 1;
                continue;
            }

            $grandPosition = $orderByIdentity[$tab->getAfter()]->getPosition();
            $newPosition   = $grandPosition + $positionFactor;

            unset($orderByPosition[$position]);
            $orderByPosition[$newPosition] = $tab;
            $tab->setPosition($newPosition);

            $positionFactor++;
        }

        return $this->finalTabsSortOrder($orderByPosition);
    }

    /**
     * Apply the last sort order to tabs.
     *
     * @param array $orderByPosition
     *
     * @return array
     */
    private function finalTabsSortOrder(array $orderByPosition)
    {
        ksort($orderByPosition);

        $ordered = [];

        /** @var TabInterface $tab */
        foreach ($orderByPosition as $tab) {
            $ordered[$tab->getId()] = $tab;
        }

        return $ordered;
    }
    
    /**
     * Get js object name
     *
     * @return string
     */
    public function getJsObjectName()
    {
        return $this->getId() . 'JsTabs';
    }

    /**
     * Get tabs ids
     *
     * @return string[]
     */
    public function getTabsIds()
    {
        if (empty($this->_tabs)) {
            return [];
        }

        return array_keys($this->_tabs);
    }

    /**
     * Get tab id
     *
     * @param \Magento\Framework\DataObject|TabInterface $tab
     * @param bool $withPrefix
     * @return string
     */
    public function getTabId($tab, $withPrefix = true)
    {
        if ($tab instanceof TabInterface) {
            return ($withPrefix ? $this->getId() . '_' : '') . $tab->getTabId();
        }
        return ($withPrefix ? $this->getId() . '_' : '') . $tab->getId();
    }

    /**
     * CVan show tab
     *
     * @param \Magento\Framework\DataObject|TabInterface $tab
     * @return bool
     */
    public function canShowTab($tab)
    {
        if ($tab instanceof TabInterface) {
            return $tab->canShowTab();
        }
        return true;
    }

    /**
     * Get tab is hidden
     *
     * @param \Magento\Framework\DataObject|TabInterface $tab
     * @return bool
     * @SuppressWarnings(PHPMD.BooleanGetMethodName)
     */
    public function getTabIsHidden($tab)
    {
        if ($tab instanceof TabInterface) {
            return $tab->isHidden();
        }
        return $tab->getIsHidden();
    }

    /**
     * Get tab url
     *
     * @param \Magento\Framework\DataObject|TabInterface $tab
     * @return string
     */
    public function getTabUrl($tab)
    {
        if ($tab instanceof TabInterface) {
            if (method_exists($tab, 'getTabUrl')) {
                return $tab->getTabUrl();
            }
            return '#';
        }
        if ($tab->getUrl() !== null) {
            return $tab->getUrl();
        }
        return '#';
    }

    /**
     * Get tab title
     *
     * @param \Magento\Framework\DataObject|TabInterface $tab
     * @return string
     */
    public function getTabTitle($tab)
    {
        if ($tab instanceof TabInterface) {
            return $tab->getTabTitle();
        }
        return $tab->getTitle();
    }

    /**
     * Get tab class
     *
     * @param \Magento\Framework\DataObject|TabInterface $tab
     * @return string
     */
    public function getTabClass($tab)
    {
        if ($tab instanceof TabInterface) {
            if (method_exists($tab, 'getTabClass')) {
                return $tab->getTabClass();
            }
            return '';
        }
        return $tab->getClass();
    }

    /**
     * Get tab label
     *
     * @param \Magento\Framework\DataObject|TabInterface $tab
     * @return string
     */
    public function getTabLabel($tab)
    {
        if ($tab instanceof TabInterface) {
            return $tab->getTabLabel();
        }
        return $tab->getLabel();
    }

    /**
     * Get tab content
     *
     * @param \Magento\Framework\DataObject|TabInterface $tab
     * @return string
     */
    public function getTabContent($tab)
    {
        if ($tab instanceof TabInterface) {
            if ($tab->getSkipGenerateContent()) {
                return '';
            }
            return $tab->toHtml();
        }
        return $tab->getContent();
    }

    /**
     * Mark tabs as dependent of each other
     *
     * Arbitrary number of tabs can be specified, but at least two
     *
     * @param string $tabOneId
     * @param string $tabTwoId
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function bindShadowTabs($tabOneId, $tabTwoId)
    {
        $tabs = [];
        $args = func_get_args();
        if (!empty($args) && count($args) > 1) {
            foreach ($args as $tabId) {
                if (isset($this->_tabs[$tabId])) {
                    $tabs[$tabId] = $tabId;
                }
            }
            $blockId = $this->getId();
            foreach ($tabs as $tabId) {
                foreach ($tabs as $tabToId) {
                    if ($tabId !== $tabToId) {
                        if (!$this->_tabs[$tabToId]->getData('shadow_tabs')) {
                            $this->_tabs[$tabToId]->setData('shadow_tabs', []);
                        }
                        $this->_tabs[$tabToId]->setData(
                            'shadow_tabs',
                            array_merge($this->_tabs[$tabToId]->getData('shadow_tabs'), [$blockId . '_' . $tabId])
                        );
                    }
                }
            }
        }
    }

    /**
     * Obtain shadow tabs information
     *
     * @param bool $asJson
     * @return array|string
     */
    public function getAllShadowTabs($asJson = true)
    {
        $result = [];
        if (!empty($this->_tabs)) {
            $blockId = $this->getId();
            foreach (array_keys($this->_tabs) as $tabId) {
                if ($this->_tabs[$tabId]->getData('shadow_tabs')) {
                    $result[$blockId . '_' . $tabId] = $this->_tabs[$tabId]->getData('shadow_tabs');
                }
            }
        }
        if ($asJson) {
            return $this->_jsonEncoder->encode($result);
        }
        return $result;
    }

    /**
     * Set tab property by tab's identifier
     *
     * @param string $tab
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setTabData($tab, $key, $value)
    {
        if (isset($this->_tabs[$tab]) && $this->_tabs[$tab] instanceof \Magento\Framework\DataObject) {
            if ($key == 'url') {
                $value = $this->getUrl($value, ['_current' => true, '_use_rewrite' => true]);
            }
            $this->_tabs[$tab]->setData($key, $value);
        }

        return $this;
    }

    /**
     * Removes tab with passed id from tabs block
     *
     * @param string $tabId
     * @return $this
     */
    public function removeTab($tabId)
    {
        if (isset($this->_tabs[$tabId])) {
            unset($this->_tabs[$tabId]);
        }
        return $this;
    }
}
