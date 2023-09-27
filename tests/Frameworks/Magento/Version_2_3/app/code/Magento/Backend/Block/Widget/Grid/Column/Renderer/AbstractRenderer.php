<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Block\Widget\Grid\Column\Renderer;

use Magento\Backend\Block\Widget\Grid\Column;
use Magento\Framework\DataObject;

/**
 * Backend grid item abstract renderer
 * @api
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 * @api
 * @since 100.0.2
 */
abstract class AbstractRenderer extends \Magento\Backend\Block\AbstractBlock implements RendererInterface
{
    /**
     * @var int
     */
    protected $_defaultWidth;

    /**
     * @var Column
     */
    protected $_column;

    /**
     * Set column for renderer.
     *
     * @param Column $column
     * @return $this
     */
    public function setColumn($column)
    {
        $this->_column = $column;
        return $this;
    }

    /**
     * Returns row associated with the renderer.
     *
     * @return Column
     */
    public function getColumn()
    {
        return $this->_column;
    }

    /**
     * Renders grid column
     *
     * @param DataObject $row
     * @return  string
     */
    public function render(DataObject $row)
    {
        if ($this->getColumn()->getEditable()) {
            $result = '<div class="admin__grid-control">';
            $result .= $this->getColumn()->getEditOnly() ? ''
                : '<span class="admin__grid-control-value">' . $this->_getValue($row) . '</span>';

            return $result . $this->_getInputValueElement($row) . '</div>' ;
        }
        return $this->_getValue($row);
    }

    /**
     * Render column for export
     *
     * @param DataObject $row
     * @return string
     */
    public function renderExport(DataObject $row)
    {
        return $this->render($row);
    }

    /**
     * Returns value of the row.
     *
     * @param DataObject $row
     * @return mixed
     */
    protected function _getValue(DataObject $row)
    {
        if ($getter = $this->getColumn()->getGetter()) {
            if (is_string($getter)) {
                return $row->{$getter}();
            } elseif (is_callable($getter)) {
                return call_user_func($getter, $row);
            }
            return '';
        }
        return $row->getData($this->getColumn()->getIndex());
    }

    /**
     * Get pre-rendered input element.
     *
     * @param DataObject $row
     * @return string
     */
    public function _getInputValueElement(DataObject $row)
    {
        return '<input type="text" class="input-text ' .
            $this->getColumn()->getValidateClass() .
            '" name="' .
            $this->getColumn()->getId() .
            '" value="' .
            $this->_getInputValue(
                $row
            ) . '"/>';
    }

    /**
     * Get input value by row.
     *
     * @param DataObject $row
     * @return mixed
     */
    protected function _getInputValue(DataObject $row)
    {
        return $this->_getValue($row);
    }

    /**
     * Renders header of the column,
     *
     * @return string
     */
    public function renderHeader()
    {
        if (false !== $this->getColumn()->getSortable()) {
            $className = 'not-sort';
            $dir = strtolower($this->getColumn()->getDir());
            $nDir = $dir == 'asc' ? 'desc' : 'asc';
            if ($this->getColumn()->getDir()) {
                $className = '_' . $dir . 'end';
            }
            $out = '<th data-sort="' .
                $this->getColumn()->getId() .
                '" data-direction="' .
                $nDir .
                '" class="data-grid-th _sortable ' .
                $className . ' ' .
                $this->getColumn()->getHeaderCssClass() .
                '"><span>' .
                $this->getColumn()->getHeader() .
                '</span></th>';
        } else {
            $out = '<th class="data-grid-th ' .
                $this->getColumn()->getHeaderCssClass() . '"><span>' .
                $this->getColumn()->getHeader() .
                '</span></th>';
        }
        return $out;
    }

    /**
     * Render HTML properties.
     *
     * @return string
     */
    public function renderProperty()
    {
        $out = '';
        $width = $this->_defaultWidth;

        if ($this->getColumn()->hasData('width')) {
            $customWidth = $this->getColumn()->getData('width');
            if (null === $customWidth || preg_match('/^[0-9]+%?$/', $customWidth)) {
                $width = $customWidth;
            } elseif (preg_match('/^([0-9]+)px$/', $customWidth, $matches)) {
                $width = (int)$matches[1];
            }
        }

        if (null !== $width) {
            $out .= ' width="' . $width . '"';
        }

        return $out;
    }

    /**
     * Returns HTML for CSS.
     *
     * @return string
     */
    public function renderCss()
    {
        return $this->getColumn()->getCssClass();
    }
}
