<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Integration\Test\Unit\Helper\Oauth;

use Magento\Framework\Math\Random;
use Magento\Framework\Oauth\Helper\Oauth;
use PHPUnit\Framework\TestCase;

class OauthTest extends TestCase
{
    /** @var Oauth */
    protected $_oauthHelper;

    protected function setUp(): void
    {
        $this->_oauthHelper = new Oauth(new Random());
    }

    protected function tearDown(): void
    {
        unset($this->_oauthHelper);
    }

    public function testGenerateToken()
    {
        $token = $this->_oauthHelper->generateToken();
        $this->assertTrue(is_string($token) && strlen($token) === Oauth::LENGTH_TOKEN);
    }

    public function testGenerateTokenSecret()
    {
        $token = $this->_oauthHelper->generateTokenSecret();
        $this->assertTrue(is_string($token) && strlen($token) === Oauth::LENGTH_TOKEN_SECRET);
    }

    public function testGenerateVerifier()
    {
        $token = $this->_oauthHelper->generateVerifier();
        $this->assertTrue(is_string($token) && strlen($token) === Oauth::LENGTH_TOKEN_VERIFIER);
    }

    public function testGenerateConsumerKey()
    {
        $token = $this->_oauthHelper->generateConsumerKey();
        $this->assertTrue(is_string($token) && strlen($token) === Oauth::LENGTH_CONSUMER_KEY);
    }

    public function testGenerateConsumerSecret()
    {
        $token = $this->_oauthHelper->generateConsumerSecret();
        $this->assertTrue(is_string($token) && strlen($token) === Oauth::LENGTH_CONSUMER_SECRET);
    }
}
