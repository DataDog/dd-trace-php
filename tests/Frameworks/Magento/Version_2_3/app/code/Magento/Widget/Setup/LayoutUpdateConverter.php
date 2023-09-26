<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Widget\Setup;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\Serializer\Serialize;
use Magento\Framework\Data\Wysiwyg\Normalizer;
use Magento\Framework\Escaper;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DB\DataConverter\DataConversionException;
use Magento\Framework\DB\DataConverter\SerializedToJson;

/**
 * Convert conditions_encoded part of layout update data from serialized to JSON format
 */
class LayoutUpdateConverter extends SerializedToJson
{
    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @var Normalizer
     */
    private $normalizer;

    /**
     * Constructor
     *
     * @param Serialize $serialize
     * @param Json $json
     * @param Normalizer $normalizer
     * @param Escaper|null $escaper
     */
    public function __construct(
        Serialize $serialize,
        Json $json,
        Normalizer $normalizer,
        Escaper $escaper = null
    ) {
        $this->normalizer = $normalizer;
        $this->escaper = $escaper ?? ObjectManager::getInstance()->get(
            Escaper::class
        );
        parent::__construct($serialize, $json);
    }

    /**
     * Convert conditions_encoded part of layout update data from serialized to JSON format
     *
     * @param string $value
     * @return string
     * @throws DataConversionException
     */
    public function convert($value)
    {
        preg_match_all(
            '/^(.*?conditions_encoded<\/argument><argument [^<]*>)(.*?)(<\/argument>.*?)$/si',
            $value,
            $matches,
            PREG_SET_ORDER
        );
        if (isset($matches[0])) {
            $matchSegments = $matches[0];
            $matchSegments[2] = parent::convert($matchSegments[2]);
            return $matchSegments[1] . $matchSegments[2] . $matchSegments[3];
        } else {
            return $value;
        }
    }

    /**
     * @inheritdoc
     */
    protected function isValidJsonValue($value)
    {
        $value = $this->normalizer->restoreReservedCharacters($value);
        return parent::isValidJsonValue($value);
    }

    /**
     * @inheritdoc
     */
    protected function unserializeValue($value)
    {
        $value = htmlspecialchars_decode($value);
        $value = $this->restoreReservedCharactersInSerializedContent($value);
        return parent::unserializeValue($value);
    }

    /**
     * @inheritdoc
     */
    protected function encodeJson($value)
    {
        return $this->escaper->escapeHtml(
            $this->normalizer->replaceReservedCharacters(parent::encodeJson($value))
        );
    }

    /**
     * Restore the reserved characters in the existing serialized content
     *
     * @param string $serializedContent
     * @return string
     */
    private function restoreReservedCharactersInSerializedContent($serializedContent)
    {
        $map = [
            '{' => '[',
            '}' => ']',
            '"' => '`',
            '\\' => '|',
        ];
        return str_replace(
            array_values($map),
            array_keys($map),
            $serializedContent
        );
    }
}
