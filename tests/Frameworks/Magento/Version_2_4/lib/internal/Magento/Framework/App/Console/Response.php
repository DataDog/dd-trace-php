<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\App\Console;

/**
 * HTTP response implementation.
 */
class Response implements \Magento\Framework\App\ResponseInterface
{
    /**
     * Status code
     * Possible values:
     *  0 (successfully)
     *  1-255 (error)
     *  -1 (error)
     *
     * @var int
     */
    protected $code = 0;

    /**
     * Success code
     */
    const SUCCESS = 0;

    /**
     * Error code
     */
    const ERROR = 255;

    /**
     * Text to output on send response
     *
     * @var string
     */
    private $body;

    /**
     * Set whether to terminate process on send or not
     *
     * @var bool
     */
    protected $terminateOnSend = true;

    /**
     * Send response to client
     *
     * @return int
     */
    public function sendResponse()
    {
        if (!empty($this->body)) {
            // phpcs:ignore Magento2.Security.LanguageConstruct.DirectOutput
            echo $this->body;
        }
        if ($this->terminateOnSend) {
            // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
            exit($this->code);
        }
        return $this->code;
    }

    /**
     * Get body
     *
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Set body
     *
     * @param string $body
     * @return void
     */
    public function setBody($body)
    {
        $this->body = $body;
    }

    /**
     * Set exit code
     *
     * @param int $code
     * @return void
     */
    public function setCode($code)
    {
        if ($code > 255) {
            $code = 255;
        }
        $this->code = $code;
    }

    /**
     * Set whether to terminate process on send or not
     *
     * @param bool $terminate
     * @return void
     */
    public function terminateOnSend($terminate)
    {
        $this->terminateOnSend = $terminate;
    }
}
