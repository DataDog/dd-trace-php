<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Filter\Template;

use Laminas\Filter\FilterInterface;

class Simple extends \Magento\Framework\DataObject implements FilterInterface
{
    /**
     * @var string
     */
    protected $_startTag = '{{';

    /**
     * @var string
     */
    protected $_endTag = '}}';

    /**
     * Set tags
     *
     * @param string $start
     * @param string $end
     * @return $this
     */
    public function setTags($start, $end)
    {
        $this->_startTag = $start;
        $this->_endTag = $end;
        return $this;
    }

    /**
     * @inheritdoc
     *
     * @param string $value
     * @return string
     */
    public function filter($value)
    {
        $callback = function ($matches) {
            return $this->getData($matches[1]);
        };
        $expression = '#' . preg_quote($this->_startTag, '#') . '(.*?)' . preg_quote($this->_endTag, '#') . '#';
        return preg_replace_callback($expression, $callback, $value);
    }
}
