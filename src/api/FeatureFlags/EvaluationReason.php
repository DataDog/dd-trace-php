<?php

namespace DDTrace\FeatureFlags;

final class EvaluationReason
{
    const STATIC_REASON = 'STATIC';
    const DEFAULT_REASON = 'DEFAULT';
    const TARGETING_MATCH = 'TARGETING_MATCH';
    const SPLIT = 'SPLIT';
    const DISABLED = 'DISABLED';
    const ERROR = 'ERROR';

    private static $valid = array(
        self::STATIC_REASON => true,
        self::DEFAULT_REASON => true,
        self::TARGETING_MATCH => true,
        self::SPLIT => true,
        self::DISABLED => true,
        self::ERROR => true,
    );

    private function __construct()
    {
    }

    public static function isValid($reason)
    {
        return isset(self::$valid[$reason]);
    }
}
