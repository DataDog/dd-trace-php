<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Integration\Test\Unit\Oauth;

use Laminas\OAuth\Http\Utility;
use Magento\Framework\DataObject;
use Magento\Framework\Math\Random;
use Magento\Framework\Oauth\Helper\Oauth;
use Magento\Framework\Oauth\OauthInputException;
use Magento\Framework\Oauth\OauthInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Integration\Model\Oauth\Consumer;
use Magento\Integration\Model\Oauth\ConsumerFactory;
use Magento\Integration\Model\Oauth\Nonce;
use Magento\Integration\Model\Oauth\Nonce\Generator;
use Magento\Integration\Model\Oauth\NonceFactory;
use Magento\Integration\Model\Oauth\Token;
use Magento\Integration\Model\Oauth\Token\Provider;
use Magento\Integration\Model\Oauth\TokenFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Magento\Integration\Model\ResourceModel\Oauth\Token as TokenResourceModel;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OauthTest extends TestCase
{
    private const TIMESTAMP_STUB = 1657789046;

    /** @var ConsumerFactory */
    private $_consumerFactory;

    /** @var NonceFactory */
    private $_nonceFactory;

    /** @var TokenFactory */
    private $_tokenFactory;

    /** @var Consumer */
    private $_consumerMock;

    /** @var Token */
    private $_tokenMock;

    /** @var \Magento\Framework\Oauth\Helper\Oauth */
    private $_oauthHelperMock;

    /** @var \Magento\Framework\Oauth\Oauth */
    private $_oauth;

    /** @var  \Zend_Oauth_Http_Utility */
    private $_httpUtilityMock;

    /** @var DateTime */
    private $_dateMock;

    /**
     * @var LoggerInterface
     */
    private $_loggerMock;

    /**
     * @var string
     */
    private $_oauthToken;

    /**
     * @var string
     */
    private $_oauthSecret;

    /**
     * @var string
     */
    private $_oauthVerifier;

    /**#@+
     * Constants
     */
    private const CONSUMER_ID = 1;

    private const REQUEST_URL = 'http://magento.ll';
    /**#@-*/

    /** @var MockObject */
    private $tokenResourceModelMock;

    /**
     * Initialize dependencies.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $this->_consumerFactory = $this->getMockBuilder(ConsumerFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->_consumerMock = $this->getMockBuilder(Consumer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(
                [
                    'getCreatedAt',
                    'loadByKey',
                    'load',
                    'getId',
                    'getSecret',
                    'getCallbackUrl',
                    'save',
                    'getData',
                    'isValidForTokenExchange',
                    '__wakeup',
                ]
            )
            ->getMock();
        $this->_consumerFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->_consumerMock);
        $this->_nonceFactory = $this->getMockBuilder(NonceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->_tokenFactory = $this->getMockBuilder(
            TokenFactory::class
        )->disableOriginalConstructor()
            ->onlyMethods(['create'])->getMock();
        $this->_tokenMock = $this->getMockBuilder(Token::class)
            ->disableOriginalConstructor()
            ->onlyMethods(
                [
                    'getId',
                    'load',
                    'createRequestToken',
                    'createVerifierToken',
                    'getVerifier',
                    'convertToAccess',
                    'getResource',
                    'loadByConsumerIdAndUserType',
                    '__wakeup',
                ]
            )
            ->addMethods(
                [
                    'getType',
                    'getToken',
                    'getSecret',
                    'getConsumerId',
                    'getRevoked'
                ]
            )
            ->getMock();
        $this->_tokenFactory->expects($this->any())->method('create')->willReturn($this->_tokenMock);
        $this->_oauthHelperMock = $this->getMockBuilder(Oauth::class)
            ->setConstructorArgs([new Random()])
            ->getMock();
        $this->_httpUtilityMock = $this->getMockBuilder(Utility::class)
            ->onlyMethods(['sign'])
            ->getMock();
        $this->_dateMock = $this->getMockBuilder(DateTime::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->_dateMock->method('timestamp')
            ->willReturn(self::TIMESTAMP_STUB);
        $this->_loggerMock = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $nonceGenerator = new Generator(
            $this->_oauthHelperMock,
            $this->_nonceFactory,
            $this->_dateMock
        );
        $this->tokenResourceModelMock = $this->getMockBuilder(TokenResourceModel::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $tokenProvider = new Provider(
            $this->_consumerFactory,
            $this->_tokenFactory,
            $this->_loggerMock
        );
        $this->_oauth = new \Magento\Framework\Oauth\Oauth(
            $this->_oauthHelperMock,
            $nonceGenerator,
            $tokenProvider,
            $this->_httpUtilityMock
        );
        $this->_oauthToken = $this->_generateRandomString(Oauth::LENGTH_TOKEN);
        $this->_oauthSecret = $this->_generateRandomString(Oauth::LENGTH_TOKEN_SECRET);
        $this->_oauthVerifier = $this->_generateRandomString(
            Oauth::LENGTH_TOKEN_VERIFIER
        );
    }

    protected function tearDown(): void
    {
        unset($this->_consumerFactory);
        unset($this->_nonceFactory);
        unset($this->_tokenFactory);
        unset($this->_oauthHelperMock);
        unset($this->_httpUtilityMock);
        unset($this->_dateMock);
        unset($this->_oauth);
    }

    /**
     * @param array $amendments
     * @return array
     */
    protected function _getRequestTokenParams($amendments = [])
    {
        $requiredParams = [
            'oauth_version' => '1.0',
            'oauth_consumer_key' => $this->_generateRandomString(
                Oauth::LENGTH_CONSUMER_KEY
            ),
            'oauth_nonce' => '',
            'oauth_timestamp' => time(),
            'oauth_signature_method' => OauthInterface::SIGNATURE_SHA256,
            'oauth_signature' => 'invalid_signature',
        ];

        return array_merge($requiredParams, $amendments);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_VERSION_REJECTED
     */
    public function testGetRequestTokenVersionRejected()
    {
        $this->expectException('Magento\Framework\Oauth\OauthInputException');
        $this->_oauth->getRequestToken(
            $this->_getRequestTokenParams(['oauth_version' => '2.0']),
            self::REQUEST_URL
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_CONSUMER_KEY_REJECTED
     */
    public function testGetRequestTokenConsumerKeyRejected()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_oauth->getRequestToken(
            $this->_getRequestTokenParams(['oauth_consumer_key' => 'wrong_key_length']),
            self::REQUEST_URL
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_CONSUMER_KEY_REJECTED
     */
    public function testGetRequestTokenConsumerKeyNotFound()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_consumerMock->expects(
            $this->once()
        )->method(
            'loadByKey'
        )->willReturn(
            new DataObject()
        );

        $this->_oauth->getRequestToken($this->_getRequestTokenParams(), self::REQUEST_URL);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_CONSUMER_KEY_INVALID
     */
    public function testGetRequestTokenOutdatedConsumerKey()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_setupNonce();
        $this->_consumerMock
            ->expects($this->any())
            ->method('isValidForTokenExchange')
            ->willReturn(false);

        $this->_oauth->getRequestToken($this->_getRequestTokenParams(), self::REQUEST_URL);
    }

    /**
     * @param bool $isLoadable
     */
    protected function _setupConsumer($isLoadable = true)
    {
        $this->_consumerMock->expects($this->any())->method('loadByKey')->willReturnSelf();

        $this->_consumerMock->expects(
            $this->any()
        )->method(
            'getCreatedAt'
        )->willReturn(
            date('c', strtotime('-1 day'))
        );

        if ($isLoadable) {
            $this->_consumerMock->expects($this->any())->method('load')->willReturnSelf();
        } else {
            $this->_consumerMock->expects(
                $this->any()
            )->method(
                'load'
            )->willReturn(
                new DataObject()
            );
        }

        $this->_consumerMock->expects($this->any())->method('getId')->willReturn(1);
        $this->_consumerMock->expects($this->any())->method('getSecret')->willReturn('consumer_secret');
        $this->_consumerMock->expects(
            $this->any()
        )->method(
            'getCallbackUrl'
        )->willReturn(
            'callback_url'
        );
    }

    protected function _makeValidExpirationPeriod()
    {
        $this->_consumerMock
            ->expects($this->any())
            ->method('isValidForTokenExchange')
            ->willReturn(true);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TIMESTAMP_REFUSED
     *
     * @dataProvider dataProviderForGetRequestTokenNonceTimestampRefusedTest
     */
    public function testGetRequestTokenOauthTimestampRefused($timestamp)
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_makeValidExpirationPeriod();

        $this->_oauth->getRequestToken(
            $this->_getRequestTokenParams(['oauth_timestamp' => $timestamp]),
            self::REQUEST_URL
        );
    }

    /**
     * @return array
     */
    public function dataProviderForGetRequestTokenNonceTimestampRefusedTest()
    {
        return [
            [0],
            //Adding one day deviation
            [time() + Generator::TIME_DEVIATION + 86400]
        ];
    }

    /**
     * @param bool $isUsed
     * @param int $timestamp
     */
    protected function _setupNonce($isUsed = false, $timestamp = 0)
    {
        $nonceMock = $this->getMockBuilder(
            Nonce::class
        )->disableOriginalConstructor()
            ->onlyMethods(
                [
                    'loadByCompositeKey',
                    'save',
                    '__wakeup',
                ]
            )
            ->addMethods(
                [
                    'getNonce',
                    'setNonce',
                    'setConsumerId',
                    'setTimestamp',
                    'getTimeStamp'
                ]
            )
            ->getMock();

        $nonceMock->expects($this->any())->method('getNonce')->willReturn($isUsed);
        $nonceMock->expects($this->any())->method('loadByCompositeKey')->willReturnSelf();
        $nonceMock->expects($this->any())->method('getTimestamp')->willReturn($timestamp);
        $nonceMock->expects($this->any())->method('setNonce')->willReturnSelf();
        $nonceMock->expects($this->any())->method('setConsumerId')->willReturnSelf();
        $nonceMock->expects($this->any())->method('setTimestamp')->willReturnSelf();
        $nonceMock->expects($this->any())->method('save')->willReturnSelf();
        $this->_nonceFactory->expects($this->any())->method('create')->willReturn($nonceMock);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_NONCE_USED
     */
    public function testGetRequestTokenNonceAlreadyUsed()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_makeValidExpirationPeriod();
        $this->_setupNonce(true);

        $this->_oauth->getRequestToken($this->_getRequestTokenParams(), self::REQUEST_URL);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_CONSUMER_KEY_REJECTED
     */
    public function testGetRequestTokenNoConsumer()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_consumerMock->expects(
            $this->any()
        )->method(
            'loadByKey'
        )->willReturn(
            new DataObject()
        );

        $this->_oauth->getRequestToken($this->_getRequestTokenParams(), self::REQUEST_URL);
    }

    /**
     * @param bool $doesExist
     * @param string $type
     * @param int $consumerId
     * @param null $verifier
     * @param bool $isRevoked
     */
    protected function _setupToken(
        $doesExist = true,
        $type = Token::TYPE_VERIFIER,
        $consumerId = self::CONSUMER_ID,
        $verifier = null,
        $isRevoked = false
    ) {
        $this->_tokenMock->expects(
            $this->any()
        )->method(
            'getId'
        )->willReturn(
            $doesExist ? self::CONSUMER_ID : null
        );

        $verifier = $verifier ?: $this->_oauthVerifier;

        $this->_tokenMock->expects($this->any())->method('load')->willReturnSelf();
        $this->_tokenMock->expects($this->any())->method('getType')->willReturn($type);
        $this->_tokenMock->expects($this->any())->method('createRequestToken')->willReturnSelf();
        $this->_tokenMock->expects($this->any())->method('getToken')->willReturn($this->_oauthToken);
        $this->_tokenMock->expects($this->any())->method('getSecret')->willReturn($this->_oauthSecret);
        $this->_tokenMock->expects($this->any())->method('getConsumerId')->willReturn($consumerId);
        $this->_tokenMock->expects($this->any())->method('getVerifier')->willReturn($verifier);
        $this->_tokenMock->expects($this->any())->method('convertToAccess')->willReturnSelf();
        $this->_tokenMock->expects($this->any())->method('getRevoked')->willReturn($isRevoked);
        $this->_tokenMock->expects($this->any())->method('loadByConsumerIdAndUserType')->willReturnSelf();
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REJECTED
     */
    public function testGetRequestTokenTokenRejected()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_makeValidExpirationPeriod();
        $this->_setupNonce();
        $this->_setupToken(false);

        $signature = 'valid_signature';
        $this->_httpUtilityMock->expects($this->any())->method('sign')->willReturn($signature);

        $this->_oauth->getRequestToken(
            $this->_getRequestTokenParams(['oauth_signature' => $signature]),
            self::REQUEST_URL
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REJECTED
     */
    public function testGetRequestTokenTokenRejectedByType()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_makeValidExpirationPeriod();
        $this->_setupNonce();
        $this->_setupToken(true, Token::TYPE_REQUEST);
        // wrong type

        $signature = 'valid_signature';
        $this->_httpUtilityMock->expects($this->any())->method('sign')->willReturn($signature);

        $this->_oauth->getRequestToken(
            $this->_getRequestTokenParams(['oauth_signature' => $signature]),
            self::REQUEST_URL
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_SIGNATURE_METHOD_REJECTED
     */
    public function testGetRequestTokenSignatureMethodRejected()
    {
        $this->expectException('Magento\Framework\Oauth\OauthInputException');
        $this->_setupConsumer();
        $this->_makeValidExpirationPeriod();
        $this->_setupNonce();
        $this->_setupToken();

        $this->_oauth->getRequestToken(
            $this->_getRequestTokenParams(['oauth_signature_method' => 'wrong_method']),
            self::REQUEST_URL
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_SIGNATURE_INVALID
     */
    public function testGetRequestTokenInvalidSignature()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_makeValidExpirationPeriod();
        $this->_setupNonce();
        $this->_setupToken();

        $this->_oauth->getRequestToken(
            $this->_getRequestTokenParams(['oauth_signature' => 'invalid_signature']),
            self::REQUEST_URL
        );
    }

    public function testGetRequestToken()
    {
        $this->_setupConsumer();
        $this->_makeValidExpirationPeriod();
        $this->_setupNonce();
        $this->_setupToken();

        $signature = 'valid_signature';
        $this->_httpUtilityMock->expects($this->any())->method('sign')->willReturn($signature);

        $requestToken = $this->_oauth->getRequestToken(
            $this->_getRequestTokenParams(['oauth_signature' => $signature]),
            self::REQUEST_URL
        );

        $this->assertEquals(
            ['oauth_token' => $this->_oauthToken, 'oauth_token_secret' => $this->_oauthSecret],
            $requestToken
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_VERSION_REJECTED
     */
    public function testGetAccessTokenVersionRejected()
    {
        $this->expectException('Magento\Framework\Oauth\OauthInputException');
        $this->_oauth->getAccessToken(
            $this->_getAccessTokenRequiredParams(['oauth_version' => '0.0']),
            self::REQUEST_URL
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_PARAMETER_ABSENT
     */
    public function testGetAccessTokenParameterAbsent()
    {
        $this->expectException('Magento\Framework\Oauth\OauthInputException');
        $this->expectExceptionMessage('"oauth_verifier" is required. Enter and try again.');
        $this->_oauth->getAccessToken(
            [
                'oauth_version' => '1.0',
                'oauth_consumer_key' => '',
                'oauth_signature' => '',
                'oauth_signature_method' => '',
                'oauth_nonce' => '',
                'oauth_timestamp' => '',
                'oauth_token' => '',
                // oauth_verifier missing
            ],
            self::REQUEST_URL
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REJECTED
     */
    public function testGetAccessTokenTokenRejected()
    {
        $this->expectException('Magento\Framework\Oauth\OauthInputException');
        $this->_oauth->getAccessToken(
            $this->_getAccessTokenRequiredParams(['oauth_token' => 'invalid_token']),
            self::REQUEST_URL
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_SIGNATURE_METHOD_REJECTED
     */
    public function testGetAccessTokenSignatureMethodRejected()
    {
        $this->expectException('Magento\Framework\Oauth\OauthInputException');
        $this->_oauth->getAccessToken(
            $this->_getAccessTokenRequiredParams(['oauth_signature_method' => 'invalid_method']),
            self::REQUEST_URL
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_USED
     */
    public function testGetAccessTokenTokenUsed()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_setupNonce();
        $this->_setupToken(true, Token::TYPE_VERIFIER);
        // Wrong type

        $this->_oauth->getAccessToken($this->_getAccessTokenRequiredParams(), self::REQUEST_URL);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REJECTED
     */
    public function testGetAccessTokenConsumerIdDoesntMatch()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_setupNonce();
        $this->_setupToken(true, Token::TYPE_REQUEST, null);

        $this->_oauth->getAccessToken($this->_getAccessTokenRequiredParams(), self::REQUEST_URL);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_VERIFIER_INVALID
     *
     * @dataProvider dataProviderForGetAccessTokenVerifierInvalidTest
     */
    public function testGetAccessTokenVerifierInvalid($verifier, $verifierFromToken)
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_setupNonce();
        $this->_setupToken(
            true,
            Token::TYPE_REQUEST,
            self::CONSUMER_ID,
            $verifierFromToken
        );

        $this->_oauth->getAccessToken(
            $this->_getAccessTokenRequiredParams(['oauth_verifier' => $verifier]),
            self::REQUEST_URL
        );
    }

    /**
     * @return array
     */
    public function dataProviderForGetAccessTokenVerifierInvalidTest()
    {
        // Verifier is not a string
        return [[3, 3], ['wrong_length', 'wrong_length'], ['verifier', 'doesn\'t match']];
    }

    public function testGetAccessToken()
    {
        $this->_setupConsumer();
        $this->_setupNonce();
        $this->_setupToken(true, Token::TYPE_REQUEST);

        $token = $this->_oauth->getAccessToken($this->_getAccessTokenRequiredParams(), self::REQUEST_URL);
        $this->assertEquals(
            ['oauth_token' => $this->_oauthToken, 'oauth_token_secret' => $this->_oauthSecret],
            $token
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REJECTED
     */
    public function testValidateAccessTokenRequestTokenRejected()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_setupNonce();
        $this->_setupToken(true, Token::TYPE_ACCESS, null);

        $this->_oauth->validateAccessTokenRequest($this->_getAccessTokenRequiredParams(), self::REQUEST_URL);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REJECTED
     */
    public function testValidateAccessTokenRequestTokenRejectedByType()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_setupNonce();
        $this->_setupToken(true, Token::TYPE_REQUEST);

        $this->_oauth->validateAccessTokenRequest($this->_getAccessTokenRequiredParams(), self::REQUEST_URL);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REVOKED
     */
    public function testValidateAccessTokenRequestTokenRevoked()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_setupNonce();
        $this->_setupToken(
            true,
            Token::TYPE_ACCESS,
            self::CONSUMER_ID,
            $this->_oauthVerifier,
            true
        );

        $this->_oauth->validateAccessTokenRequest($this->_getAccessTokenRequiredParams(), self::REQUEST_URL);
    }

    public function testValidateAccessTokenRequest()
    {
        $this->_setupConsumer();
        $this->_setupNonce();
        $this->_setupToken(true, Token::TYPE_ACCESS);
        $requiredParams = $this->_getAccessTokenRequiredParams();
        $this->assertEquals(
            1,
            $this->_oauth->validateAccessTokenRequest($requiredParams, self::REQUEST_URL),
            "Consumer ID is invalid."
        );
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REJECTED
     */
    public function testValidateAccessTokenRejectedByType()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_setupToken(true, Token::TYPE_REQUEST);

        $this->_oauth->validateAccessToken($this->_oauthToken);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REVOKED
     */
    public function testValidateAccessTokenRevoked()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer();
        $this->_setupToken(
            true,
            Token::TYPE_ACCESS,
            self::CONSUMER_ID,
            $this->_oauthVerifier,
            true
        );

        $this->_oauth->validateAccessToken($this->_oauthToken);
    }

    /**
     * \Magento\Framework\Oauth\OauthInterface::ERR_TOKEN_REJECTED
     */
    public function testValidateAccessTokenNoConsumer()
    {
        $this->expectException('Magento\Framework\Oauth\Exception');
        $this->_setupConsumer(false);
        $this->_setupToken(true, Token::TYPE_ACCESS);

        $this->_oauth->validateAccessToken($this->_oauthToken);
    }

    public function testValidateAccessToken()
    {
        $this->_setupConsumer();
        $this->_setupToken(true, Token::TYPE_ACCESS);

        $this->assertEquals(1, $this->_oauth->validateAccessToken($this->_oauthToken), "Consumer ID is invalid.");
    }

    public function testBuildAuthorizationHeader()
    {
        $signature = 'valid_signature';
        $this->_httpUtilityMock->expects($this->any())->method('sign')->willReturn($signature);

        $this->_setupConsumer(false);
        $this->_oauthHelperMock->expects(
            $this->any()
        )->method(
            'generateRandomString'
        )->willReturn(
            'tyukmnjhgfdcvxstyuioplkmnhtfvert'
        );

        $request = [
            'oauth_consumer_key' => 'edf957ef88492f0a32eb7e1731e85da2',
            'oauth_consumer_secret' => 'asdawwewefrtyh2f0a32eb7e1731e85d',
            'oauth_token' => '7c0709f789e1f38a17aa4b9a28e1b06c',
            'oauth_token_secret' => 'a6agsfrsfgsrjjjjyy487939244ssggg',
            'custom_param1' => 'foo',
            'custom_param2' => 'bar',
        ];

        $requestUrl = 'http://www.example.com/endpoint';
        $oauthHeader = $this->_oauth->buildAuthorizationHeader($request, $requestUrl);

        $expectedHeader = 'OAuth oauth_nonce="tyukmnjhgfdcvxstyuioplkmnhtfvert",' .
            'oauth_timestamp="' . self::TIMESTAMP_STUB . '",' .
            'oauth_version="1.0",oauth_consumer_key="edf957ef88492f0a32eb7e1731e85da2",' .
            'oauth_consumer_secret="asdawwewefrtyh2f0a32eb7e1731e85d",' .
            'oauth_token="7c0709f789e1f38a17aa4b9a28e1b06c",' .
            'oauth_token_secret="a6agsfrsfgsrjjjjyy487939244ssggg",' .
            'oauth_signature="valid_signature"';

        $this->assertEquals($expectedHeader, $oauthHeader, 'Generated Oauth header is incorrect');
    }

    /**
     * @dataProvider dataProviderMissingParamForBuildAuthorizationHeaderTest
     */
    public function testMissingParamForBuildAuthorizationHeader($expectedMessage, $request)
    {
        $this->expectException(OauthInputException::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->expectExceptionCode(0);

        $requestUrl = 'http://www.example.com/endpoint';
        $this->_oauth->buildAuthorizationHeader($request, $requestUrl);
    }

    /**
     * @return array
     */
    public function dataProviderMissingParamForBuildAuthorizationHeaderTest()
    {
        return [
            [
                'oauth_consumer_key',
                [ //'oauth_consumer_key' => 'edf957ef88492f0a32eb7e1731e85d',
                    'oauth_consumer_secret' => 'asdawwewefrtyh2f0a32eb7e1731e85d',
                    'oauth_token' => '7c0709f789e1f38a17aa4b9a28e1b06c',
                    'oauth_token_secret' => 'a6agsfrsfgsrjjjjyy487939244ssggg',
                    'custom_param1' => 'foo',
                    'custom_param2' => 'bar'
                ],
            ],
            [
                'oauth_consumer_secret',
                [
                    'oauth_consumer_key' => 'edf957ef88492f0a32eb7e1731e85d',
                    //'oauth_consumer_secret' => 'asdawwewefrtyh2f0a32eb7e1731e85d',
                    'oauth_token' => '7c0709f789e1f38a17aa4b9a28e1b06c',
                    'oauth_token_secret' => 'a6agsfrsfgsrjjjjyy487939244ssggg',
                    'custom_param1' => 'foo',
                    'custom_param2' => 'bar'
                ]
            ],
            [
                'oauth_token',
                [
                    'oauth_consumer_key' => 'edf957ef88492f0a32eb7e1731e85d',
                    'oauth_consumer_secret' => 'asdawwewefrtyh2f0a32eb7e1731e85d',
                    //'oauth_token' => '7c0709f789e1f38a17aa4b9a28e1b06c',
                    'oauth_token_secret' => 'a6agsfrsfgsrjjjjyy487939244ssggg',
                    'custom_param1' => 'foo',
                    'custom_param2' => 'bar'
                ]
            ],
            [
                'oauth_token_secret',
                [
                    'oauth_consumer_key' => 'edf957ef88492f0a32eb7e1731e85d',
                    'oauth_consumer_secret' => 'asdawwewefrtyh2f0a32eb7e1731e85d',
                    'oauth_token' => '7c0709f789e1f38a17aa4b9a28e1b06c',
                    //'oauth_token_secret' => 'a6agsfrsfgsrjjjjyy487939244ssggg',
                    'custom_param1' => 'foo',
                    'custom_param2' => 'bar'
                ]
            ]
        ];
    }

    /**
     * @param array $amendments
     * @return array
     */
    protected function _getAccessTokenRequiredParams($amendments = [])
    {
        $requiredParams = [
            'oauth_consumer_key' => $this->_generateRandomString(
                Oauth::LENGTH_CONSUMER_KEY
            ),
            'oauth_signature' => '',
            'oauth_signature_method' => OauthInterface::SIGNATURE_SHA256,
            'oauth_nonce' => '',
            'oauth_timestamp' => (string)time(),
            'oauth_token' => $this->_generateRandomString(Oauth::LENGTH_TOKEN),
            'oauth_verifier' => $this->_oauthVerifier,
        ];

        return array_merge($requiredParams, $amendments);
    }

    /**
     * @param $length
     * @return bool|string
     */
    private function _generateRandomString($length)
    {
        return substr(
            str_shuffle(str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 5)),
            0,
            $length
        );
    }
}
