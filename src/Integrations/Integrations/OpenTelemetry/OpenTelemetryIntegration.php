<?php

namespace DDTrace\Integrations\OpenTelemetry;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;
use DDTrace\Util\ObjectKVStore;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKeys;
use function DDTrace\active_span;
use function DDTrace\generate_distributed_tracing_headers;
use function DDTrace\hook_function;
use function DDTrace\hook_method;
use function DDTrace\install_hook;
use function DDTrace\trace_id;

class OpenTelemetryIntegration extends Integration
{
    const NAME = 'opentelemetry';

    public function getName()
    {
        return self::NAME;
    }

    // Source: https://magp.ie/2015/09/30/convert-large-integer-to-hexadecimal-without-php-math-extension/
    private static function largeBaseConvert($numString, $fromBase, $toBase)
    {
        $chars = "0123456789abcdefghijklmnopqrstuvwxyz";
        $toString = substr($chars, 0, $toBase);

        $length = strlen($numString);
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $number[$i] = strpos($chars, $numString[$i]);
        }
        do {
            $divide = 0;
            $newLen = 0;
            for ($i = 0; $i < $length; $i++) {
                $divide = $divide * $fromBase + $number[$i];
                if ($divide >= $toBase) {
                    $number[$newLen++] = (int)($divide / $toBase);
                    $divide = $divide % $toBase;
                } elseif ($newLen > 0) {
                    $number[$newLen++] = 0;
                }
            }
            $length = $newLen;
            $result = $toString[$divide] . $result;
        } while ($newLen != 0);

        return $result;
    }

    public function init()
    {
        $integration = $this;

        return Integration::LOADED;
    }
}
