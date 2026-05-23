<?php

namespace DDTrace\FeatureFlags\Internal\Metric;

final class OtlpMetricEncoder
{
    const METRIC_NAME = 'feature_flag.evaluations';
    const METRIC_UNIT = '{evaluation}';
    const METRIC_DESCRIPTION = 'Number of feature flag evaluations';

    private function __construct()
    {
    }

    public static function encode($serviceName, array $points, $startTimeUnixNano = null, $timeUnixNano = null)
    {
        $startTimeUnixNano = $startTimeUnixNano === null ? self::nowUnixNano() : (int) $startTimeUnixNano;
        $timeUnixNano = $timeUnixNano === null ? self::nowUnixNano() : (int) $timeUnixNano;

        $dataPoints = '';
        foreach ($points as $point) {
            $dataPoints .= self::messageField(1, self::numberDataPoint(
                $point['attributes'],
                (int) $point['count'],
                $startTimeUnixNano,
                $timeUnixNano
            ));
        }

        $sum = $dataPoints
            . self::varintField(2, 1)
            . self::varintField(3, 1);

        $metric = self::stringField(1, self::METRIC_NAME)
            . self::stringField(2, self::METRIC_DESCRIPTION)
            . self::stringField(3, self::METRIC_UNIT)
            . self::messageField(7, $sum);

        $scopeMetrics = self::messageField(2, $metric);
        $resourceMetrics = self::messageField(1, self::resource($serviceName))
            . self::messageField(2, $scopeMetrics);

        return self::messageField(1, $resourceMetrics);
    }

    private static function resource($serviceName)
    {
        return self::messageField(1, self::keyValue('service.name', (string) $serviceName));
    }

    private static function numberDataPoint(array $attributes, $count, $startTimeUnixNano, $timeUnixNano)
    {
        $encoded = '';
        foreach ($attributes as $key => $value) {
            $encoded .= self::messageField(7, self::keyValue((string) $key, (string) $value));
        }

        return $encoded
            . self::fixed64Field(2, $startTimeUnixNano)
            . self::fixed64Field(3, $timeUnixNano)
            . self::fixed64Field(6, $count);
    }

    private static function keyValue($key, $value)
    {
        return self::stringField(1, $key)
            . self::messageField(2, self::stringField(1, $value));
    }

    private static function stringField($fieldNumber, $value)
    {
        return self::messageField($fieldNumber, (string) $value);
    }

    private static function messageField($fieldNumber, $payload)
    {
        return self::tag($fieldNumber, 2) . self::varint(strlen($payload)) . $payload;
    }

    private static function varintField($fieldNumber, $value)
    {
        return self::tag($fieldNumber, 0) . self::varint((int) $value);
    }

    private static function fixed64Field($fieldNumber, $value)
    {
        $value = (int) $value;

        return self::tag($fieldNumber, 1) . pack('V2', $value & 0xffffffff, ($value >> 32) & 0xffffffff);
    }

    private static function tag($fieldNumber, $wireType)
    {
        return self::varint(($fieldNumber << 3) | $wireType);
    }

    private static function varint($value)
    {
        $value = (int) $value;
        $encoded = '';
        while ($value > 0x7f) {
            $encoded .= chr(($value & 0x7f) | 0x80);
            $value >>= 7;
        }

        return $encoded . chr($value);
    }

    private static function nowUnixNano()
    {
        return (int) floor(microtime(true) * 1000000000);
    }
}
