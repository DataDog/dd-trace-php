<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Url;

/**
 * Base64 decoder for URLs
 *
 * @api
 * @since 100.0.2
 */
interface DecoderInterface
{
    /**
     * base64_decode() for URLs decoding
     *
     * @param    string $url
     * @return   string
     */
    public function decode($url);
}
