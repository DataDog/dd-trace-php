<?php

namespace DDTrace\FeatureFlags;

final class EvaluationType
{
    const BOOLEAN = 'boolean';
    const STRING = 'string';
    const INTEGER = 'integer';
    const FLOAT = 'float';
    const OBJECT = 'object';

    private static $valid = array(
        self::BOOLEAN => true,
        self::STRING => true,
        self::INTEGER => true,
        self::FLOAT => true,
        self::OBJECT => true,
    );

    private function __construct()
    {
    }

    public static function isValid($valueType)
    {
        return isset(self::$valid[$valueType]);
    }

    public static function fromDefaultValue($defaultValue)
    {
        if (is_bool($defaultValue)) {
            return self::BOOLEAN;
        }

        if (is_string($defaultValue)) {
            return self::STRING;
        }

        if (is_int($defaultValue)) {
            return self::INTEGER;
        }

        if (is_float($defaultValue)) {
            return self::FLOAT;
        }

        if (is_array($defaultValue)) {
            return self::OBJECT;
        }

        throw new \InvalidArgumentException('Unsupported feature flag default value type');
    }
}
