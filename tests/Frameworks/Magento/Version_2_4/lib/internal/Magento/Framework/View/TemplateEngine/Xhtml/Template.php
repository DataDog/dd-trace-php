<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\TemplateEngine\Xhtml;

/**
 * XML Template Engine
 */
class Template
{
    const XML_VERSION = '1.0';

    const XML_ENCODING = 'UTF-8';

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \DOMElement
     */
    protected $templateNode;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @param string $content
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        $content
    ) {
        $this->logger = $logger;
        $document = new \DOMDocument(static::XML_VERSION, static::XML_ENCODING);
        $document->loadXML($content, LIBXML_PARSEHUGE);
        $this->templateNode = $document->documentElement;
    }

    /**
     * Get template root element
     *
     * @return \DOMElement
     */
    public function getDocumentElement()
    {
        return $this->templateNode;
    }

    /**
     * Append
     *
     * @param string $content
     * @return void
     */
    public function append($content)
    {
        $ownerDocument= $this->templateNode->ownerDocument;
        $document = new \DOMDocument();
        $document->loadXml($content, LIBXML_PARSEHUGE);
        $this->templateNode->appendChild(
            $ownerDocument->importNode($document->documentElement, true)
        );
    }

    /**
     * Returns the string representation
     *
     * @return string
     */
    public function __toString()
    {
        try {
            $this->templateNode->ownerDocument->normalizeDocument();
            $result = $this->templateNode->ownerDocument->saveHTML();
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
            $result = '';
        }
        return $result;
    }
}
