<?php

namespace DDTrace\FeatureFlags;

final class EvaluationErrorCode
{
    const FLAG_NOT_FOUND = 'FLAG_NOT_FOUND';
    const PARSE_ERROR = 'PARSE_ERROR';
    const TYPE_MISMATCH = 'TYPE_MISMATCH';
    const GENERAL = 'GENERAL';
    const PROVIDER_NOT_READY = 'PROVIDER_NOT_READY';

    private static $valid = array(
        self::FLAG_NOT_FOUND => true,
        self::PARSE_ERROR => true,
        self::TYPE_MISMATCH => true,
        self::GENERAL => true,
        self::PROVIDER_NOT_READY => true,
    );

    private function __construct()
    {
    }

    public static function isValid($errorCode)
    {
        return $errorCode === null || isset(self::$valid[$errorCode]);
    }
}
