<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Abstract class for the controller tests
 */

namespace Magento\TestFramework\TestCase;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\View\Element\Message\InterpretationStrategyInterface;
use Magento\Theme\Controller\Result\MessagePlugin;
use PHPUnit\Framework\TestCase;

/**
 * Set of methods useful for performing requests to Controllers.
 *
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractController extends TestCase
{
    protected $_runCode = '';

    protected $_runScope = 'store';

    protected $_runOptions = [];

    /**
     * @var RequestInterface
     */
    protected $_request;

    /**
     * @var ResponseInterface
     */
    protected $_response;

    /**
     * @var \Magento\TestFramework\ObjectManager
     */
    protected $_objectManager;

    /**
     * Whether absence of session error messages has to be asserted automatically upon a test completion
     *
     * @var bool
     */
    protected $_assertSessionErrors = false;

    /**
     * Bootstrap instance getter
     *
     * @return \Magento\TestFramework\Helper\Bootstrap
     */
    protected function _getBootstrap()
    {
        return \Magento\TestFramework\Helper\Bootstrap::getInstance();
    }

    /**
     * Bootstrap application before any test
     */
    protected function setUp(): void
    {
        $this->_assertSessionErrors = false;
        $this->_objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->_objectManager->removeSharedInstance(\Magento\Framework\App\ResponseInterface::class);
        $this->_objectManager->removeSharedInstance(\Magento\Framework\App\RequestInterface::class);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->_request = null;
        $this->_response = null;
        $this->_objectManager = null;
    }

    /**
     * Ensure that there were no error messages displayed on the admin panel
     */
    protected function assertPostConditions(): void
    {
        if ($this->_assertSessionErrors) {
            // equalTo() is intentionally used instead of isEmpty() to provide the informative diff
            $this->assertSessionMessages(
                $this->equalTo([]),
                \Magento\Framework\Message\MessageInterface::TYPE_ERROR
            );
        }
    }

    /**
     * Run request
     *
     * @param string $uri
     */
    public function dispatch($uri)
    {
        $request = $this->getRequest();

        $request->setDispatched(false);
        $request->setRequestUri($uri);
        if ($request->isPost()
            && !property_exists($request->getPost(), 'form_key')
        ) {
            /** @var FormKey $formKey */
            $formKey = $this->_objectManager->get(FormKey::class);
            $request->setPostValue('form_key', $formKey->getFormKey());
        }
        $this->_getBootstrap()->runApp();
    }

    /**
     * Request getter
     *
     * @return RequestInterface
     */
    public function getRequest()
    {
        if (!$this->_request) {
            $this->_request = $this->_objectManager->get(RequestInterface::class);
        }
        return $this->_request;
    }

    /**
     * Reset Request parameters
     *
     * @return void
     */
    protected function resetRequest(): void
    {
        $this->_objectManager->removeSharedInstance(RequestInterface::class);
        $this->_request = null;
    }

    /**
     * Response getter
     *
     * @return ResponseInterface
     */
    public function getResponse()
    {
        if (!$this->_response) {
            $this->_response = $this->_objectManager->get(ResponseInterface::class);
        }
        return $this->_response;
    }

    /**
     * Assert that response is '404 Not Found'
     */
    public function assert404NotFound()
    {
        $this->assertEquals('noroute', $this->getRequest()->getControllerName());
        $this->assertStringContainsString('404 Not Found', $this->getResponse()->getBody());
    }

    /**
     * Analyze response object and look for header with specified name, and assert a regex towards its value
     *
     * @param string $headerName
     * @param string $valueRegex
     * @throws \PHPUnit\Framework\AssertionFailedError when header not found
     */
    public function assertHeaderPcre($headerName, $valueRegex)
    {
        $headerFound = false;
        $headers = $this->getResponse()->getHeaders();
        foreach ($headers as $header) {
            if ($header->getFieldName() === $headerName) {
                $headerFound = true;
                $this->assertMatchesRegularExpression($valueRegex, $header->getFieldValue());
            }
        }
        if (!$headerFound) {
            $this->fail("Header '{$headerName}' was not found. Headers dump:\n" . var_export($headers, 1));
        }
    }

    /**
     * Assert that there is a redirect to expected URL.
     * Omit expected URL to check that redirect to wherever has been occurred.
     * Examples of usage:
     * $this->assertRedirect($this->equalTo($expectedUrl));
     * $this->assertRedirect($this->stringStartsWith($expectedUrlPrefix));
     * $this->assertRedirect($this->stringEndsWith($expectedUrlSuffix));
     * $this->assertRedirect($this->stringContains($expectedUrlSubstring));
     *
     * @param \PHPUnit\Framework\Constraint\Constraint|null $urlConstraint
     */
    public function assertRedirect(\PHPUnit\Framework\Constraint\Constraint $urlConstraint = null)
    {
        $this->assertTrue($this->getResponse()->isRedirect(), 'Redirect was expected, but none was performed.');
        if ($urlConstraint) {
            $actualUrl = '';
            foreach ($this->getResponse()->getHeaders() as $header) {
                if ($header->getFieldName() == 'Location') {
                    $actualUrl = $header->getFieldValue();
                    break;
                }
            }
            $this->assertThat($actualUrl, $urlConstraint, 'Redirection URL does not match expectations');
        }
    }

    /**
     * Assert that actual session messages meet expectations:
     * Usage examples:
     * $this->assertSessionMessages($this->isEmpty(), \Magento\Framework\Message\MessageInterface::TYPE_ERROR);
     * $this->assertSessionMessages($this->equalTo(['Entity has been saved.'],
     * \Magento\Framework\Message\MessageInterface::TYPE_SUCCESS);
     *
     * @param \PHPUnit\Framework\Constraint\Constraint $constraint Constraint to compare actual messages against
     * @param string|null $messageType Message type filter,
     *        one of the constants \Magento\Framework\Message\MessageInterface::*
     * @param string $messageManagerClass Class of the session model that manages messages
     */
    public function assertSessionMessages(
        \PHPUnit\Framework\Constraint\Constraint $constraint,
        $messageType = null,
        $messageManagerClass = \Magento\Framework\Message\Manager::class
    ) {
        $this->_assertSessionErrors = false;
        /** @var MessageInterface[]|string[] $messageObjects */
        $messages = $this->getMessages($messageType, $messageManagerClass);
        /** @var string[] $messages */
        $messagesFiltered = array_map(
            function ($message) {
                /** @var MessageInterface|string $message */
                return ($message instanceof MessageInterface) ? $message->toString() : $message;
            },
            $messages
        );

        $this->assertThat(
            $messagesFiltered,
            $constraint,
            'Session messages do not meet expectations ' . var_export($messagesFiltered, true)
        );
    }

    /**
     * Return all stored messages
     *
     * @param string|null $messageType
     * @param string $messageManagerClass
     * @return array
     */
    protected function getMessages(
        $messageType = null,
        $messageManagerClass = \Magento\Framework\Message\Manager::class
    ) {
        return array_merge(
            $this->getSessionMessages($messageType, $messageManagerClass),
            $this->getCookieMessages($messageType)
        );
    }

    /**
     * Return messages stored in session
     *
     * @param string|null $messageType
     * @param string $messageManagerClass
     * @return array
     */
    protected function getSessionMessages(
        $messageType = null,
        $messageManagerClass = \Magento\Framework\Message\Manager::class
    ) {
        /** @var $messageManager \Magento\Framework\Message\ManagerInterface */
        $messageManager = $this->_objectManager->get($messageManagerClass);
        /** @var $messages \Magento\Framework\Message\AbstractMessage[] */
        if ($messageType === null) {
            $messages = $messageManager->getMessages()->getItems();
        } else {
            $messages = $messageManager->getMessages()->getItemsByType($messageType);
        }

        /** @var $messageManager InterpretationStrategyInterface */
        $interpretationStrategy = $this->_objectManager->get(InterpretationStrategyInterface::class);

        $actualMessages = [];
        foreach ($messages as $message) {
            $actualMessages[] = $interpretationStrategy->interpret($message);
        }

        return $actualMessages;
    }

    /**
     * Return messages stored in cookies by type
     *
     * @param string|null $messageType
     * @return array
     */
    protected function getCookieMessages($messageType = null)
    {
        /** @var $cookieManager CookieManagerInterface */
        $cookieManager = $this->_objectManager->get(CookieManagerInterface::class);

        /** @var $jsonSerializer \Magento\Framework\Serialize\Serializer\Json */
        $jsonSerializer = $this->_objectManager->get(\Magento\Framework\Serialize\Serializer\Json::class);
        try {
            $messages = $jsonSerializer->unserialize(
                $cookieManager->getCookie(
                    MessagePlugin::MESSAGES_COOKIES_NAME,
                    $jsonSerializer->serialize([])
                )
            );

            if (!is_array($messages)) {
                $messages = [];
            }
        } catch (\InvalidArgumentException $e) {
            $messages = [];
        }

        $actualMessages = [];
        foreach ($messages as $message) {
            if ($messageType === null || $message['type'] == $messageType) {
                $actualMessages[] = $message['text'];
            }
        }

        return $actualMessages;
    }
}
