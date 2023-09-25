<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Css\PreProcessor\Adapter;

use Magento\Framework\App\State;
use Pelago\Emogrifier;

/**
 * This class will inline the css of an html to each tag to be used for applications such as a styled email.
 */
class CssInliner
{
    /**
     * @var Emogrifier
     */
    private $emogrifier;

    /**
     * @param State $appState
     */
    public function __construct(State $appState)
    {
        $this->emogrifier = new Emogrifier();
        $this->emogrifier->setDebug($appState->getMode() === State::MODE_DEVELOPER);
    }

    /**
     * Sets the HTML to be used with the css. This method should be used with setCss.
     *
     * @param string $html
     * @return void
     */
    public function setHtml($html)
    {
        $this->emogrifier->setHtml($html);
    }

    /**
     * Sets the CSS to be merged with the HTML. This method should be used with setHtml.
     *
     * @param string $css
     * @return void
     */
    public function setCss($css)
    {
        $this->emogrifier->setCss($css);
    }

    /**
     * Disables the parsing of <style> blocks.
     *
     * @return void
     */
    public function disableStyleBlocksParsing()
    {
        $this->emogrifier->disableStyleBlocksParsing();
    }

    /**
     * Processes the html by placing the css inline. Set first the css by using setCss and html by using setHtml.
     *
     * @return string
     * @throws \BadMethodCallException
     */
    public function process()
    {
        return $this->emogrifier->emogrify();
    }
}
