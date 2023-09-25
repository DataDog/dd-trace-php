<?php
/**
 * Factory of REST request deserializers.
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Webapi\Rest\Request;

use Magento\Framework\Phrase;

class DeserializerFactory
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var array
     */
    protected $_deserializers;

    /**
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param array $deserializers
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        array $deserializers = []
    ) {
        $this->_objectManager = $objectManager;
        $this->_deserializers = $deserializers;
    }

    /**
     * Retrieve proper deserializer for the specified content type.
     *
     * @param string $contentType
     * @return \Magento\Framework\Webapi\Rest\Request\DeserializerInterface
     * @throws \LogicException|\Magento\Framework\Webapi\Exception
     */
    public function get($contentType)
    {
        if (empty($this->_deserializers)) {
            throw new \LogicException('Request deserializer adapter is not set.');
        }
        foreach ($this->_deserializers as $deserializerMetadata) {
            $deserializerType = $deserializerMetadata['type'];
            if ($deserializerType == $contentType) {
                $deserializerClass = $deserializerMetadata['model'];
                break;
            }
        }

        if (!isset($deserializerClass) || empty($deserializerClass)) {
            throw new \Magento\Framework\Webapi\Exception(
                new Phrase('Server cannot understand Content-Type HTTP header media type %1', [$contentType])
            );
        }

        $deserializer = $this->_objectManager->get($deserializerClass);
        if (!$deserializer instanceof \Magento\Framework\Webapi\Rest\Request\DeserializerInterface) {
            throw new \LogicException(
                'The deserializer must implement "Magento\Framework\Webapi\Rest\Request\DeserializerInterface".'
            );
        }
        return $deserializer;
    }
}
