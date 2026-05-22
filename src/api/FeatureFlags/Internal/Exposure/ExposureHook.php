<?php

namespace DDTrace\FeatureFlags\Internal\Exposure;

use DDTrace\FeatureFlags\Internal\EvaluationCompleted;
use DDTrace\FeatureFlags\Internal\EvaluationCompletedHook;

final class ExposureHook implements EvaluationCompletedHook
{
    private static $defaultWriter;
    private static $shutdownRegistered = false;

    private $writer;

    public function __construct(ExposureWriter $writer)
    {
        $this->writer = $writer;
    }

    public static function createDefault()
    {
        if (self::$defaultWriter === null) {
            self::$defaultWriter = ExposureWriter::createDefault();
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function(array(self::$defaultWriter, 'flush'));
            self::$shutdownRegistered = true;
        }

        return new self(self::$defaultWriter);
    }

    public function evaluationCompleted(EvaluationCompleted $evaluation)
    {
        $this->writer->record($evaluation);
    }
}
